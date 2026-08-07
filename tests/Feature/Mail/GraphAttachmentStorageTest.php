<?php

namespace Tests\Feature\Mail;

use App\Models\Email;
use App\Models\EmailAttachment;
use App\Models\MailAccount;
use App\Models\User;
use App\Services\Mail\GraphMailSyncService;
use App\Services\Mail\OAuthTokenRefresher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * Exercises the sanitising through a real fetchBody(), so it covers the part
 * that actually broke: one hostile name used to throw after the body was
 * saved, leaving the message with no attachment rows at all and no second
 * chance, because the non-null body stops fetchBody ever running again
 * (ZERO-106).
 */
class GraphAttachmentStorageTest extends TestCase
{
    use RefreshDatabase;

    private Email $email;

    private GraphMailSyncService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $account = MailAccount::factory()->create([
            'user_id' => User::factory(),
            'provider' => MailAccount::PROVIDER_OUTLOOK,
            'oauth_access_token' => 'token',
            'oauth_expires_at' => now()->addHour(),
        ]);

        $this->email = Email::factory()->create([
            'mail_account_id' => $account->id,
            'uid' => 'graph-message-id',
            'body_html' => null,
            'body_text' => null,
        ]);

        $refresher = Mockery::mock(OAuthTokenRefresher::class);
        $refresher->shouldReceive('freshAccessToken')->andReturn('token');
        $this->service = new GraphMailSyncService($refresher);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }

    /** @param list<array{name: string, content: string}> $attachments */
    private function fakeGraph(array $attachments): void
    {
        Http::fake([
            'graph.microsoft.com/v1.0/me/messages/graph-message-id/attachments' => Http::response([
                'value' => array_map(fn (array $a): array => [
                    '@odata.type' => '#microsoft.graph.fileAttachment',
                    'name' => $a['name'],
                    'contentType' => 'application/octet-stream',
                    'size' => strlen($a['content']),
                    'contentBytes' => base64_encode($a['content']),
                ], $attachments),
            ], 200),
            'graph.microsoft.com/v1.0/me/messages/graph-message-id*' => Http::response([
                'body' => ['contentType' => 'text', 'content' => 'the body'],
                'hasAttachments' => true,
            ], 200),
        ]);
    }

    public function test_a_traversing_filename_is_stored_rather_than_dropped(): void
    {
        $this->fakeGraph([['name' => '../../../../escaped.txt', 'content' => 'payload']]);

        $this->service->fetchBody($this->email);

        $attachment = EmailAttachment::sole();

        // The original is kept for display and for Content-Disposition.
        $this->assertSame('../../../../escaped.txt', $attachment->filename);
        // The path is confined to this message's own directory.
        $this->assertStringStartsWith("email-attachments/{$this->email->mail_account_id}/{$this->email->id}/", $attachment->storage_path);
        $this->assertStringNotContainsString('..', $attachment->storage_path);
        Storage::disk('local')->assertExists($attachment->storage_path);
        $this->assertSame('payload', Storage::disk('local')->get($attachment->storage_path));
    }

    public function test_one_bad_attachment_does_not_cost_the_message_the_others(): void
    {
        $this->fakeGraph([
            ['name' => '../../../../escaped.txt', 'content' => 'first'],
            ['name' => 'report.pdf', 'content' => 'second'],
        ]);

        $this->service->fetchBody($this->email);

        $this->assertSame(2, EmailAttachment::count());
        $this->assertEqualsCanonicalizing(
            ['../../../../escaped.txt', 'report.pdf'],
            EmailAttachment::pluck('filename')->all(),
        );
    }

    public function test_two_attachments_sharing_a_name_get_two_files(): void
    {
        $this->fakeGraph([
            ['name' => 'image.png', 'content' => 'first image'],
            ['name' => 'image.png', 'content' => 'second image'],
        ]);

        $this->service->fetchBody($this->email);

        $paths = EmailAttachment::pluck('storage_path');

        $this->assertCount(2, $paths->unique(), 'each attachment needs its own file');
        $this->assertEqualsCanonicalizing(
            ['first image', 'second image'],
            $paths->map(fn (string $p): string => Storage::disk('local')->get($p))->all(),
        );
    }

    public function test_the_body_is_still_saved_alongside(): void
    {
        $this->fakeGraph([['name' => 'report.pdf', 'content' => 'x']]);

        $this->service->fetchBody($this->email);

        $this->assertSame('the body', $this->email->refresh()->body_text);
    }
}
