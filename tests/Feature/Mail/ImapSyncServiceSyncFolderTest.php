<?php

namespace Tests\Feature\Mail;

use App\Events\NewEmailArrived;
use App\Exceptions\SyncBudgetExceededException;
use App\Models\Email;
use App\Models\MailAccount;
use App\Models\MailFolder;
use App\Models\PendingMirrorAction;
use App\Models\User;
use App\Services\Mail\ImapClientFactory;
use App\Services\Mail\ImapSyncService;
use App\Services\Mail\OAuthTokenRefresher;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\TestCase;
use Webklex\PHPIMAP\Address;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Connection\Protocols\ProtocolInterface;
use Webklex\PHPIMAP\Connection\Protocols\Response;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Message;
use Webklex\PHPIMAP\Query\WhereQuery;
use Webklex\PHPIMAP\Support\FlagCollection;
use Webklex\PHPIMAP\Support\MessageCollection;

// Subclass that exposes the protected per-folder sync entry point for unit
// testing without requiring a live IMAP connection.
class TestableImapSyncServiceForSyncFolder extends ImapSyncService
{
    public function callSyncFolder(MailAccount $account, Folder $folder, MailFolder $folderRecord, string $folderName, int $limit, ?CarbonInterface $deadline = null): void
    {
        $this->syncFolder($account, $folder, $folderRecord, $folderName, $limit, $deadline);
    }
}

class ImapSyncServiceSyncFolderTest extends TestCase
{
    use RefreshDatabase;

    private TestableImapSyncServiceForSyncFolder $service;

    private MailAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $clients = new ImapClientFactory(Mockery::mock(OAuthTokenRefresher::class));
        $this->service = new TestableImapSyncServiceForSyncFolder($clients);

        $user = User::factory()->create();
        $this->account = MailAccount::factory()->create(['user_id' => $user->id]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }

    private function makeFolder(): Folder
    {
        $folder = Mockery::mock(Folder::class);
        $folder->full_name = 'INBOX';
        $folder->path = 'INBOX';

        return $folder;
    }

    private function makeMessage(int $uid, bool $isRead, string $subject = 'Test subject'): Message
    {
        $message = Mockery::mock(Message::class);
        $message->shouldReceive('getUid')->andReturn($uid);
        $message->shouldReceive('getFlags')->andReturn(new FlagCollection($isRead ? ['Seen' => 'Seen'] : []));
        $message->shouldReceive('getMessageId')->andReturn(null);
        $message->shouldReceive('getInReplyTo')->andReturn(null);
        $message->shouldReceive('getReferences')->andReturn(null);
        $message->shouldReceive('getFrom')->andReturn([new Address((object) ['mail' => 'sender@example.com', 'personal' => 'Sender Name'])]);
        $message->shouldReceive('getTo')->andReturn(null);
        $message->shouldReceive('getCc')->andReturn(null);
        $message->shouldReceive('getSubject')->andReturn(new class($subject)
        {
            public function __construct(private string $subject) {}

            public function toString(): string
            {
                return $this->subject;
            }
        });
        $message->shouldReceive('getDate')->andReturn(new class
        {
            public function toDate(): Carbon
            {
                return Carbon::parse('2026-01-01T00:00:00Z');
            }
        });

        return $message;
    }

