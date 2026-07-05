<?php

namespace Tests\Feature\Mail;

use App\Models\MailAccount;
use App\Models\User;
use App\Services\Mail\OAuthTokenRefresher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OAuthTokenRefresherTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private OAuthTokenRefresher $refresher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->refresher = new OAuthTokenRefresher;
    }

    public function test_returns_stored_token_when_not_expired(): void
    {
        $account = MailAccount::factory()->create([
            'user_id' => $this->user->id,
            'provider' => MailAccount::PROVIDER_GMAIL,
            'oauth_access_token' => 'valid-token',
            'oauth_refresh_token' => 'refresh-token',
            'oauth_expires_at' => now()->addHour(),
        ]);

        Http::preventStrayRequests();

        $token = $this->refresher->freshAccessToken($account);

        $this->assertSame('valid-token', $token);
    }

    public function test_refreshes_google_token_when_expired(): void
    {
        $account = MailAccount::factory()->create([
            'user_id' => $this->user->id,
            'provider' => MailAccount::PROVIDER_GMAIL,
            'oauth_access_token' => 'old-token',
            'oauth_refresh_token' => 'my-refresh-token',
            'oauth_expires_at' => now()->subMinute(),
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'fresh-google-token',
                'expires_in' => 3600,
            ], 200),
        ]);

        $token = $this->refresher->freshAccessToken($account);

        $this->assertSame('fresh-google-token', $token);
        $updated = $account->fresh();
        $this->assertSame('fresh-google-token', $updated->oauth_access_token);
        $this->assertTrue($updated->oauth_expires_at->isFuture());
    }

    public function test_refreshes_microsoft_token_and_stores_new_refresh_token(): void
    {
        $account = MailAccount::factory()->create([
            'user_id' => $this->user->id,
            'provider' => MailAccount::PROVIDER_OUTLOOK,
            'oauth_access_token' => 'old-token',
            'oauth_refresh_token' => 'ms-refresh-token',
            'oauth_expires_at' => now()->subMinute(),
        ]);

        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response([
                'access_token' => 'fresh-ms-token',
                'refresh_token' => 'new-refresh-token',
                'expires_in' => 3600,
            ], 200),
        ]);

        $token = $this->refresher->freshAccessToken($account);

        $this->assertSame('fresh-ms-token', $token);
        $updated = $account->fresh();
        $this->assertSame('fresh-ms-token', $updated->oauth_access_token);
        $this->assertSame('new-refresh-token', $updated->oauth_refresh_token);
    }

    public function test_sets_sync_status_to_error_and_throws_when_google_refresh_fails(): void
    {
        $account = MailAccount::factory()->create([
            'user_id' => $this->user->id,
            'provider' => MailAccount::PROVIDER_GMAIL,
            'oauth_access_token' => null,
            'oauth_refresh_token' => 'bad-refresh',
            'oauth_expires_at' => now()->subMinute(),
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $this->expectException(\RuntimeException::class);

        try {
            $this->refresher->freshAccessToken($account);
        } finally {
            $this->assertSame('error', $account->fresh()->sync_status);
        }
    }

    public function test_builds_valid_xoauth2_token(): void
    {
        $raw = "user=test@example.com\x01auth=Bearer mytoken\x01\x01";
        $expected = base64_encode($raw);

        $this->assertSame($expected, $this->refresher->buildXOAuth2Token('test@example.com', 'mytoken'));
    }
}
