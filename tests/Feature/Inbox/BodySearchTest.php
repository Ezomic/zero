<?php

namespace Tests\Feature\Inbox;

use App\Models\Email;
use App\Models\MailAccount;
use App\Models\User;
use App\Services\Mail\GraphMailSyncService;
use App\Services\Mail\OAuthTokenRefresher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

/**
 * emails_fts indexes body_text, and bulk sync stores none: fetchBody() only
 * runs when a message is opened, and almost none ever are. Searching for a
 * phrase from inside a message found nothing for 97 percent of the mailbox
 * (ZERO-102).
 */
class BodySearchTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private MailAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->user = User::factory()->create();
        $this->account = MailAccount::factory()->create([
            'user_id' => $this->user->id,
            'provider' => MailAccount::PROVIDER_OUTLOOK,
            'oauth_access_token' => 'token',
            'oauth_expires_at' => now()->addHour(),
        ]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }

    private function search(string $term): TestResponse
    {
        return $this->actingAs($this->user)->get(route('inbox.index', ['q' => $term]));
    }

    public function test_a_body_that_was_stored_is_searchable(): void
    {
        Email::factory()->create([
            'mail_account_id' => $this->account->id,
            'folder' => 'INBOX',
            'subject' => 'Nothing useful in the subject',
            'body_text' => 'The mooring mast needs inspecting before Tuesday.',
        ]);

        $this->search('mooring')
            ->assertOk()
            ->assertSee('Nothing useful in the subject');
    }

    /**
     * The half of the problem that needed no network: an HTML body stored a
     * null body_text, so even an opened message was unsearchable.
     */
    public function test_an_html_only_body_becomes_searchable_once_fetched(): void
    {
        $email = Email::factory()->create([
            'mail_account_id' => $this->account->id,
            'folder' => 'INBOX',
            'subject' => 'Nothing useful in the subject',
            'uid' => 'graph-id',
            'body_html' => null,
            'body_text' => null,
        ]);

        Http::fake([
            '*/attachments' => Http::response(['value' => []], 200),
            '*' => Http::response([
                'body' => ['contentType' => 'html', 'content' => '<p>The <b>mooring</b> mast needs inspecting.</p>'],
                'hasAttachments' => false,
            ], 200),
        ]);

        $refresher = Mockery::mock(OAuthTokenRefresher::class);
        $refresher->shouldReceive('freshAccessToken')->andReturn('token');
        (new GraphMailSyncService($refresher))->fetchBody($email);

        // The HTML is still what gets rendered; the text exists to be indexed.
        $this->assertSame('<p>The <b>mooring</b> mast needs inspecting.</p>', $email->refresh()->body_html);
        $this->assertStringContainsString('mooring', (string) $email->body_text);

        $this->search('mooring')
            ->assertOk()
            ->assertSee('Nothing useful in the subject');
    }

    public function test_a_message_with_no_body_at_all_is_still_not_matched(): void
    {
        Email::factory()->create([
            'mail_account_id' => $this->account->id,
            'folder' => 'INBOX',
            'subject' => 'Unopened message',
            'body_text' => null,
            'body_html' => null,
        ]);

        // Nothing to match on until the backfill has fetched it, which is
        // what BackfillBodiesCommandTest covers.
        $this->search('mooring')
            ->assertOk()
            ->assertDontSee('Unopened message');
    }

    public function test_searching_by_subject_still_works(): void
    {
        Email::factory()->create([
            'mail_account_id' => $this->account->id,
            'folder' => 'INBOX',
            'subject' => 'Zeppelin maintenance',
            'body_text' => null,
        ]);

        $this->search('zeppelin')
            ->assertOk()
            ->assertSee('Zeppelin maintenance');
    }
}