    /**
     * Wire up the incremental fetch path: the folder's full UID list is read
     * from the connection's getUid() (uid_cache off), then messages are fetched
     * in batches via curate_messages(). whereUid('n:*') is deliberately not used
     * because webklex quotes the range into an invalid `UID SEARCH UID "n:*"`
     * (ZERO-61). $messagesByUid maps uid => Message for the ones that come back.
     *
     * $unseenUids is what `UID SEARCH UNSEEN` reports, which is how the
     * reconciliation reads the server's flags for messages already stored.
     *
     * @param  array<int>  $allUids
     * @param  array<int, Message>  $messagesByUid
     * @param  array<int>  $unseenUids
     */
    private function mockIncrementalFetch(Folder $folder, array $allUids, array $messagesByUid, array $unseenUids = []): void
    {
        $response = Mockery::mock(Response::class);
        $response->shouldReceive('validatedData')->andReturn($allUids);

        $unseenResponse = Mockery::mock(Response::class);
        $unseenResponse->shouldReceive('validatedData')->andReturn($unseenUids);

        $connection = Mockery::mock(ProtocolInterface::class);
        $connection->shouldReceive('getUid')->andReturn($response);
        $connection->shouldReceive('search')->with(['UNSEEN'])->andReturn($unseenResponse);

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('getConnection')->andReturn($connection);
        $folder->shouldReceive('getClient')->andReturn($client);

        $query = Mockery::mock(WhereQuery::class);
        $folder->shouldReceive('query')->andReturn($query);
        $query->shouldReceive('setFetchBody')->with(false)->andReturnSelf();
        $query->shouldReceive('curate_messages')->andReturnUsing(function ($uidCollection) use ($messagesByUid) {
            $messages = [];
            foreach ($uidCollection as $uid) {
                if (isset($messagesByUid[(int) $uid])) {
                    $messages[] = $messagesByUid[(int) $uid];
                }
            }

            return new MessageCollection($messages);
        });
    }

    public function test_incremental_sync_fetches_only_uids_above_last_uid_and_checkpoints_the_highest(): void
    {
        $folderRecord = MailFolder::create([
            'mail_account_id' => $this->account->id,
            'local_name' => 'INBOX',
            'remote_path' => 'INBOX',
            'last_uid' => 100,
            'uid_validity' => 42,
        ]);

        $folder = $this->makeFolder();
        $folder->shouldReceive('examine')->andReturn(['uidvalidity' => 42]);

        // UIDs 50 and 100 are at/below the cursor and must be skipped; only
        // 101 and 103 are fetched, and last_uid checkpoints to the highest.
        $this->mockIncrementalFetch($folder, [50, 100, 101, 103], [
            101 => $this->makeMessage(uid: 101, isRead: false),
            103 => $this->makeMessage(uid: 103, isRead: false),
        ]);

        $this->service->callSyncFolder($this->account, $folder, $folderRecord, 'INBOX', 5000);

        $this->assertSame(103, $folderRecord->fresh()->last_uid);
        $this->assertSame(2, Email::where('mail_account_id', $this->account->id)->count());
    }

    public function test_incremental_sync_reads_the_uid_list_via_getuid_and_filters_the_boundary(): void
    {
        // Regression for ZERO-61: the incremental UID list is read via getUid()
        // (with uid_cache off), not whereUid('n:*'). webklex quotes that range
        // into an invalid `UID SEARCH UID "n:*"` command that Gmail rejects with
        // "BAD Could not parse command". getUid() returns every UID in the
        // folder including the cursor itself, so uid == last_uid must be dropped.
        // The mock wires up getUid()/curate_messages() and has no whereUid
        // expectation, so a regression back to the quoted-range search fails.
        $folderRecord = MailFolder::create([
            'mail_account_id' => $this->account->id,
            'local_name' => 'INBOX',
            'remote_path' => 'INBOX',
            'last_uid' => 100,
            'uid_validity' => 42,
        ]);

        $folder = $this->makeFolder();
        $folder->shouldReceive('examine')->andReturn(['uidvalidity' => 42]);
        $this->mockIncrementalFetch($folder, [100, 101], [
            101 => $this->makeMessage(uid: 101, isRead: false),
        ]);

        $this->service->callSyncFolder($this->account, $folder, $folderRecord, 'INBOX', 5000);

        $this->assertSame(101, $folderRecord->fresh()->last_uid);
        $this->assertSame(1, Email::where('mail_account_id', $this->account->id)->count());
    }

