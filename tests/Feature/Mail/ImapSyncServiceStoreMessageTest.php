<?php

namespace Tests\Feature\Mail;

use App\Events\NewEmailArrived;
use App\Models\Email;
use App\Models\MailAccount;
use App\Models\User;
use App\Services\Mail\ImapClientFactory;
use App\Services\Mail\ImapSyncService;
use App\Services\Mail\OAuthTokenRefresher;
use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\TestCase;
use Webklex\PHPIMAP\Address;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Header;
use Webklex\PHPIMAP\Message;
use Webklex\PHPIMAP\Support\FlagCollection;

// Subclass that exposes the protected per-message upsert for unit testing
// without requiring a live IMAP connection.
class TestableImapSyncServiceForStoreMessage extends ImapSyncService
{
    public function callStoreMessage(MailAccount $account, Folder $folder, string $folderName, Message $message, bool $broadcastNew): int
    {
        return $this->storeMessage($account, $folder, $folderName, $message, $broadcastNew);
    }
}

class ImapSyncServiceStoreMessageTest extends TestCase
{
    use RefreshDatabase;

    private TestableImapSyncServiceForStoreMessage $service;

    private MailAccount $account;

    private Folder $folder;

    protected function setUp(): void
    {
        parent::setUp();

        $clients = new ImapClientFactory(Mockery::mock(OAuthTokenRefresher::class));
        $this->service = new TestableImapSyncServiceForStoreMessage($clients);

        $user = User::factory()->create();
        $this->account = MailAccount::factory()->create(['user_id' => $user->id]);

        $this->folder = Mockery::mock(Folder::class);
        $this->folder->full_name = 'INBOX';
        $this->folder->path = 'INBOX';
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function makeMessage(int $uid, bool $isRead, string $subject = 'Test subject', ?string $messageId = null, bool $hasDate = true, array $headers = []): Message
    {
        $message = Mockery::mock(Message::class);
        // A real Header parsed from raw text rather than a stub, so header
        // name normalisation is exercised the way the server sends it.
        $raw = 'Date: Thu, 01 Jan 2026 00:00:00 +0000'."\r\n";

        foreach ($headers as $name => $value) {
            $raw .= "{$name}: {$value}\r\n";
        }

        $message->shouldReceive('getHeader')->andReturn(new Header($raw, Config::make()));
        $message->shouldReceive('getUid')->andReturn($uid);
        $message->shouldReceive('getFlags')->andReturn(new FlagCollection($isRead ? ['Seen' => 'Seen'] : []));
        $message->shouldReceive('getMessageId')->andReturn($messageId === null ? null : new class($messageId)
        {
            public function __construct(private string $messageId) {}

            public function toString(): string
            {
                return $this->messageId;
            }
        });
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
        $message->shouldReceive('getDate')->andReturn($hasDate ? new class
        {
            public function toDate(): Carbon
            {
                return Carbon::parse('2026-01-01T00:00:00Z');
            }
        } : null);

        return $message;
    }

    public function test_inserts_a_new_message(): void
    {
        $message = $this->makeMessage(uid: 501, isRead: false);

        $uid = $this->service->callStoreMessage($this->account, $this->folder, 'INBOX', $message, broadcastNew: false);

        $this->assertSame(501, $uid);
        $this->assertDatabaseHas('emails', [
            'mail_account_id' => $this->account->id,
            'folder' => 'INBOX',
            'uid' => '501',
            'subject' => 'Test subject',
            'from_address' => 'sender@example.com',
            'is_read' => false,
        ]);
    }

    public function test_it_stores_the_list_unsubscribe_headers(): void
    {
        $message = $this->makeMessage(uid: 777, isRead: false, headers: [
            'List-Unsubscribe' => '<https://example.com/unsub>, <mailto:leave@example.com>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);

        $this->service->callStoreMessage($this->account, $this->folder, 'INBOX', $message, broadcastNew: false);

        $this->assertDatabaseHas('emails', [
            'uid' => '777',
            'list_unsubscribe' => '<https://example.com/unsub>, <mailto:leave@example.com>',
            'list_unsubscribe_post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    public function test_a_message_without_the_headers_stores_nulls(): void
    {
        $message = $this->makeMessage(uid: 778, isRead: false);

        $this->service->callStoreMessage($this->account, $this->folder, 'INBOX', $message, broadcastNew: false);

        $this->assertDatabaseHas('emails', [
            'uid' => '778',
            'list_unsubscribe' => null,
            'list_unsubscribe_post' => null,
        ]);
    }

    public function test_does_not_duplicate_an_existing_message(): void
    {
        $first = $this->makeMessage(uid: 502, isRead: false);
        $this->service->callStoreMessage($this->account, $this->folder, 'INBOX', $first, broadcastNew: false);

        $second = $this->makeMessage(uid: 502, isRead: false);
        $this->service->callStoreMessage($this->account, $this->folder, 'INBOX', $second, broadcastNew: false);

        $this->assertSame(1, Email::where('mail_account_id', $this->account->id)->where('uid', '502')->count());
    }

    public function test_reconciles_read_flag_for_an_existing_message(): void
    {
        $unread = $this->makeMessage(uid: 503, isRead: false);
        $this->service->callStoreMessage($this->account, $this->folder, 'INBOX', $unread, broadcastNew: false);

        $nowRead = $this->makeMessage(uid: 503, isRead: true);
        $this->service->callStoreMessage($this->account, $this->folder, 'INBOX', $nowRead, broadcastNew: false);

        $this->assertDatabaseHas('emails', [
            'mail_account_id' => $this->account->id,
            'uid' => '503',
            'is_read' => true,
        ]);
    }

    public function test_broadcast_new_true_does_not_throw_for_a_new_unread_inbox_message(): void
    {
        $message = $this->makeMessage(uid: 504, isRead: false);

        $uid = $this->service->callStoreMessage($this->account, $this->folder, 'INBOX', $message, broadcastNew: true);

        $this->assertSame(504, $uid);
        $this->assertDatabaseHas('emails', ['uid' => '504']);
    }

    public function test_a_new_message_gets_a_generated_ulid(): void
    {
        $message = $this->makeMessage(uid: 601, isRead: false);
        $this->service->callStoreMessage($this->account, $this->folder, 'INBOX', $message, broadcastNew: false);

        $email = Email::where('mail_account_id', $this->account->id)->where('uid', '601')->firstOrFail();

        $this->assertNotNull($email->ulid);
        $this->assertSame(26, strlen($email->ulid));
    }

    public function test_the_same_message_moved_to_another_folder_reuses_its_ulid(): void
    {
        $inInbox = $this->makeMessage(uid: 602, isRead: false, messageId: '<shared@example.com>');
        $this->service->callStoreMessage($this->account, $this->folder, 'INBOX', $inInbox, broadcastNew: false);
        $original = Email::where('mail_account_id', $this->account->id)->where('uid', '602')->firstOrFail();

        // Simulate an out-of-band move: the same message reappears in a
        // different folder with a different UID on the next sync.
        $archiveFolder = Mockery::mock(Folder::class);
        $archiveFolder->full_name = 'Archive';
        $archiveFolder->path = 'Archive';
        $inArchive = $this->makeMessage(uid: 999, isRead: false, messageId: '<shared@example.com>');
        $this->service->callStoreMessage($this->account, $archiveFolder, 'Archive', $inArchive, broadcastNew: false);

        $moved = Email::where('mail_account_id', $this->account->id)->where('uid', '999')->firstOrFail();

        $this->assertNotSame($original->id, $moved->id);
        $this->assertSame($original->ulid, $moved->ulid);
    }

    public function test_a_message_with_no_parseable_date_still_stores_and_broadcasts(): void
    {
        Event::fake([NewEmailArrived::class]);

        $message = $this->makeMessage(uid: 506, isRead: false, hasDate: false);

        $uid = $this->service->callStoreMessage($this->account, $this->folder, 'INBOX', $message, broadcastNew: true);

        $this->assertSame(506, $uid);
        $this->assertDatabaseHas('emails', ['uid' => '506', 'sent_at' => null]);
        Event::assertDispatched(NewEmailArrived::class, fn (NewEmailArrived $event) => $event->sentAt !== '');
    }

    public function test_a_message_whose_date_header_cannot_be_parsed_still_stores(): void
    {
        $message = $this->makeMessage(uid: 507, isRead: false, hasDate: false);
        $message->shouldReceive('getDate')->andReturn(new class
        {
            public function toDate(): Carbon
            {
                throw new InvalidFormatException('Unexpected data found.');
            }
        });

        $uid = $this->service->callStoreMessage($this->account, $this->folder, 'INBOX', $message, broadcastNew: true);

        $this->assertSame(507, $uid);
        $this->assertDatabaseHas('emails', ['uid' => '507', 'sent_at' => null]);
    }

    public function test_broadcast_carries_the_real_email_id_not_a_hardcoded_zero(): void
    {
        Event::fake([NewEmailArrived::class]);

        $message = $this->makeMessage(uid: 505, isRead: false);
        $this->service->callStoreMessage($this->account, $this->folder, 'INBOX', $message, broadcastNew: true);

        $email = Email::where('mail_account_id', $this->account->id)->where('uid', '505')->firstOrFail();

        Event::assertDispatched(NewEmailArrived::class, fn (NewEmailArrived $event) => $event->emailId === $email->id && $event->emailId !== 0);
    }
}
