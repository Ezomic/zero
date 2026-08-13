<?php

namespace Tests\Feature\Inbox;

use App\Models\Draft;
use App\Models\Email;
use App\Models\MailAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SignatureTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private MailAccount $work;

    private MailAccount $personal;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->user = User::factory()->create();
        $this->work = MailAccount::factory()->create([
            'user_id' => $this->user->id,
            'email_address' => 'me@work.example',
            'signature' => "Robbin Thijssen\nThijssen Software",
        ]);
        $this->personal = MailAccount::factory()->create([
            'user_id' => $this->user->id,
            'email_address' => 'me@personal.example',
            'signature' => 'Sent from my own machine',
        ]);
    }

    public function test_the_signature_is_saved_on_the_account(): void
    {
        $this->actingAs($this->user)
            ->put(route('accounts.update', $this->work), [
                'email_address' => 'me@work.example',
                'display_name' => 'Me',
                'signature' => "New sign-off\nSecond line",
                'imap_host' => 'imap.example.com',
                'imap_port' => 993,
                'imap_username' => 'me',
                'smtp_host' => 'smtp.example.com',
                'smtp_port' => 587,
                'smtp_username' => 'me',
                'is_active' => '1',
            ])
            ->assertRedirect();

        $this->assertSame("New sign-off\nSecond line", $this->work->refresh()->signature);
    }

    public function test_the_edit_form_shows_the_current_signature(): void
    {
        $this->actingAs($this->user)
            ->get(route('accounts.edit', $this->work))
            ->assertOk()
            ->assertSee('Thijssen Software');
    }

    public function test_an_oauth_account_can_have_one_too(): void
    {
        $gmail = MailAccount::factory()->create([
            'user_id' => $this->user->id,
            'provider' => MailAccount::PROVIDER_GMAIL,
            'signature' => null,
        ]);

        $this->actingAs($this->user)
            ->put(route('accounts.update', $gmail), [
                'display_name' => 'Gmail',
                'signature' => 'Sent from Gmail',
                'is_active' => '1',
            ])
            ->assertRedirect();

        $this->assertSame('Sent from Gmail', $gmail->refresh()->signature);
    }

    /**
     * Applied in the browser rather than baked into the prefill, so it is
     * visible and editable before sending and so switching account can swap
     * it (ZERO-116).
     */
    public function test_the_composer_carries_every_accounts_signature(): void
    {
        $this->actingAs($this->user)
            ->get(route('compose.create'))
            ->assertOk()
            ->assertSee('Thijssen Software')
            ->assertSee('Sent from my own machine');
    }

    public function test_an_account_without_one_contributes_nothing(): void
    {
        $bare = MailAccount::factory()->create([
            'user_id' => $this->user->id,
            'email_address' => 'bare@example.com',
            'signature' => null,
        ]);

        $response = $this->actingAs($this->user)->get(route('compose.create'))->assertOk();

        // The map is keyed by account id, so a signature-less account simply
        // is not in it.
        $this->assertStringNotContainsString('"'.$bare->id.'":', $response->getContent() ?: '');
    }

    public function test_a_blank_signature_is_treated_as_none(): void
    {
        $this->work->forceFill(['signature' => "   \n  "])->save();

        $response = $this->actingAs($this->user)->get(route('compose.create'))->assertOk();

        $this->assertStringNotContainsString('"'.$this->work->id.'":', $response->getContent() ?: '');
    }

    public function test_reply_and_forward_carry_the_signatures_too(): void
    {
        $email = Email::factory()->create([
            'mail_account_id' => $this->work->id,
            'subject' => 'Original',
            'from_address' => 'them@example.com',
        ]);

        foreach (['compose.reply', 'compose.forward'] as $route) {
            $this->actingAs($this->user)
                ->get(route($route, $email))
                ->assertOk()
                ->assertSee('Thijssen Software');
        }
    }

    public function test_another_users_signature_is_never_offered(): void
    {
        MailAccount::factory()->create([
            'user_id' => User::factory(),
            'signature' => 'Somebody elses sign-off',
        ]);

        $this->actingAs($this->user)
            ->get(route('compose.create'))
            ->assertOk()
            ->assertDontSee('Somebody elses sign-off');
    }

    /**
     * The composer decides whether to append based on the separator already
     * being present, so a resumed draft has to reach the textarea byte for
     * byte. Whether the JS then leaves it alone is a browser behaviour and is
     * verified there, not here.
     */
    public function test_a_resumed_draft_reaches_the_composer_with_its_signature_intact(): void
    {
        $draft = Draft::create([
            'user_id' => $this->user->id,
            'mail_account_id' => $this->work->id,
            'subject' => 'Half written',
            'body' => "Some text\n\n-- \nRobbin Thijssen\nThijssen Software",
        ]);

        $this->actingAs($this->user)
            ->get(route('compose.create', ['draft' => $draft->id]))
            ->assertOk()
            ->assertSee(e("Some text\n\n-- \nRobbin Thijssen\nThijssen Software"), false);
    }
}