    public function test_incremental_sync_batches_a_large_backlog_and_stops_at_the_deadline(): void
    {
        // Regression for ZERO-53: a folder far behind its cursor must be
        // fetched in checkpointed batches, not one unbounded call, so a slow
        // (throttled) account can make durable progress across runs instead of
        // losing every attempt to the job timeout.
        $folderRecord = MailFolder::create([
            'mail_account_id' => $this->account->id,
            'local_name' => 'INBOX',
            'remote_path' => 'INBOX',
            'last_uid' => 100,
            'uid_validity' => 42,
        ]);

        $folder = $this->makeFolder();
        $folder->shouldReceive('examine')->andReturn(['uidvalidity' => 42]);

        // 90..100 are at/below the cursor (skipped); 101..130 are the backlog.
        $messagesByUid = [];
        foreach (range(101, 130) as $uid) {
            $messagesByUid[$uid] = $this->makeMessage(uid: $uid, isRead: true);
        }
        $this->mockIncrementalFetch($folder, range(90, 130), $messagesByUid);

        $pastDeadline = Carbon::now()->subSecond();

        try {
            $this->service->callSyncFolder($this->account, $folder, $folderRecord, 'INBOX', 5000, $pastDeadline);
            $this->fail('Expected SyncBudgetExceededException to stop the run at the deadline');
        } catch (SyncBudgetExceededException) {
            // Expected — a clean, intentional pause.
        }

        // First batch of FULL_SYNC_CHUNK_SIZE (25) messages (uids 101..125) is
        // processed and checkpointed; the deadline then stops the run before
        // the remaining backlog (126..130) is fetched.
        $this->assertSame(125, $folderRecord->fresh()->last_uid, 'first batch must checkpoint before the budget stop');
        $this->assertSame(25, Email::where('mail_account_id', $this->account->id)->count(), 'only the first batch should be processed');
    }

    public function test_uid_validity_change_resets_last_uid_and_triggers_a_full_resync_instead_of_incremental(): void
    {
        $folderRecord = MailFolder::create([
            'mail_account_id' => $this->account->id,
            'local_name' => 'INBOX',
            'remote_path' => 'INBOX',
            'last_uid' => 100,
            'uid_validity' => 42,
        ]);

        $folder = $this->makeFolder();
        // Server reports a different UIDVALIDITY — the folder was recreated.
        $folder->shouldReceive('examine')->andReturn(['uidvalidity' => 999]);

        $query = Mockery::mock(WhereQuery::class);
        $folder->shouldReceive('messages')->andReturn($query);
        // getByUidGreaterOrEqual() must never be called — a UIDVALIDITY
        // mismatch means last_uid=100 no longer refers to anything meaningful
        // on the server, so incremental fetch would be wrong.
        $query->shouldNotReceive('getByUidGreaterOrEqual');

        $query->shouldReceive('all')->andReturnSelf();
        $query->shouldReceive('setFetchBody')->with(false)->andReturnSelf();
        $query->shouldReceive('fetchOrderAsc')->andReturnSelf();
        $query->shouldReceive('chunked')->once()->andReturnUsing(function ($callback) {
            $callback(new MessageCollection([$this->makeMessage(uid: 5, isRead: false)]));
        });

        $this->service->callSyncFolder($this->account, $folder, $folderRecord, 'INBOX', 5000);

        $folderRecord->refresh();
        $this->assertSame(999, $folderRecord->uid_validity);
        $this->assertSame(5, $folderRecord->last_uid);
    }

