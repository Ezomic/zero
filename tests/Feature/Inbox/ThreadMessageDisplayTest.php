<?php

namespace Tests\Feature\Inbox;

use App\Models\Email;
use App\Models\MailAccount;
use App\Models\User;
use App\Support\AvatarColor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ThreadMessageDisplayTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private MailAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->user = User::factory()->create();
        $this->account = MailAccount::factory()->create([
            'user_id' => $this->user->id,
            'email_address' => 'me@example.com',
        ]);
    }

    private function message(array $attributes): Email
    {
        return Email::factory()->create([
            'mail_account_id' => $this->account->id,
            'thread_id' => 'thread-1',
            'folder' => 'INBOX',
            ...$attributes,
        ]);
    }

    public function test_only_the_newest_message_starts_expanded(): void
    {
        $this->message(['from_address' => 'them@example.com', 'sent_at' => now()->subDays(2), 'body_text' => 'The oldest one']);
        $this->message(['from_address' => 'them@example.com', 'sent_at' => now()->subDay(), 'body_text' => 'The middle one']);
        $newest = $this->message(['from_address' => 'them@example.com', 'sent_at' => now(), 'body_text' => 'The newest one']);

        $html = $this->actingAs($this->user)->get(route('inbox.show', $newest))->assertOk()->getContent();

        // Two collapsed, one open — read off the Alpine state each card carries.
        $this->assertSame(2, substr_count($html, 'x-data="{ open: false }"'));
        $this->assertSame(1, substr_count($html, 'x-data="{ open: true }"'));
    }

    public function test_a_collapsed_message_still_carries_its_snippet(): void
    {
        $this->message(['from_address' => 'them@example.com', 'sent_at' => now()->subDay(), 'body_text' => 'Kindly review the attached quote']);
        $newest = $this->message(['from_address' => 'them@example.com', 'sent_at' => now()]);

        $this->actingAs($this->user)
            ->get(route('inbox.show', $newest))
            ->assertOk()
            ->assertSee('Kindly review the attached quote');
    }

    public function test_the_thread_header_counts_the_messages(): void
    {
        $this->message(['sent_at' => now()->subDay()]);
        $newest = $this->message(['sent_at' => now()]);

        $this->actingAs($this->user)
            ->get(route('inbox.show', $newest))
            ->assertOk()
            ->assertSee('2 messages in this conversation');
    }

    public function test_a_single_message_thread_shows_no_count(): void
    {
        $only = $this->message(['sent_at' => now()]);

        $this->actingAs($this->user)
            ->get(route('inbox.show', $only))
            ->assertOk()
            ->assertDontSee('messages in this conversation');
    }

    public function test_your_own_reply_is_marked_out_from_the_other_party(): void
    {
        $this->message(['from_address' => 'them@example.com', 'sent_at' => now()->subDay()]);
        $mine = $this->message(['from_address' => 'me@example.com', 'sent_at' => now()]);

        $html = $this->actingAs($this->user)->get(route('inbox.show', $mine))->assertOk()->getContent();

        $this->assertStringContainsString('class="msg outgoing"', $html);
        $this->assertStringContainsString('<span class="msg-tag">You</span>', $html);
        $this->assertSame(1, substr_count($html, 'msg-tag'));
    }

    public function test_a_message_in_the_sent_folder_counts_as_yours_whatever_the_address(): void
    {
        $sent = $this->message(['folder' => 'SENT', 'from_address' => 'alias@example.com', 'sent_at' => now()]);

        $this->assertTrue($sent->isFromOwner());
    }

    public function test_avatar_colour_follows_the_sender_not_the_account(): void
    {
        $them = $this->message(['from_address' => 'them@example.com', 'sent_at' => now()->subDay()]);
        $mine = $this->message(['from_address' => 'me@example.com', 'sent_at' => now()]);

        $html = $this->actingAs($this->user)->get(route('inbox.show', $mine))->assertOk()->getContent();

        $theirColour = AvatarColor::forAddress('them@example.com');
        $myColour = AvatarColor::forAddress('me@example.com');

        $this->assertNotSame($theirColour, $myColour);
        $this->assertStringContainsString("background:{$theirColour}", $html);
        $this->assertStringContainsString("background:{$myColour}", $html);
        $this->assertNotNull($them->id);
    }

    public function test_the_same_address_always_gets_the_same_colour(): void
    {
        $this->assertSame(
            AvatarColor::forAddress('someone@example.com'),
            AvatarColor::forAddress('SOMEONE@Example.com '),
        );
    }
}
