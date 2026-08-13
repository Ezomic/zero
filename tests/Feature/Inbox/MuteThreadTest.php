<?php

namespace Tests\Feature\Inbox;

use App\Events\NewEmailArrived;
use App\Models\Email;
use App\Models\MailAccount;
use App\Models\MailFolder;
use App\Models\MutedThread;
use App\Models\User;
use App\Services\Mail\GraphMailSyncService;
use App\Services\Mail\OAuthTokenRefresher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class MuteThreadTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private MailAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        MutedThread::forgetMemo();

        $this->user = User::factory()->create();
        $this->account = MailAccount::factory()->create([
            'user_id' => $this->user->id,
            'provider' => MailAccount::PROVIDER_OUTLOOK,
            'oauth_access_token' => 'token',
            'oauth_expires_at' => now()->addHour(),
        ]);

        MailFolder::create([
            'mail_account_id' => $this->account->id,
            'local_name' => 'INBOX',
            'remote_path' => 'inbox-folder-id',
            'delta_link' => 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox-folder-id/messages/delta?$deltatoken=abc',
        ]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }

    private function message(array $attributes = []): Email
    {
        return Email::factory()->create([
            'mail_account_id' => $this->account->id,
            'thread_id' => 'noisy-thread',
            'folder' => 'INBOX',
            'is_archived' => false,
            'is_deleted' => false,
            ...$attributes,
        ]);
    }

    /** Delivers one new message on the noisy thread through a real sync. */
    private function syncReply(string $uid = 'reply-1'): void
    {
        Http::fake([
            '*/messages/delta*' => Http::response([
                'value' => [[
                    'id' => $uid,
                    'subject' => 'Another reply nobody needs',
                    'from' => ['emailAddress' => ['address' => 'chatty@example.com']],
                    'receivedDateTime' => '2026-08-07T10:00:00Z',
                    'isRead' => false,
                    'conversationId' => 'noisy-thread',
                    'internetMessageId' => "<{$uid}@example.com>",
                ]],
                '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox-folder-id/messages/delta?$deltatoken=next',
            ], 200),
            '*/me/mailFolders/inbox' => Http::response(['id' => 'inbox-folder-id', 'displayName' => 'Inbox'], 200),
            '*/me/mailFolders/*' => Http::response([], 404),
            '*/me/mailFolders*' => Http::response(['value' => []], 200),
            '*' => Http::response([], 200),
        ]);

        $refresher = Mockery::mock(OAuthTokenRefresher::class);
        $refresher->shouldReceive('freshAccessToken')->andReturn('token');
        (new GraphMailSyncService($refresher))->sync($this->account);
    }

    public function test_muting_records_the_thread_and_archives_what_is_here(): void
    {
        $first = $this->message();
        $second = $this->message();

        $this->actingAs($this->user)
            ->post(route('inbox.toggleMute', $first))
            ->assertRedirect();

        $this->assertDatabaseHas('muted_threads', [
            'mail_account_id' => $this->account->id,
            'thread_id' => 'noisy-thread',
        ]);
        $this->assertTrue($first->refresh()->is_archived);
        $this->assertTrue($second->refresh()->is_archived);
    }

    public function test_a_reply_to_a_muted_thread_never_reaches_the_inbox(): void
    {
        $this->actingAs($this->user)->post(route('inbox.toggleMute', $this->message()));
        MutedThread::forgetMemo();

        $this->syncReply();

        $reply = Email::where('uid', 'reply-1')->sole();
        $this->assertTrue($reply->is_archived, 'a muted reply must land archived');
    }

    /**
     * The half my own review nearly missed: a thread that still pops a toast
     * is not muted in any sense the user would recognise.
     */
    public function test_a_reply_to_a_muted_thread_does_not_broadcast(): void
    {
        Event::fake([NewEmailArrived::class]);

        $this->actingAs($this->user)->post(route('inbox.toggleMute', $this->message()));
        MutedThread::forgetMemo();

        $this->syncReply();

        Event::assertNotDispatched(NewEmailArrived::class);
    }

    public function test_a_reply_to_an_unmuted_thread_still_broadcasts(): void
    {
        Event::fake([NewEmailArrived::class]);

        $this->message();
        $this->syncReply();

        Event::assertDispatched(NewEmailArrived::class);
    }

    /**
     * Muting files mail, it does not hide it. Note that search is scoped to
     * the view you are in, which predates this ticket, so a muted message is
     * found from the archived view rather than the inbox.
     */
    public function test_a_muted_reply_is_still_kept_and_findable(): void
    {
        $this->actingAs($this->user)->post(route('inbox.toggleMute', $this->message()));
        MutedThread::forgetMemo();

        $this->syncReply();

        $this->assertDatabaseHas('emails', ['uid' => 'reply-1', 'is_deleted' => false]);

        $this->actingAs($this->user)
            ->get(route('inbox.index', ['archived' => 1]))
            ->assertOk()
            ->assertSee('Another reply nobody needs');

        $this->actingAs($this->user)
            ->get(route('inbox.index', ['archived' => 1, 'q' => 'nobody']))
            ->assertOk()
            ->assertSee('Another reply nobody needs');
    }

    public function test_a_muted_reply_stays_out_of_the_inbox_view(): void
    {
        $this->actingAs($this->user)->post(route('inbox.toggleMute', $this->message()));
        MutedThread::forgetMemo();

        $this->syncReply();

        $this->actingAs($this->user)
            ->get(route('inbox.index'))
            ->assertOk()
            ->assertDontSee('Another reply nobody needs');
    }

    public function test_unmuting_lets_replies_through_again(): void
    {
        $email = $this->message();

        $this->actingAs($this->user)->post(route('inbox.toggleMute', $email));
        $this->actingAs($this->user)->post(route('inbox.toggleMute', $email));

        $this->assertDatabaseMissing('muted_threads', ['thread_id' => 'noisy-thread']);

        MutedThread::forgetMemo();
        $this->syncReply();

        $this->assertFalse(Email::where('uid', 'reply-1')->sole()->is_archived);
    }

    public function test_muting_is_scoped_to_the_account(): void
    {
        $other = MailAccount::factory()->create(['user_id' => $this->user->id]);
        $theirs = Email::factory()->create([
            'mail_account_id' => $other->id,
            'thread_id' => 'noisy-thread',
            'folder' => 'INBOX',
            'is_archived' => false,
        ]);

        $this->actingAs($this->user)->post(route('inbox.toggleMute', $this->message()));

        // Same thread_id, different account: untouched.
        $this->assertFalse($theirs->refresh()->is_archived);
        $this->assertFalse(MutedThread::isMuted((int) $other->id, 'noisy-thread'));
    }

    public function test_another_user_cannot_mute_your_thread(): void
    {
        $email = $this->message();

        $this->actingAs(User::factory()->create())
            ->post(route('inbox.toggleMute', $email))
            ->assertForbidden();

        $this->assertSame(0, MutedThread::count());
    }

    public function test_the_reading_pane_offers_the_toggle(): void
    {
        $email = $this->message();

        $this->actingAs($this->user)
            ->get(route('inbox.panel', $email))
            ->assertOk()
            ->assertSee(route('inbox.toggleMute', $email), false)
            ->assertSee('later replies skip the inbox');
    }

    public function test_the_reading_pane_shows_a_muted_thread_as_muted(): void
    {
        $email = $this->message();
        $this->actingAs($this->user)->post(route('inbox.toggleMute', $email));

        $this->actingAs($this->user)
            ->get(route('inbox.panel', $email))
            ->assertOk()
            ->assertSee('replies will reach the inbox again');
    }
}