    public function test_full_sync_checkpoints_last_uid_after_each_chunk_not_only_at_the_end(): void
    {
        $folderRecord = MailFolder::create([
            'mail_account_id' => $this->account->id,
            'local_name' => 'INBOX',
            'remote_path' => 'INBOX',
            'last_uid' => 0,
            'uid_validity' => 0,
        ]);

        $folder = $this->makeFolder();
        // examine() runs on every sync now (including first-time full syncs) so
        // UIDVALIDITY is recorded from the start; a 0 here means the server did
        // not report one and no reset logic applies.
        $folder->shouldReceive('examine')->andReturn(['uidvalidity' => 0]);

        $query = Mockery::mock(WhereQuery::class);
        $folder->shouldReceive('messages')->andReturn($query);
        $query->shouldReceive('all')->andReturnSelf();
        $query->shouldReceive('setFetchBody')->with(false)->andReturnSelf();
        $query->shouldReceive('fetchOrderAsc')->andReturnSelf();

        $midChunkLastUid = null;

        $query->shouldReceive('chunked')
            ->once()
            ->with(Mockery::type('callable'), Mockery::type('integer'))
            ->andReturnUsing(function ($callback) use ($folderRecord, &$midChunkLastUid) {
                // First batch checkpoints before the second batch runs.
                $callback(new MessageCollection([$this->makeMessage(uid: 10, isRead: false)]));
                $midChunkLastUid = $folderRecord->fresh()->last_uid;
                $callback(new MessageCollection([$this->makeMessage(uid: 20, isRead: false)]));
            });

        $this->service->callSyncFolder($this->account, $folder, $folderRecord, 'INBOX', 5000);

        $this->assertSame(10, $midChunkLastUid, 'last_uid should be checkpointed after the first chunk, before the second chunk runs');
        $this->assertSame(20, $folderRecord->fresh()->last_uid);
    }

    public function test_a_stored_zero_uid_validity_is_adopted_not_treated_as_a_folder_recreation(): void
    {
        // Regression for ZERO-50: a folder fully synced by a previous run is
        // left with uid_validity=0 until this run records it. That stored 0
        // must NOT be read as a changed UIDVALIDITY — doing so wiped last_uid
        // and re-fetched the whole folder on every run, livelocking the
        // backfill.
        $folderRecord = MailFolder::create([
            'mail_account_id' => $this->account->id,
            'local_name' => 'Saxion',
            'remote_path' => 'Saxion',
            'last_uid' => 307,
            'uid_validity' => 0,
        ]);

        $folder = $this->makeFolder();
        $folder->shouldReceive('examine')->andReturn(['uidvalidity' => 77]);
        // Must stay on the incremental path (no full re-fetch). All server UIDs
        // are at/below the cursor, so nothing new is fetched and the cursor is
        // preserved — a reset would have wiped it to 0.
        $folder->shouldNotReceive('messages');
        $this->mockIncrementalFetch($folder, [100, 200, 307], []);

        $this->service->callSyncFolder($this->account, $folder, $folderRecord, 'Saxion', 5000);

        $folderRecord->refresh();
        $this->assertSame(307, $folderRecord->last_uid, 'cursor must be preserved, not reset');
        $this->assertSame(77, $folderRecord->uid_validity, 'server UIDVALIDITY should now be recorded');
    }

    public function test_full_sync_stops_at_the_time_budget_after_checkpointing_the_current_batch(): void
    {
        $folderRecord = MailFolder::create([
            'mail_account_id' => $this->account->id,
            'local_name' => 'INBOX',
            'remote_path' => 'INBOX',
            'last_uid' => 0,
            'uid_validity' => 0,
        ]);

        $folder = $this->makeFolder();
        $folder->shouldReceive('examine')->andReturn(['uidvalidity' => 0]);
        $query = Mockery::mock(WhereQuery::class);
        $folder->shouldReceive('messages')->andReturn($query);
        $query->shouldReceive('all')->andReturnSelf();
        $query->shouldReceive('setFetchBody')->with(false)->andReturnSelf();
        $query->shouldReceive('fetchOrderAsc')->andReturnSelf();
        $query->shouldReceive('chunked')->once()->andReturnUsing(function ($callback) {
            // The first batch checkpoints, then the deadline stops the run —
            // the second batch must never execute.
            $callback(new MessageCollection([$this->makeMessage(uid: 10, isRead: false)]));
            $callback(new MessageCollection([$this->makeMessage(uid: 20, isRead: false)]));
        });

        $pastDeadline = Carbon::now()->subSecond();

        try {
            $this->service->callSyncFolder($this->account, $folder, $folderRecord, 'INBOX', 5000, $pastDeadline);
            $this->fail('Expected SyncBudgetExceededException to stop the run at the deadline');
        } catch (SyncBudgetExceededException) {
            // Expected — a clean, intentional pause.
        }

        $this->assertSame(10, $folderRecord->fresh()->last_uid, 'the first batch must be checkpointed before the budget stop');
        $this->assertSame(1, Email::where('mail_account_id', $this->account->id)->count(), 'no batch after the deadline should be processed');
    }

