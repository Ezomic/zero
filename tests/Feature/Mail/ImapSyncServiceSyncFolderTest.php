<?php

namespace Tests\Feature\Mail;

use App\Events\NewEmailArrived;
use App\Exceptions\SyncBudgetExceededException;
use App\Models\Email;
use App\Models\MailAccount;
use App\Models\MailFolder;
use App\Models\User;
use App\Services\Mail\ImapSyncService;
use App\Services\Mail\OAuthTokenRefresher;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\TestCase;
use Webklex\PHPIMAP\Address;
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

        $refresher = Mockery::mock(OAuthTokenRefresher::class);
        $this->service = new TestableImapSyncServiceForSyncFolder($refresher);

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
     * Wire up the incremental fetch path: the new-UID list is read from a
     * folder-scoped `whereUid('n:*')->search()`, then messages are fetched in
     * batches via `whereUidIn($batch)->get()`. The raw connection's getUid()
     * must never be touched (ZERO-55), so getClient() is disallowed.
     * $messagesByUid maps uid => Message for the ones that should come back.
     *
     * @param  array<int>  $allUids
     * @param  array<int, Message>  $messagesByUid
     */
    private function mockIncrementalFetch(Folder $folder, array $allUids, array $messagesByUid): void
    {
        $folder->shouldNotReceive('getClient');

        $query = Mockery::mock(WhereQuery::class);
        $folder->shouldReceive('query')->andReturn($query);

        // whereUid('n:*')->search() returns the folder's UID list.
        $query->shouldReceive('whereUid')->andReturnSelf();
        $query->shouldReceive('search')->andReturn(collect($allUids));

        // setFetchBody(false)->whereUidIn($batch)->get() fetches a batch.
        $query->shouldReceive('setFetchBody')->with(false)->andReturnSelf();
        $currentBatch = [];
        $query->shouldReceive('whereUidIn')->andReturnUsing(function ($uids) use (&$currentBatch, $query) {
            $currentBatch = $uids;

            return $query;
        });
        $query->shouldReceive('get')->andReturnUsing(function () use (&$currentBatch, $messagesByUid) {
            $messages = [];
            foreach ($currentBatch as $uid) {
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

    public function test_incremental_sync_reads_uids_from_a_folder_scoped_search_not_the_raw_connection(): void
    {
        // Regression for ZERO-55: the incremental UID list must come from a
        // folder-scoped `whereUid('n:*')->search()`, not the shared
        // connection's getUid(). getUid() reports whichever mailbox the
        // connection currently has selected, which desyncs from $folder in the
        // round-robin loop and returned phantom UIDs — fetching those crashed
        // the whole sync with "Command failed to process: Empty response".
        $folderRecord = MailFolder::create([
            'mail_account_id' => $this->account->id,
            'local_name' => 'INBOX',
            'remote_path' => 'INBOX',
            'last_uid' => 100,
            'uid_validity' => 42,
        ]);

        $folder = $this->makeFolder();
        $folder->shouldReceive('examine')->andReturn(['uidvalidity' => 42]);
        // The raw connection must never be consulted for the UID list.
        $folder->shouldNotReceive('getClient');

        $query = Mockery::mock(WhereQuery::class);
        $folder->shouldReceive('query')->andReturn($query);
        // The search must be scoped to uid > last_uid via the 'n:*' range.
        $query->shouldReceive('whereUid')->with('101:*')->andReturnSelf();
        // The 'n:*' range returns the boundary UID (100) too; it must be filtered out.
        $query->shouldReceive('search')->andReturn(collect([100, 101]));
        $query->shouldReceive('setFetchBody')->with(false)->andReturnSelf();
        $query->shouldReceive('whereUidIn')->with([101])->andReturnSelf();
        $query->shouldReceive('get')->andReturn(new MessageCollection([$this->makeMessage(uid: 101, isRead: false)]));

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
}
