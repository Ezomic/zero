<?php

namespace Tests\Feature\Outbound;

use App\Models\MailAccount;
use App\Models\OutboundToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class TokenCommandsTest extends TestCase
{
    use RefreshDatabase;

    private MailAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('outbound-ip:127.0.0.1');
        $this->account = MailAccount::factory()->create(['user_id' => User::factory()]);
    }

    private function plainFrom(string $output): string
    {
        preg_match('/zob_[0-9a-f]{64}/', $output, $matches);

        return $matches[0] ?? '';
    }

    private function statusFor(string $plain): int
    {
        return $this->withToken($plain)
            ->postJson('/api/outbound', ['to' => 'a@example.com', 'subject' => 's', 'text' => 't'])
            ->getStatusCode();
    }

    public function test_create_prints_the_plain_token_once_and_stores_only_its_hash(): void
    {
        $this->artisan('outbound:token-create', ['name' => 'filebox', '--account' => $this->account->id])
            ->expectsOutputToContain('zob_')
            ->assertSuccessful();

        $token = OutboundToken::sole();
        $this->assertSame('filebox', $token->name);
        $this->assertSame($this->account->id, $token->mail_account_id);
        $this->assertNull($token->revoked_at);
        $this->assertSame(64, strlen($token->token_hash));
    }

    public function test_the_printed_token_authenticates(): void
    {
        Queue::fake();
        Artisan::call('outbound:token-create', ['name' => 'filebox', '--account' => $this->account->id]);

        $this->assertSame(202, $this->statusFor($this->plainFrom(Artisan::output())));
    }

    public function test_create_refuses_a_missing_account_a_duplicate_name_and_an_inactive_account(): void
    {
        $this->artisan('outbound:token-create', ['name' => 'filebox', '--account' => 9999])->assertFailed();
        $this->artisan('outbound:token-create', ['name' => 'filebox'])->assertFailed();

        $this->artisan('outbound:token-create', ['name' => 'filebox', '--account' => $this->account->id])->assertSuccessful();
        $this->artisan('outbound:token-create', ['name' => 'filebox', '--account' => $this->account->id])->assertFailed();

        $inactive = MailAccount::factory()->create(['is_active' => false]);
        $this->artisan('outbound:token-create', ['name' => 'x', '--account' => $inactive->id])->assertFailed();

        $this->assertSame(1, OutboundToken::count());
    }

    public function test_revoke_stops_the_token_working(): void
    {
        Queue::fake();
        [, $plain] = OutboundToken::issue('filebox', $this->account);

        $this->artisan('outbound:token-revoke', ['name' => 'filebox'])->assertSuccessful();

        $this->assertSame(401, $this->statusFor($plain));
        $this->artisan('outbound:token-revoke', ['name' => 'nope'])->assertFailed();
    }

    public function test_rotate_replaces_the_secret_and_keeps_name_and_account(): void
    {
        Queue::fake();
        [$token, $old] = OutboundToken::issue('filebox', $this->account);

        $this->assertSame(0, Artisan::call('outbound:token-rotate', ['name' => 'filebox']));
        $new = $this->plainFrom(Artisan::output());

        $this->assertNotSame('', $new);
        $this->assertNotSame($old, $new);
        $this->assertSame($token->id, OutboundToken::sole()->id);
        $this->assertSame(401, $this->statusFor($old));
        $this->assertSame(202, $this->statusFor($new));
    }

    public function test_a_revoked_token_cannot_be_rotated_back_to_life(): void
    {
        OutboundToken::issue('filebox', $this->account)[0]->revoke();

        $this->artisan('outbound:token-rotate', ['name' => 'filebox'])->assertFailed();
        $this->assertNotNull(OutboundToken::sole()->revoked_at);
    }
}
