<?php

namespace Tests\Feature\Mail;

use App\Models\MailAccount;
use App\Models\User;
use App\Services\Mail\ImapClientFactory;
use App\Services\Mail\MailSenderService;
use App\Services\Mail\OAuthTokenRefresher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Tests\TestCase;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Support\FolderCollection;

class MailSenderServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_sends_via_gmail_api_with_url_safe_base64(): void
    {
        $account = MailAccount::factory()->create([
            'user_id' => $this->user->id,
            'provider' => MailAccount::PROVIDER_GMAIL,
            'email_address' => 'sender@gmail.com',
            'oauth_access_token' => 'gmail-token',
            'oauth_expires_at' => now()->addHour(),
        ]);

        Http::fake([
            'https://gmail.googleapis.com/*' => Http::response(['id' => 'msg-123'], 200),
        ]);

        $refresher = Mockery::mock(OAuthTokenRefresher::class);
        $refresher->shouldReceive('freshAccessToken')->andReturn('gmail-token');

        $service = new MailSenderService($refresher, new ImapClientFactory($refresher));
        $service->send($account, [
            'to' => ['recipient@example.com'],
            'subject' => 'Hello',
            'html' => '<p>World</p>',
        ]);

        Http::assertSent(function ($request) {
            $this->assertStringContainsString('gmail.googleapis.com', $request->url());
            $raw = $request->data()['raw'];
            // URL-safe base64 must not contain +, /, or =
            $this->assertStringNotContainsString('+', $raw);
            $this->assertStringNotContainsString('/', $raw);
            $this->assertStringNotContainsString('=', $raw);

            return true;
        });
    }

    public function test_sends_via_graph_api_with_correct_payload_shape(): void
    {
        $account = MailAccount::factory()->create([
            'user_id' => $this->user->id,
            'provider' => MailAccount::PROVIDER_OUTLOOK,
            'email_address' => 'sender@outlook.com',
            'oauth_access_token' => 'ms-token',
            'oauth_expires_at' => now()->addHour(),
        ]);

        Http::fake([
            'https://graph.microsoft.com/*' => Http::response(null, 202),
        ]);

        $refresher = Mockery::mock(OAuthTokenRefresher::class);
        $refresher->shouldReceive('freshAccessToken')->andReturn('ms-token');

        $service = new MailSenderService($refresher, new ImapClientFactory($refresher));
        $service->send($account, [
            'to' => ['recipient@example.com'],
            'cc' => ['cc@example.com'],
            'subject' => 'Test subject',
            'html' => '<p>Body</p>',
            'in_reply_to' => 'original-message-id@domain.com',
        ]);

        Http::assertSent(function ($request) {
            $data = $request->data();
            $this->assertSame('Test subject', $data['message']['subject']);
            $this->assertSame('recipient@example.com', $data['message']['toRecipients'][0]['emailAddress']['address']);
            $this->assertSame('cc@example.com', $data['message']['ccRecipients'][0]['emailAddress']['address']);
            $this->assertTrue($data['saveToSentItems']);
            $headers = collect($data['message']['internetMessageHeaders'] ?? []);
            $this->assertNotNull($headers->firstWhere('name', 'In-Reply-To'));

            return true;
        });
    }

    public function test_throws_runtime_exception_when_gmail_api_fails(): void
    {
        $account = MailAccount::factory()->create([
            'user_id' => $this->user->id,
            'provider' => MailAccount::PROVIDER_GMAIL,
            'email_address' => 'sender@gmail.com',
            'oauth_access_token' => 'gmail-token',
            'oauth_expires_at' => now()->addHour(),
        ]);

        Http::fake([
            'https://gmail.googleapis.com/*' => Http::response(['error' => 'quota exceeded'], 429),
        ]);

        $refresher = Mockery::mock(OAuthTokenRefresher::class);
        $refresher->shouldReceive('freshAccessToken')->andReturn('gmail-token');

        $service = new MailSenderService($refresher, new ImapClientFactory($refresher));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Gmail send failed/');

        $service->send($account, [
            'to' => ['r@example.com'],
            'subject' => 'X',
            'html' => '<p>X</p>',
        ]);
    }

    private function dsnFor(?string $encryption, int $port): string
    {
        $account = MailAccount::factory()->make([
            'user_id' => $this->user->id,
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => $port,
            'smtp_encryption' => $encryption,
            'smtp_username' => 'me@example.com',
            'smtp_password' => 'p@ss word',
        ]);

        $service = new MailSenderService($refresher = Mockery::mock(OAuthTokenRefresher::class), new ImapClientFactory($refresher));

        $dsn = new \ReflectionMethod(MailSenderService::class, 'smtpDsn');
        $dsn->setAccessible(true);

        return (string) $dsn->invoke($service, $account);
    }

    public function test_ssl_accounts_get_an_implicit_tls_dsn(): void
    {
        $this->assertStringStartsWith('smtps://', $this->dsnFor('ssl', 465));
        $this->assertStringEndsWith('@smtp.example.com:465', $this->dsnFor('ssl', 465));
    }

    public function test_tls_accounts_stay_on_smtp_so_starttls_is_negotiated(): void
    {
        $this->assertStringStartsWith('smtp://', $this->dsnFor('tls', 587));
    }

    public function test_an_account_with_no_encryption_stays_on_smtp(): void
    {
        $this->assertStringStartsWith('smtp://', $this->dsnFor(null, 25));
    }

    public function test_credentials_are_url_encoded_in_the_dsn(): void
    {
        $dsn = $this->dsnFor('tls', 587);

        $this->assertStringContainsString('me%40example.com:p%40ss%20word@', $dsn);
    }

    /**
     * The scheme is only worth changing if Symfony acts on it, so assert the
     * socket it builds rather than the string we handed it.
     */
    public function test_only_the_ssl_dsn_wraps_the_socket_in_tls(): void
    {
        $this->assertTrue($this->streamUsesTls($this->dsnFor('ssl', 465)));
        $this->assertFalse($this->streamUsesTls($this->dsnFor('tls', 587)));
    }

    private function streamUsesTls(string $dsn): bool
    {
        $transport = Transport::fromDsn($dsn);

        $getStream = new \ReflectionMethod(EsmtpTransport::class, 'getStream');
        $getStream->setAccessible(true);
        $stream = $getStream->invoke($transport);

        $tls = new \ReflectionProperty(SocketStream::class, 'tls');
        $tls->setAccessible(true);

        return (bool) $tls->getValue($stream);
    }

    public function test_append_to_sent_folder_appends_on_the_folder_not_the_client(): void
    {
        $account = MailAccount::factory()->make(['user_id' => $this->user->id]);

        $sentFolder = Mockery::mock(Folder::class);
        $sentFolder->name = 'Sent';
        $sentFolder->shouldReceive('appendMessage')
            ->once()
            ->with('RAW-MIME', ['Seen']);

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('connect')->once();
        $client->shouldReceive('getFolders')->with(false)->andReturn(new FolderCollection([$sentFolder]));

        $refresher = Mockery::mock(OAuthTokenRefresher::class);

        $service = Mockery::mock(MailSenderService::class, [$refresher, new ImapClientFactory($refresher)])->makePartial();
        $service->shouldAllowMockingProtectedMethods()
            ->shouldReceive('makeImapClient')
            ->with($account)
            ->andReturn($client);

        $append = new \ReflectionMethod(MailSenderService::class, 'appendToSentFolder');
        $append->setAccessible(true);
        $append->invoke($service, $account, 'RAW-MIME');

        // Mockery's ->once() on the Folder is the assertion; verify it explicitly so
        // the test isn't reported as risky.
        $this->assertTrue(true);
    }
}
