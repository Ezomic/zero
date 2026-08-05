<?php

namespace Tests\Feature\Mail;

use App\Models\MailAccount;
use App\Models\User;
use App\Services\Mail\ImapClientFactory;
use App\Services\Mail\MailSenderService;
use App\Services\Mail\OAuthTokenRefresher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;
use Webklex\PHPIMAP\Client;

class ImapClientFactoryTest extends TestCase
{
    use RefreshDatabase;

    private function factory(?OAuthTokenRefresher $refresher = null): ImapClientFactory
    {
        return new ImapClientFactory($refresher ?? Mockery::mock(OAuthTokenRefresher::class));
    }

    private function account(array $attributes = []): MailAccount
    {
        return MailAccount::factory()->create([
            'user_id' => User::factory(),
            'provider' => MailAccount::PROVIDER_IMAP,
            ...$attributes,
        ]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }

    public function test_the_apps_imap_options_reach_the_client(): void
    {
        // Regression for ZERO-51: a bare `new ClientManager` ignores
        // config/imap.php, so options.fallback_date never reached the client
        // and one message with an unparseable Date header aborted the sync.
        config()->set('imap.options.fallback_date', '1970-01-01 00:00:00');

        $options = $this->factory()->make($this->account())->getConfig()->get('options');

        $this->assertSame('1970-01-01 00:00:00', $options['fallback_date']);
    }

    public function test_a_password_account_authenticates_with_its_stored_password(): void
    {
        $client = $this->factory()->make($this->account([
            'imap_host' => 'imap.example.com',
            'imap_username' => 'me@example.com',
            'imap_password' => 'stored-secret',
        ]));

        $this->assertSame('imap.example.com', $client->host);
        $this->assertSame('me@example.com', $client->username);
        $this->assertSame('stored-secret', $client->password);
        $this->assertNotSame('oauth', $client->authentication);
    }

    public function test_an_oauth_account_authenticates_with_a_freshly_refreshed_token(): void
    {
        $account = $this->account([
            'provider' => MailAccount::PROVIDER_GMAIL,
            'imap_password' => 'irrelevant',
        ]);

        $refresher = Mockery::mock(OAuthTokenRefresher::class);
        $refresher->shouldReceive('freshAccessToken')->once()->with($account)->andReturn('fresh-token');

        $client = $this->factory($refresher)->make($account);

        $this->assertSame('oauth', $client->authentication);
        $this->assertSame('fresh-token', $client->password);
    }

    /**
     * The sender used to build its own client with a bare ClientManager, so
     * the append to Sent ran without the app's options (ZERO-96).
     */
    public function test_the_sender_builds_its_client_through_the_factory(): void
    {
        config()->set('imap.options.fallback_date', '1970-01-01 00:00:00');

        $refresher = Mockery::mock(OAuthTokenRefresher::class);
        $service = new MailSenderService($refresher, $this->factory($refresher));

        $make = new \ReflectionMethod(MailSenderService::class, 'makeImapClient');
        $make->setAccessible(true);
        $client = $make->invoke($service, $this->account());

        $this->assertInstanceOf(Client::class, $client);
        $this->assertSame('1970-01-01 00:00:00', $client->getConfig()->get('options')['fallback_date']);
    }
}