    public function test_incremental_sync_broadcasts_new_unread_messages_but_full_sync_does_not(): void
    {
        Event::fake([NewEmailArrived::class]);

        // Full sync (last_uid=0): must not broadcast, even for an unread
        // INBOX message — a first sync can have years of unread mail and
        // would otherwise flood the UI with notifications.
        $fullSyncFolder = MailFolder::create([
            'mail_account_id' => $this->account->id,
            'local_name' => 'INBOX',
            'remote_path' => 'INBOX',
            'last_uid' => 0,
            'uid_validity' => 0,
        ]);

        $folder = $this->makeFolder();
        $folder->shouldReceive('examine')->andReturn(['uidvalidity' => 0]);
        $query = Mockery::mock(WhereQuery::class);
        $folder->shouldReceive('messages')->andReturn($query);
        $query->shouldReceive('all')->andReturnSelf();
        $query->shouldReceive('setFetchBody')->with(false)->andReturnSelf();
        $query->shouldReceive('fetchOrderAsc')->andReturnSelf();
        $query->shouldReceive('chunked')->once()->andReturnUsing(function ($callback) {
            $callback(new MessageCollection([$this->makeMessage(uid: 1, isRead: false)]));
        });

        $this->service->callSyncFolder($this->account, $folder, $fullSyncFolder, 'INBOX', 5000);

        Event::assertNotDispatched(NewEmailArrived::class);

        // Incremental sync: a genuinely new unread message should broadcast.
        $incrementalFolder = MailFolder::create([
            'mail_account_id' => $this->account->id,
            'local_name' => 'SENT',
            'remote_path' => 'SENT',
            'last_uid' => 50,
            'uid_validity' => 7,
        ]);

        $folder2 = $this->makeFolder();
        $folder2->shouldReceive('examine')->andReturn(['uidvalidity' => 7]);
        $this->mockIncrementalFetch($folder2, [50, 51], [
            51 => $this->makeMessage(uid: 51, isRead: false),
        ]);

        // Note: NewEmailArrived only fires for folderName === 'INBOX' in the
        // real implementation, so use INBOX here to actually exercise the
        // broadcast path on the incremental branch.
        $this->service->callSyncFolder($this->account, $folder2, $incrementalFolder, 'INBOX', 5000);

        Event::assertDispatched(NewEmailArrived::class);
    }

    // --- reconciliation of already-stored messages (ZERO-90) ----------------

    private function incrementalFolderRecord(int $lastUid = 100): MailFolder
    {
        return MailFolder::create([
            'mail_account_id' => $this->account->id,
            'local_name' => 'INBOX',
            'remote_path' => 'INBOX',
            'last_uid' => $lastUid,
            'uid_validity' => 42,
        ]);
    }

    private function storedEmail(int $uid, bool $isRead, bool $isDeleted = false): Email
    {
        return Email::factory()->create([
            'mail_account_id' => $this->account->id,
            'folder' => 'INBOX',
            'uid' => (string) $uid,
            'is_read' => $isRead,
            'is_deleted' => $isDeleted,
        ]);
    }

