<?php

namespace Tests\Feature\Inbox;

use App\Models\Email;
use App\Models\MailAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class UnsubscribeTest extends TestCase
{
    use RefreshDatabase;

    /** Literal IP so the guard passes without depending on DNS. */
    private const ENDPOINT = 'https://93.184.216.34/unsub';

    private User $user;

    private MailAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->user = User::factory()->create();
        $this->account = MailAccount::factory()->create(['user_id' => $this->user->id]);
    }

    private function newsletter(array $attributes = []): Email
    {
        return Email::factory()->create([
            'mail_account_id' => $this->account->id,
            'from_address' => 'news@example.com',
            'folder' => 'INBOX',
            'uid' => (string) fake()->unique()->numberBetween(1, 999999),
            'is_deleted' => false,
            'is_archived' => false,
            // Non-null so opening the thread does not try to fetch it over IMAP.
            'body_text' => 'Weekly digest.',
            'list_unsubscribe' => '<'.self::ENDPOINT.'>',
            'list_unsubscribe_post' => 'List-Unsubscribe=One-Click',
            ...$attributes,
        ]);
    }

    private function unsubscribe(Email $email)
    {
        return $this->actingAs($this->user)
            ->from(route('inbox.show', $email))
            ->post(route('inbox.unsubscribe', $email));
    }

    public function test_it_posts_the_rfc_8058_body_to_the_endpoint(): void
    {
        Http::fake([self::ENDPOINT => Http::response('', 200)]);

        $this->unsubscribe($this->newsletter())
            ->assertRedirect()
            ->assertSessionHas('status');

        Http::assertSent(function (Request $request) {
            return $request->url() === self::ENDPOINT
                && $request->method() === 'POST'
                && $request['List-Unsubscribe'] === 'One-Click';
        });
    }

    public function test_it_reports_a_refusal_instead_of_claiming_success(): void
    {
        Http::fake([self::ENDPOINT => Http::response('nope', 403)]);

        $this->unsubscribe($this->newsletter())
            ->assertRedirect()
            ->assertSessionHas('error')
            ->assertSessionMissing('status');
    }

    public function test_it_refuses_a_message_without_the_post_header(): void
    {
        Http::fake();

        $this->unsubscribe($this->newsletter(['list_unsubscribe_post' => null]))
            ->assertRedirect()
            ->assertSessionHas('error');

        Http::assertNothingSent();
    }

    public function test_it_refuses_a_private_endpoint(): void
    {
        Http::fake();

        $email = $this->newsletter(['list_unsubscribe' => '<https://169.254.169.254/latest/meta-data/>']);

        $this->unsubscribe($email)
            ->assertRedirect()
            ->assertSessionHas('error');

        Http::assertNothingSent();
    }

    public function test_another_user_cannot_unsubscribe_your_mail(): void
    {
        Http::fake();

        $email = $this->newsletter();

        $this->actingAs(User::factory()->create())
            ->post(route('inbox.unsubscribe', $email))
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_the_reading_pane_offers_it_only_when_the_header_is_there(): void
    {
        $with = $this->newsletter();
        $without = $this->newsletter(['list_unsubscribe' => null, 'list_unsubscribe_post' => null]);

        $this->actingAs($this->user)->get(route('inbox.show', $with))
            ->assertOk()
            ->assertSee('This is a mailing list.')
            ->assertSee(route('inbox.unsubscribe', $with));

        $this->actingAs($this->user)->get(route('inbox.show', $without))
            ->assertOk()
            ->assertDontSee('This is a mailing list.');
    }

    public function test_a_link_only_sender_gets_a_link_rather_than_a_button(): void
    {
        $email = $this->newsletter([
            'list_unsubscribe' => '<https://example.com/leave>',
            'list_unsubscribe_post' => null,
        ]);

        $this->actingAs($this->user)->get(route('inbox.show', $email))
            ->assertOk()
            ->assertSee('Unsubscribe on their site')
            ->assertSee('https://example.com/leave', false)
            ->assertDontSee(route('inbox.unsubscribe', $email));
    }

    public function test_a_one_click_sender_keeps_its_page_link_as_a_fallback(): void
    {
        $email = $this->newsletter();

        $this->actingAs($this->user)->get(route('inbox.show', $email))
            ->assertOk()
            ->assertSee(route('inbox.unsubscribe', $email))
            ->assertSee('Open their page')
            ->assertSee(self::ENDPOINT, false);
    }

    public function test_an_unverifiable_target_is_reported_as_such_not_as_a_missing_link(): void
    {
        Http::fake();

        // Offers one-click, but the host cannot be vouched for, so the reason
        // shown has to be the guard rather than an absent header.
        $email = $this->newsletter(['list_unsubscribe' => '<https://127.0.0.1/unsub>']);

        $response = $this->unsubscribe($email)->assertRedirect();

        $this->assertStringContainsString('could not be verified', (string) session('error'));
        Http::assertNothingSent();
    }
}
