<?php

namespace Tests\Feature\Outbound;

use App\Jobs\SendOutgoingMessageJob;
use App\Models\Contact;
use App\Models\MailAccount;
use App\Models\OutboundToken;
use App\Models\OutgoingMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Concerns\FakesMailTransports;
use Tests\TestCase;

class SendApiTest extends TestCase
{
    use FakesMailTransports;
    use RefreshDatabase;

    private User $owner;

    private MailAccount $account;

    private OutboundToken $token;

    private string $plain;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('outgoing');
        RateLimiter::clear('outbound-ip:127.0.0.1');
        $this->owner = User::factory()->create();
        $this->account = MailAccount::factory()->create(['user_id' => $this->owner->id, 'email_address' => 'noreply@example.com']);
        [$this->token, $this->plain] = OutboundToken::issue('filebox', $this->account);
    }

    /** @param array<string, mixed> $body */
    private function send(array $body = [], ?string $plain = null): TestResponse
    {
        return $this->withToken($plain ?? $this->plain)->postJson('/api/outbound', [
            'to' => 'user@example.com',
            'subject' => 'Reset your password',
            'text' => "Open the link:\nhttps://filebox.example/reset/abc",
            ...$body,
        ]);
    }

    public function test_a_valid_request_is_accepted_and_queued_as_an_immediate_message(): void
    {
        Queue::fake();

        $this->send()->assertStatus(202);

        $message = OutgoingMessage::sole();
        $this->assertSame($this->account->id, $message->mail_account_id);
        $this->assertSame($this->owner->id, $message->user_id);
        $this->assertSame($this->token->id, $message->outbound_token_id);
        $this->assertTrue($message->via_api);
        $this->assertSame(['user@example.com'], $message->to_addresses);
        $this->assertSame([], $message->cc_addresses);
        $this->assertSame([], $message->attachments);
        $this->assertSame('Reset your password', $message->subject);
        $this->assertSame(OutgoingMessage::HELD, $message->status);
        $this->assertFalse($message->send_at->isFuture());
        Queue::assertPushed(SendOutgoingMessageJob::class, fn ($job): bool => $job->messageId === $message->id && $job->delay === null);
    }

    public function test_it_is_delivered_at_once_through_the_account_transport(): void
    {
        $this->fakeSmtp();
        config(['queue.default' => 'sync']);

        $this->send()->assertStatus(202);

        $this->assertCount(1, $this->smtpSent);
        $this->assertStringContainsString('To: user@example.com', $this->smtpSent[0]);
        $this->assertStringContainsString('noreply@example.com', $this->smtpSent[0]);
        $this->assertSame(OutgoingMessage::SENT, OutgoingMessage::sole()->status);
    }

    public function test_a_gmail_account_goes_out_through_the_gmail_api(): void
    {
        config(['queue.default' => 'sync']);
        Http::fake(['https://gmail.googleapis.com/*' => Http::response(['id' => '1'], 200)]);
        $gmail = MailAccount::factory()->gmail()->create([
            'user_id' => $this->owner->id,
            'oauth_access_token' => 'tok',
            'oauth_expires_at' => now()->addHour(),
        ]);
        [, $plain] = OutboundToken::issue('gmail-client', $gmail);

        $this->send(plain: $plain)->assertStatus(202);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'gmail/v1/users/me/messages/send'));
    }

    public function test_missing_wrong_malformed_and_revoked_tokens_get_the_same_401(): void
    {
        $this->token->revoke();
        [, $live] = OutboundToken::issue('live', $this->account);
        $bodies = [];

        $responses = [
            $this->postJson('/api/outbound', ['to' => 'a@example.com', 'subject' => 's', 'text' => 't']),
            $this->send(plain: 'zob_'.str_repeat('0', 64)),
            $this->send(plain: 'not a token'),
            $this->send(plain: $this->plain),
            $this->withHeaders(['Authorization' => 'Basic '.base64_encode('a:b')])->postJson('/api/outbound', []),
            $this->withHeaders(['Authorization' => 'Bearer'])->postJson('/api/outbound', []),
        ];

        foreach ($responses as $response) {
            $response->assertStatus(401);
            $bodies[] = $response->getContent();
        }

        $this->assertCount(1, array_unique($bodies));
        $this->assertSame(0, OutgoingMessage::count());

        Queue::fake();
        $this->send(plain: $live)->assertStatus(202);
    }

    public function test_a_token_whose_account_is_inactive_or_deleted_is_refused(): void
    {
        Queue::fake();
        $this->account->forceFill(['is_active' => false])->save();
        $this->send()->assertStatus(401);

        $this->account->forceFill(['is_active' => true])->save();
        $this->send()->assertStatus(202);

        $this->account->delete();
        $this->send()->assertStatus(401);
        $this->assertSame(1, OutgoingMessage::count());
    }

    public function test_a_session_is_not_a_credential_and_csrf_is_not_required(): void
    {
        Queue::fake();

        $this->actingAs($this->owner)->postJson('/api/outbound', ['to' => 'a@example.com', 'subject' => 's', 'text' => 't'])
            ->assertStatus(401);

        $this->withToken($this->plain)->postJson('/api/outbound', ['to' => 'a@example.com', 'subject' => 's', 'text' => 't'])
            ->assertStatus(202);
    }

    public function test_the_token_cannot_choose_another_account_or_sender(): void
    {
        $other = MailAccount::factory()->create(['user_id' => $this->owner->id]);
        Queue::fake();

        $this->send(['from' => 'ceo@example.com', 'account' => $other->id, 'mail_account_id' => $other->id, 'user_id' => 999, 'cc' => 'x@example.com', 'bcc' => 'y@example.com'])
            ->assertStatus(202);

        $message = OutgoingMessage::sole();
        $this->assertSame($this->account->id, $message->mail_account_id);
        $this->assertSame($this->owner->id, $message->user_id);
        $this->assertSame([], $message->cc_addresses);
    }

    public function test_validation_failures_are_422_and_queue_nothing(): void
    {
        $cases = [
            'missing to' => ['to' => null],
            'empty to' => ['to' => ''],
            'whitespace to' => ['to' => '   '],
            'punctuation to' => ['to' => '!!!'],
            'no domain' => ['to' => 'user@'],
            'display name' => ['to' => 'Name <user@example.com>'],
            'fullwidth at in display name form' => ['to' => 'Name <a＠b.c>'],
            'fullwidth at bare' => ['to' => 'a＠example.com'],
            'ip literal' => ['to' => 'a@[127.0.0.1]'],
            'no tld' => ['to' => 'user@localhost'],
            'percent hack' => ['to' => 'a%b@example.com'],
            'comma list' => ['to' => 'a@example.com,b@example.com'],
            'semicolon list' => ['to' => 'a@example.com;b@example.com'],
            'space list' => ['to' => 'a@example.com b@example.com'],
            'newline in to' => ['to' => "a@example.com\nBcc: evil@example.com"],
            'crlf in to' => ['to' => "a@example.com\r\nBcc: evil@example.com"],
            'array to' => ['to' => ['a@example.com']],
            'array of two to' => ['to' => ['a@example.com', 'b@example.com']],
            'object to' => ['to' => ['email' => 'a@example.com']],
            'int to' => ['to' => 5],
            'long to' => ['to' => str_repeat('a', 250).'@example.com'],
            'missing subject' => ['subject' => null],
            'empty subject' => ['subject' => ''],
            'whitespace subject' => ['subject' => " \t "],
            'array subject' => ['subject' => ['a']],
            'long subject' => ['subject' => str_repeat('s', 201)],
            'crlf subject' => ['subject' => "Hi\r\nBcc: evil@example.com"],
            'lf subject' => ['subject' => "Hi\nthere"],
            'cr subject' => ['subject' => "Hi\rthere"],
            'missing text' => ['text' => null],
            'empty text' => ['text' => ''],
            'whitespace text' => ['text' => " \n\t "],
            'array text' => ['text' => ['a']],
            'long text' => ['text' => str_repeat('t', 5001)],
        ];

        foreach ($cases as $label => $override) {
            $body = array_filter(['to' => 'user@example.com', 'subject' => 's', 'text' => 't', ...$override], fn ($v): bool => $v !== null);

            $this->withToken($this->plain)->postJson('/api/outbound', $body)
                ->assertStatus(422, "case: {$label}");
            RateLimiter::clear('outbound-ip:127.0.0.1');
            RateLimiter::clear('outbound-token:'.$this->token->id);
        }

        $this->assertSame(0, OutgoingMessage::count());
    }

    public function test_the_length_limits_are_inclusive_and_count_characters(): void
    {
        Queue::fake();

        $this->send(['subject' => str_repeat('é', 200), 'text' => str_repeat('😀', 5000)])->assertStatus(202);
        $this->send(['subject' => str_repeat('é', 201)])->assertStatus(422);
        $this->send(['text' => str_repeat('😀', 5001)])->assertStatus(422);
    }

    public function test_unusual_but_valid_values_are_stored_trimmed_but_otherwise_as_given(): void
    {
        Queue::fake();

        $this->send(['to' => '  User+Tag@Example.COM  ', 'subject' => 'Ünïcödé 🔐 !!!', 'text' => '!!!'])->assertStatus(202);

        $message = OutgoingMessage::sole();
        $this->assertSame(['User+Tag@Example.COM'], $message->to_addresses);
        $this->assertSame('Ünïcödé 🔐 !!!', $message->subject);
        $this->assertSame('!!!', $message->body);
    }

    public function test_the_body_is_sent_as_escaped_text(): void
    {
        $this->fakeSmtp();
        config(['queue.default' => 'sync']);

        $this->send(['text' => '<script>alert(1)</script>'])->assertStatus(202);

        $this->assertStringContainsString('&lt;script&gt;', $this->smtpSent[0]);
        $this->assertStringNotContainsString('<script>', $this->smtpSent[0]);
    }

    public function test_a_repeated_request_sends_twice(): void
    {
        Queue::fake();

        $this->send()->assertStatus(202);
        $this->send()->assertStatus(202);

        $this->assertSame(2, OutgoingMessage::count());
    }

    public function test_each_token_is_limited_to_twenty_sends_an_hour(): void
    {
        Queue::fake();
        [, $other] = OutboundToken::issue('other', $this->account);

        for ($i = 0; $i < 20; $i++) {
            $this->send()->assertStatus(202);
            RateLimiter::clear('outbound-ip:127.0.0.1');
        }

        $this->send()->assertStatus(429);
        RateLimiter::clear('outbound-ip:127.0.0.1');
        $this->send(plain: $other)->assertStatus(202);

        $this->travel(61)->minutes();
        RateLimiter::clear('outbound-ip:127.0.0.1');
        $this->send()->assertStatus(202);
    }

    public function test_failed_authentication_counts_against_the_ip_throttle(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->send(plain: 'zob_wrong')->assertStatus(401);
        }

        $this->send(plain: 'zob_wrong')->assertStatus(429);
        $this->send()->assertStatus(429);
        $this->assertSame(0, OutgoingMessage::count());
    }

    public function test_every_accepted_send_is_logged_without_the_body_or_the_token(): void
    {
        Queue::fake();
        Log::spy();

        $this->send(['text' => 'secret-body-text'])->assertStatus(202);

        Log::shouldHaveReceived('info')->once()->withArgs(function (string $message, array $context): bool {
            $flat = (string) json_encode($context);

            return $message === 'outbound.accepted'
                && $context['token'] === 'filebox'
                && $context['to'] === 'user@example.com'
                && ! str_contains($flat, 'secret-body-text')
                && ! str_contains($flat, $this->plain);
        });
    }

    public function test_the_token_is_stored_only_as_a_hash(): void
    {
        $row = (array) DB::table('outbound_tokens')->first();

        $this->assertSame(hash('sha256', $this->plain), $row['token_hash']);
        $this->assertStringNotContainsString($this->plain, (string) json_encode($row));
        $this->assertSame(68, strlen($this->plain));
    }

    public function test_api_messages_stay_out_of_the_status_strip_and_cannot_be_undone(): void
    {
        Queue::fake();
        $this->send()->assertStatus(202);
        $message = OutgoingMessage::sole();

        $this->actingAs($this->owner)->getJson(route('outgoing.index'))->assertOk()->assertJson(['messages' => []]);
        $this->actingAs($this->owner)->postJson(route('outgoing.undo', $message->id))->assertNotFound();
        $this->actingAs($this->owner)->deleteJson(route('outgoing.dismiss', $message->id))->assertNotFound();
        $this->assertSame(OutgoingMessage::HELD, $message->fresh()->status);
    }

    public function test_the_sweep_re_queues_a_lost_api_message(): void
    {
        Queue::fake();
        $this->send()->assertStatus(202);

        $this->travel(3)->minutes();
        $this->artisan('outgoing:sweep')->assertSuccessful();

        Queue::assertPushed(SendOutgoingMessageJob::class, 2);
    }

    public function test_delivery_adds_no_contacts_and_a_failure_is_logged(): void
    {
        $this->fakeSmtp();
        config(['queue.default' => 'sync']);
        $this->send()->assertStatus(202);
        $this->assertSame(0, Contact::count());

        $this->fakeSmtp(failWith: 'smtp down');
        Log::spy();
        $this->send(['to' => 'second@example.com'])->assertStatus(202);

        $this->assertSame(OutgoingMessage::FAILED, OutgoingMessage::latest('id')->first()?->status);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $message === 'outbound.failed' && $context['token'] === 'filebox');
    }

    public function test_a_message_accepted_before_the_token_was_revoked_is_not_sent(): void
    {
        $this->fakeSmtp();
        Queue::fake();
        $this->send()->assertStatus(202);

        $this->token->revoke();
        app()->call([new SendOutgoingMessageJob(OutgoingMessage::sole()->id), 'handle']);

        $this->assertSame([], $this->smtpSent);
        $this->assertSame(OutgoingMessage::FAILED, OutgoingMessage::sole()->status);
    }

    public function test_a_message_accepted_before_the_account_was_deactivated_is_not_sent(): void
    {
        $this->fakeSmtp();
        Queue::fake();
        $this->send()->assertStatus(202);

        $this->account->forceFill(['is_active' => false])->save();
        app()->call([new SendOutgoingMessageJob(OutgoingMessage::sole()->id), 'handle']);

        $this->assertSame([], $this->smtpSent);
    }

    public function test_a_failed_api_message_keeps_no_copy_of_its_text(): void
    {
        $this->fakeSmtp(failWith: 'smtp down');
        config(['queue.default' => 'sync']);

        $this->send()->assertStatus(202);

        $this->assertSame('', OutgoingMessage::sole()->body);
    }

    public function test_a_message_stays_out_of_the_users_paths_after_its_account_is_deleted(): void
    {
        Queue::fake();
        $this->send()->assertStatus(202);

        $this->account->delete();

        $message = OutgoingMessage::sole();
        $this->assertNull($message->outbound_token_id);
        $this->actingAs($this->owner)->getJson(route('outgoing.index'))->assertJson(['messages' => []]);
    }

    public function test_a_queue_failure_leaves_no_row_to_be_sent_later(): void
    {
        Bus::shouldReceive('dispatch')->andThrow(new RuntimeException('queue locked'));
        $this->withoutExceptionHandling();

        try {
            $this->send();
            $this->fail('expected the dispatch failure to surface');
        } catch (RuntimeException) {
            $this->assertSame(0, OutgoingMessage::count());
        }
    }
}