    public function test_a_message_read_on_another_device_is_reconciled_to_read(): void
    {
        $folderRecord = $this->incrementalFolderRecord();
        $email = $this->storedEmail(uid: 60, isRead: false);

        $folder = $this->makeFolder();
        $folder->shouldReceive('examine')->andReturn(['uidvalidity' => 42]);
        // Still on the server, and no longer in the UNSEEN set.
        $this->mockIncrementalFetch($folder, [60], [], unseenUids: []);

        $this->service->callSyncFolder($this->account, $folder, $folderRecord, 'INBOX', 5000);

        $this->assertTrue($email->fresh()->is_read);
    }

    public function test_a_message_marked_unread_on_another_device_is_reconciled_to_unread(): void
    {
        $folderRecord = $this->incrementalFolderRecord();
        $email = $this->storedEmail(uid: 60, isRead: true);

        $folder = $this->makeFolder();
        $folder->shouldReceive('examine')->andReturn(['uidvalidity' => 42]);
        $this->mockIncrementalFetch($folder, [60], [], unseenUids: [60]);

        $this->service->callSyncFolder($this->account, $folder, $folderRecord, 'INBOX', 5000);

        $this->assertFalse($email->fresh()->is_read);
    }

    public function test_a_message_expunged_on_the_server_is_marked_deleted(): void
    {
        $folderRecord = $this->incrementalFolderRecord();
        $gone = $this->storedEmail(uid: 60, isRead: true);
        $kept = $this->storedEmail(uid: 61, isRead: true);

        $folder = $this->makeFolder();
        $folder->shouldReceive('examine')->andReturn(['uidvalidity' => 42]);
        // 60 is no longer in the folder; 61 still is.
        $this->mockIncrementalFetch($folder, [61], []);

        $this->service->callSyncFolder($this->account, $folder, $folderRecord, 'INBOX', 5000);

        $this->assertTrue($gone->fresh()->is_deleted);
        $this->assertFalse($kept->fresh()->is_deleted);
    }

    /**
     * A UID fetch that comes back with nothing is indistinguishable from a
     * genuinely emptied folder, and acting on the second would wipe the
     * folder locally.
     */
    public function test_an_empty_server_uid_list_deletes_nothing(): void
    {
        $folderRecord = $this->incrementalFolderRecord();
        $email = $this->storedEmail(uid: 60, isRead: true);

        $folder = $this->makeFolder();
        $folder->shouldReceive('examine')->andReturn(['uidvalidity' => 42]);
        $this->mockIncrementalFetch($folder, [], []);

        $this->service->callSyncFolder($this->account, $folder, $folderRecord, 'INBOX', 5000);

        $this->assertFalse($email->fresh()->is_deleted);
    }

    /**
     * Until a drain has pushed our own "mark read" the server still reports
     * the message unseen, and reconciling against that would undo it.
     */
    public function test_a_message_with_an_action_still_queued_is_left_alone(): void
    {
        $folderRecord = $this->incrementalFolderRecord();
        $email = $this->storedEmail(uid: 60, isRead: true);

        PendingMirrorAction::create([
            'mail_account_id' => $this->account->id,
            'email_id' => $email->id,
            'action' => 'mark_read',
            'remote_folder_path' => 'INBOX',
            'uid' => '60',
        ]);

        $folder = $this->makeFolder();
        $folder->shouldReceive('examine')->andReturn(['uidvalidity' => 42]);
        $this->mockIncrementalFetch($folder, [60], [], unseenUids: [60]);

        $this->service->callSyncFolder($this->account, $folder, $folderRecord, 'INBOX', 5000);

        $this->assertTrue($email->fresh()->is_read);
    }

