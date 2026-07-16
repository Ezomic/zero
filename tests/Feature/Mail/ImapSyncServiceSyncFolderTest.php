<?php

namespace Tests\Feature\Mail;

use App\Events\NewEmailArrived;
use App\Models\Email;
use App\Models\MailAccount;
use App\Models\MailFolder;
use App\Models\User;
use App\Services\Mail\ImapSyncService;
use App\Services\Mail\OAuthTokenRefresher;
use Carbon\Carbon;
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
    public function callSyncFolder(MailAccount $account, Folder $folder, MailFolder $folderRecord, string $folderName, int $limit): void
    {
        $this->syncFolder($account, $folder, $folderRecord, $folderName, $limit);
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

        $query = Mockery::mock(WhereQuery::class);
        $folder->shouldReceive('messages')->andReturn($query);
        $query->shouldReceive('setFetchBody')->with(false)->andReturnSelf();
        $query->shouldReceive('limit')->with(5000)->andReturnSelf();
        $query->shouldReceive('fetchOrderDesc')->andReturnSelf();
        $query->shouldReceive('getByUidGreaterOrEqual')->with(101)->andReturn(new MessageCollection([
            $this->makeMessage(uid: 101, isRead: false),
            $this->makeMessage(uid: 103, isRead: false),
        ]));

        $this->service->callSyncFolder($this->account, $folder, $folderRecord, 'INBOX', 5000);

        $this->assertSame(103, $folderRecord->fresh()->last_uid);
        $this->assertSame(2, Email::where('mail_account_id', $this->account->id)->count());
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
        $folder->shouldNotReceive('examine'); // last_uid=0 means this is a first-time sync — no incremental branch at all.

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
        $query2 = Mockery::mock(WhereQuery::class);
        $folder2->shouldReceive('messages')->andReturn($query2);
        $query2->shouldReceive('setFetchBody')->with(false)->andReturnSelf();
        $query2->shouldReceive('limit')->with(5000)->andReturnSelf();
        $query2->shouldReceive('fetchOrderDesc')->andReturnSelf();
        $query2->shouldReceive('getByUidGreaterOrEqual')->with(51)->andReturn(new MessageCollection([
            $this->makeMessage(uid: 51, isRead: false),
        ]));

        // Note: NewEmailArrived only fires for folderName === 'INBOX' in the
        // real implementation, so use INBOX here to actually exercise the
        // broadcast path on the incremental branch.
        $this->service->callSyncFolder($this->account, $folder2, $incrementalFolder, 'INBOX', 5000);

        Event::assertDispatched(NewEmailArrived::class);
    }
}
