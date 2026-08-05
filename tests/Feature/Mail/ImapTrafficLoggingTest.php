<?php

namespace Tests\Feature\Mail;

use App\Models\MailAccount;
use App\Models\User;
use App\Services\Mail\ImapClientFactory;
use App\Services\Mail\ImapSyncService;
use App\Services\Mail\OAuthTokenRefresher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class TestableImapSyncServiceForLogging extends ImapSyncService
{
    public function callImapTrafficLogger(MailAccount $account): \Closure
    {
        return $this->imapTrafficLogger($account);
    }
}

class ImapTrafficLoggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_logs_masked_imap_traffic_to_the_imap_channel(): void
    {
        $logged = [];
        Log::shouldReceive('channel')->with('imap')->andReturnSelf();
        Log::shouldReceive('debug')->andReturnUsing(function (string $message) use (&$logged): void {
            $logged[] = $message;
        });

        $clients = new ImapClientFactory(Mockery::mock(OAuthTokenRefresher::class));
        $service = new TestableImapSyncServiceForLogging($clients);
        $account = MailAccount::factory()->create(['user_id' => User::factory()]);

        $handler = $service->callImapTrafficLogger($account);

        $result = $handler(
            ">> TAG1 LOGIN \"ezomic@gmail.com\" \"supersecretpassword\"\r\n".
            ">> TAG2 SELECT INBOX\r\n".
            "<< * OK [READ-WRITE] INBOX selected\r\n"
        );

        // Nothing is written to stdout.
        $this->assertSame('', $result);

        $all = implode("\n", $logged);
        $this->assertStringNotContainsString('supersecretpassword', $all, 'the password must never be logged');
        $this->assertStringContainsString('LOGIN "ezomic@gmail.com" ***', $all);
        $this->assertStringContainsString('>> TAG2 SELECT INBOX', $all);
        $this->assertStringContainsString('<< * OK [READ-WRITE] INBOX selected', $all);
        $this->assertStringContainsString("account {$account->id}:", $all);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }
}
