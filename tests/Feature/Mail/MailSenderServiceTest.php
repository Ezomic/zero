<?php

namespace Tests\Feature\Mail;

use App\Models\MailAccount;
use App\Models\User;
use App\Services\Mail\MailSenderService;
use App\Services\Mail\OAuthTokenRefresher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

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

        $service = new MailSenderService($refresher);
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

        $service = new MailSenderService($refresher);
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

        $service = new MailSenderService($refresher);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Gmail send failed/');

        $service->send($account, [
            'to' => ['r@example.com'],
            'subject' => 'X',
            'html' => '<p>X</p>',
        ]);
    }
}
