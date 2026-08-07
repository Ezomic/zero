<?php

namespace Tests\Feature\Accounts;

use App\Jobs\SyncMailAccountJob;
use App\Models\MailAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

/**
 * Reconnecting is one of two routes back to a working account, and it used to
 * reset only is_active. The account then worked again while still rendering
 * as broken, with the old failure text under it (ZERO-105).
 */
class OAuthReconnectTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }

    private function brokenAccount(string $provider, string $email): MailAccount
    {
        return MailAccount::factory()->create([
            'user_id' => $this->user->id,
            'provider' => $provider,
            'email_address' => $email,
            'is_active' => false,
            'sync_status' => 'error',
            'sync_error' => 'NO AUTHENTICATE failed.',
        ]);
    }

    private function fakeSocialite(string $driver, string $email): void
    {
        $socialiteUser = new SocialiteUser;
        $socialiteUser->map([
            'id' => 'remote-id',
            'name' => 'Robbin',
            'email' => $email,
        ]);
        $socialiteUser->token = 'fresh-access-token';
        $socialiteUser->refreshToken = 'fresh-refresh-token';
        $socialiteUser->expiresIn = 3600;

        $provider = Mockery::mock(AbstractProvider::class);
        $provider->shouldReceive('user')->andReturn($socialiteUser);

        Socialite::shouldReceive('driver')->with($driver)->andReturn($provider);
    }

    public function test_reconnecting_gmail_clears_the_failure(): void
    {
        $account = $this->brokenAccount(MailAccount::PROVIDER_GMAIL, 'me@gmail.com');
        $this->fakeSocialite('google', 'me@gmail.com');

        $this->actingAs($this->user)
            ->get(route('auth.google.callback'))
            ->assertRedirect(route('accounts.index'));

        $account->refresh();
        $this->assertTrue($account->is_active);
        $this->assertSame('idle', $account->sync_status);
        $this->assertNull($account->sync_error);
        $this->assertNotNull($account->sync_status_since);
        Queue::assertPushed(SyncMailAccountJob::class);
    }

    public function test_reconnecting_outlook_clears_the_failure(): void
    {
        $account = $this->brokenAccount(MailAccount::PROVIDER_OUTLOOK, 'me@outlook.com');
        $this->fakeSocialite('graph', 'me@outlook.com');

        $this->actingAs($this->user)
            ->get(route('auth.microsoft.callback'))
            ->assertRedirect(route('accounts.index'));

        $account->refresh();
        $this->assertTrue($account->is_active);
        $this->assertSame('idle', $account->sync_status);
        $this->assertNull($account->sync_error);
        Queue::assertPushed(SyncMailAccountJob::class);
    }

    public function test_reconnecting_stores_the_fresh_tokens(): void
    {
        $account = $this->brokenAccount(MailAccount::PROVIDER_GMAIL, 'me@gmail.com');
        $this->fakeSocialite('google', 'me@gmail.com');

        $this->actingAs($this->user)->get(route('auth.google.callback'));

        $account->refresh();
        $this->assertSame('fresh-access-token', $account->oauth_access_token);
        $this->assertSame('fresh-refresh-token', $account->oauth_refresh_token);
    }

    /**
     * Without a refresh token the connection is not actually repaired, so the
     * account must not be presented as healthy.
     */
    public function test_a_callback_with_no_refresh_token_leaves_the_account_alone(): void
    {
        $account = $this->brokenAccount(MailAccount::PROVIDER_GMAIL, 'me@gmail.com');

        $socialiteUser = new SocialiteUser;
        $socialiteUser->map(['id' => 'x', 'name' => 'Robbin', 'email' => 'me@gmail.com']);
        $socialiteUser->token = 'token';
        $socialiteUser->refreshToken = null;

        $provider = Mockery::mock(AbstractProvider::class);
        $provider->shouldReceive('user')->andReturn($socialiteUser);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $this->actingAs($this->user)
            ->get(route('auth.google.callback'))
            ->assertRedirect(route('accounts.index'))
            ->assertSessionHas('error');

        $account->refresh();
        $this->assertFalse($account->is_active);
        $this->assertSame('error', $account->sync_status);
        Queue::assertNotPushed(SyncMailAccountJob::class);
    }
}
