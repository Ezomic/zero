<?php

namespace Tests\Feature\Mail;

use App\Events\NewEmailArrived;
use App\Models\Email;
use App\Models\MailAccount;
use App\Models\MailFolder;
use App\Models\MailRule;
use App\Models\PendingMirrorAction;
use App\Models\User;
use App\Services\Mail\ImapClientFactory;
use App\Services\Mail\ImapSyncService;
use App\Services\Mail\OAuthTokenRefresher;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;
use Webklex\PHPIMAP\Address;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Header;
use Webklex\PHPIMAP\Message;
use Webklex\PHPIMAP\Support\FlagCollection;

class ImapRulesExposedSyncService extends ImapSyncService
{
    public function callStoreMessage(MailAccount $account, Folder $folder, string $folderName, Message $message, bool $broadcastNew): int
    {
        return $this->storeMessage($account, $folder, $folderName, $message, $broadcastNew);
    }
}

class ImapSyncServiceRulesTest extends TestCase
{
    use RefreshDatabase;

    private ImapRulesExposedSyncService $service;

    private MailAccount $account;

    private Folder $folder;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        MailRule::forgetMemo();

        $this->service = new ImapRulesExposedSyncService(new ImapClientFactory(Mockery::mock(OAuthTokenRefresher::class)));
        $this->user = User::factory()->create();
        $this->account = MailAccount::factory()->create(['user_id' => $this->user->id]);

        $this->folder = Mockery::mock(Folder::class);
        $this->folder->full_name = 'INBOX';
        $this->folder->path = 'INBOX';
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }

    private function message(int $uid, string $from, string $subject = 'Hello', bool $read = false): Message
    {
        $text = fn (string $value): object => new class($value)
        {
            public function __construct(private string $value) {}

            public function toString(): string
            {
                return $this->value;
            }
        };

        $message = Mockery::mock(Message::class);
        $message->shouldReceive('getHeader')->andReturn(new Header("Date: Thu, 01 Jan 2026 00:00:00 +0000\r\n", Config::make()));
        $message->shouldReceive('getUid')->andReturn($uid);
        $message->shouldReceive('getFlags')->andReturn(new FlagCollection($read ? ['Seen' => 'Seen'] : []));
        $message->shouldReceive('getMessageId')->andReturn($text("<{$uid}@x.test>"));
        $message->shouldReceive('getInReplyTo')->andReturn(null);
        $message->shouldReceive('getReferences')->andReturn(null);
        $message->shouldReceive('getFrom')->andReturn([new Address((object) ['mail' => $from, 'personal' => 'Sender'])]);
        $message->shouldReceive('getTo')->andReturn(null);
        $message->shouldReceive('getCc')->andReturn(null);
        $message->shouldReceive('getSubject')->andReturn($text($subject));
        $message->shouldReceive('getDate')->andReturn(new class
        {
            public function toDate(): Carbon
            {
                return Carbon::parse('2026-01-01T00:00:00Z');
            }
        });

        return $message;
    }

    private function store(Message $message, string $folderName = 'INBOX'): void
    {
        $this->service->callStoreMessage($this->account, $this->folder, $folderName, $message, broadcastNew: true);
    }

    /** @param array<string, mixed> $attributes */
    private function rule(string $action, array $attributes = []): MailRule
    {
        return MailRule::create($attributes + [
            'user_id' => $this->user->id, 'mail_account_id' => null, 'position' => 1, 'field' => 'from',
            'operator' => 'contains', 'value' => 'noisy', 'action' => $action,
        ]);
    }

    public function test_every_action_applies_on_the_imap_path(): void
    {
        Event::fake([NewEmailArrived::class]);
        MailFolder::create(['mail_account_id' => $this->account->id, 'local_name' => 'Shops', 'remote_path' => 'Shops']);
        $this->rule('archive', ['position' => 1]);
        $this->rule('star', ['position' => 2]);
        $this->rule('mark_read', ['position' => 3]);
        $this->rule('file', ['position' => 4, 'mail_account_id' => $this->account->id, 'folder' => 'Shops']);

        $this->store($this->message(41, 'a@noisy.test'));

        $email = Email::where('message_id', '<41@x.test>')->sole();
        $this->assertTrue($email->is_archived);
        $this->assertTrue($email->is_starred);
        $this->assertTrue($email->is_read);
        $this->assertSame('Shops', $email->folder);
        $this->assertNull($email->uid);
        $this->assertEqualsCanonicalizing(
            ['star', 'mark_read', 'move:Shops'],
            PendingMirrorAction::where('email_id', $email->id)->pluck('action')->all(),
        );
        $this->assertDatabaseHas('pending_mirror_actions', ['email_id' => $email->id, 'action' => 'move:Shops', 'uid' => '41', 'remote_folder_path' => 'INBOX']);
        Event::assertNotDispatched(NewEmailArrived::class);
    }

    public function test_rules_do_not_run_for_mail_arriving_outside_the_inbox(): void
    {
        $this->rule('archive');

        $this->store($this->message(5, 'a@noisy.test'), 'Archive2');

        $this->assertFalse(Email::where('message_id', '<5@x.test>')->sole()->is_archived);
    }

    public function test_an_odd_subject_encoding_does_not_break_storing(): void
    {
        $this->rule('archive', ['field' => 'subject', 'value' => 'invoice']);

        $this->store($this->message(6, 'a@x.test', "Invoice \xFF\xFE 2026"));

        $this->assertDatabaseCount('emails', 1);
    }
}