    public function test_a_queued_delete_does_not_get_reconciled_away_early(): void
    {
        $folderRecord = $this->incrementalFolderRecord();
        $email = $this->storedEmail(uid: 60, isRead: true);

        PendingMirrorAction::create([
            'mail_account_id' => $this->account->id,
            'email_id' => $email->id,
            'action' => 'delete',
            'remote_folder_path' => 'INBOX',
            'uid' => '60',
        ]);

        $folder = $this->makeFolder();
        $folder->shouldReceive('examine')->andReturn(['uidvalidity' => 42]);
        // The drain has not run, so the message is gone from neither side yet.
        $this->mockIncrementalFetch($folder, [61], []);
        $this->storedEmail(uid: 61, isRead: true);

        $this->service->callSyncFolder($this->account, $folder, $folderRecord, 'INBOX', 5000);

        $this->assertFalse($email->fresh()->is_deleted);
    }

    public function test_another_accounts_mail_is_never_reconciled(): void
    {
        $folderRecord = $this->incrementalFolderRecord();
        $otherAccount = MailAccount::factory()->create(['user_id' => User::factory()]);
        $theirs = Email::factory()->create([
            'mail_account_id' => $otherAccount->id,
            'folder' => 'INBOX',
            'uid' => '60',
            'is_read' => false,
            'is_deleted' => false,
        ]);

        $folder = $this->makeFolder();
        $folder->shouldReceive('examine')->andReturn(['uidvalidity' => 42]);
        $this->mockIncrementalFetch($folder, [99], []);

        $this->service->callSyncFolder($this->account, $folder, $folderRecord, 'INBOX', 5000);

        $this->assertFalse($theirs->fresh()->is_deleted);
        $this->assertFalse($theirs->fresh()->is_read);
    }

    public function test_a_failed_unseen_search_leaves_flags_alone_without_failing_the_folder(): void
    {
        $folderRecord = $this->incrementalFolderRecord();
        $email = $this->storedEmail(uid: 60, isRead: false);

        $folder = $this->makeFolder();
        $folder->shouldReceive('examine')->andReturn(['uidvalidity' => 42]);

        $response = Mockery::mock(Response::class);
        $response->shouldReceive('validatedData')->andReturn([60]);

        $connection = Mockery::mock(ProtocolInterface::class);
        $connection->shouldReceive('getUid')->andReturn($response);
        $connection->shouldReceive('search')->andThrow(new \RuntimeException('SEARCH failed'));

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('getConnection')->andReturn($connection);
        $folder->shouldReceive('getClient')->andReturn($client);

        $query = Mockery::mock(WhereQuery::class);
        $folder->shouldReceive('query')->andReturn($query);
        $query->shouldReceive('setFetchBody')->with(false)->andReturnSelf();
        $query->shouldReceive('curate_messages')->andReturn(new MessageCollection([]));

        $this->service->callSyncFolder($this->account, $folder, $folderRecord, 'INBOX', 5000);

        $this->assertFalse($email->fresh()->is_read);
        $this->assertFalse($email->fresh()->is_deleted);
    }

    public function test_a_first_time_full_sync_reconciles_nothing(): void
    {
        // last_uid = 0 takes the full-sync branch, where every message is
        // being fetched anyway and there is nothing yet to reconcile against.
        $folderRecord = $this->incrementalFolderRecord(lastUid: 0);
        $email = $this->storedEmail(uid: 60, isRead: false);

        $folder = $this->makeFolder();
        $folder->shouldReceive('examine')->andReturn(['uidvalidity' => 42]);

        $query = Mockery::mock(WhereQuery::class);
        $folder->shouldReceive('messages')->andReturn($query);
        $query->shouldReceive('all')->andReturnSelf();
        $query->shouldReceive('setFetchBody')->with(false)->andReturnSelf();
        $query->shouldReceive('fetchOrderAsc')->andReturnSelf();
        $query->shouldReceive('chunked')->andReturnNull();

        $this->service->callSyncFolder($this->account, $folder, $folderRecord, 'INBOX', 5000);

        $this->assertFalse($email->fresh()->is_deleted);
        $this->assertFalse($email->fresh()->is_read);
    }
}
