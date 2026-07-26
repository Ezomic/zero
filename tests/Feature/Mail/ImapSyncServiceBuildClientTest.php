<?php

namespace Tests\Feature\Mail;

use App\Models\MailAccount;
use App\Models\User;
use App\Services\Mail\ImapSyncService;
use App\Services\Mail\OAuthTokenRefresher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;
use Webklex\PHPIMAP\Client;

class TestableImapSyncServiceForBuildClient extends ImapSyncService
{
    public function callBuildClient(MailAccount $account): Client
    {
        return $this->buildClient($account);
    }
}

class ImapSyncServiceBuildClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_built_client_applies_the_apps_imap_options_including_fallback_date(): void
    {
        // Regression for ZERO-51: a bare `new ClientManager` ignores
        // config/imap.php, so options.fallback_date never reached the client
        // and one message with an unparseable Date header aborted the sync.
        config()->set('imap.options.fallback_date', '1970-01-01 00:00:00');

        $refresher = Mockery::mock(OAuthTokenRefresher::class);
        $service = new TestableImapSyncServiceForBuildClient($refresher);
        $account = MailAccount::factory()->create([
            'user_id' => User::factory(),
            'provider' => MailAccount::PROVIDER_IMAP,
        ]);

        $options = $service->callBuildClient($account)->getConfig()->get('options');

        $this->assertSame('1970-01-01 00:00:00', $options['fallback_date']);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }
}
