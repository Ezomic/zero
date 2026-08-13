<?php

namespace Tests\Feature\Mail;

use App\Models\Email;
use App\Models\MailAccount;
use App\Models\MailFolder;
use App\Models\User;
use App\Services\Mail\GraphMailSyncService;
use App\Services\Mail\OAuthTokenRefresher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * The star has to survive a round trip in both directions: set here it must
 * reach the server, set on a phone it must reach here (ZERO-113).
 */
class StarSyncTest extends TestCase
{
    use RefreshDatabase;

    private MailAccount $account;

    private GraphMailSyncService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = MailAccount::factory()->create([
            'user_id' => User::factory(),
            'provider' => MailAccount::PROVIDER_OUTLOOK,
            'oauth_access_token' => 'token',
            'oauth_expires_at' => now()->addHour(),
        ]);

        MailFolder::create([
            'mail_account_id' => $this->account->id,
            'local_name' => 'INBOX',
            'remote_path' => 'inbox-folder-id',
            'delta_link' => 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox-folder-id/messages/delta?$deltatoken=abc',
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

    /** @param list<array<string, mixed>> $value */
    private function fakeDelta(array $value): void
    {
        Http::fake([
            '*/messages/delta*' => Http::response([
                'value' => $value,
                '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox-folder-id/messages/delta?$deltatoken=next',
            ], 200),
            '*/me/mailFolders/inbox' => Http::response(['id' => 'inbox-folder-id', 'displayName' => 'Inbox'], 200),
            '*/me/mailFolders/*' => Http::response([], 404),
            '*/me/mailFolders*' => Http::response(['value' => []], 200),
            '*' => Http::response([], 200),
        ]);
    }

    public function test_graph_star_action_patches_the_followup_flag(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $email = Email::factory()->create(['mail_account_id' => $this->account->id, 'uid' => 'msg-1']);

        $this->service->applyAction($email, 'star');

        Http::assertSent(fn ($request) => $request->method() === 'PATCH'
            && $request['flag']['flagStatus'] === 'flagged');
    }

    public function test_graph_unstar_action_clears_it(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $email = Email::factory()->create(['mail_account_id' => $this->account->id, 'uid' => 'msg-1']);

        $this->service->applyAction($email, 'unstar');

        Http::assertSent(fn ($request) => $request['flag']['flagStatus'] === 'notFlagged');
    }

    public function test_a_newly_synced_message_records_the_servers_flag(): void
    {
        $this->fakeDelta([
            [
                'id' => 'flagged-id',
                'subject' => 'Flagged elsewhere',
                'from' => ['emailAddress' => ['address' => 'a@example.com']],
                'receivedDateTime' => '2026-08-07T10:00:00Z',
                'isRead' => true,
                'flag' => ['flagStatus' => 'flagged'],
            ],
            [
                'id' => 'plain-id',
                'subject' => 'Not flagged',
                'from' => ['emailAddress' => ['address' => 'b@example.com']],
                'receivedDateTime' => '2026-08-07T10:00:00Z',
                'isRead' => true,
                'flag' => ['flagStatus' => 'notFlagged'],
            ],
        ]);

        $this->service->sync($this->account);

        $this->assertTrue(Email::where('uid', 'flagged-id')->sole()->is_starred);
        $this->assertFalse(Email::where('uid', 'plain-id')->sole()->is_starred);
    }

    public function test_a_star_set_on_another_device_reaches_an_existing_message(): void
    {
        $email = Email::factory()->create([
            'mail_account_id' => $this->account->id,
            'folder' => 'INBOX',
            'uid' => 'known-id',
            'is_read' => true,
            'is_starred' => false,
        ]);

        $this->fakeDelta([[
            'id' => 'known-id',
            'isRead' => true,
            'flag' => ['flagStatus' => 'flagged'],
        ]]);

        $this->service->sync($this->account);

        $this->assertTrue($email->refresh()->is_starred);
    }

    public function test_a_star_removed_on_another_device_is_cleared_here(): void
    {
        $email = Email::factory()->create([
            'mail_account_id' => $this->account->id,
            'folder' => 'INBOX',
            'uid' => 'known-id',
            'is_read' => true,
            'is_starred' => true,
        ]);

        $this->fakeDelta([[
            'id' => 'known-id',
            'isRead' => true,
            'flag' => ['flagStatus' => 'notFlagged'],
        ]]);

        $this->service->sync($this->account);

        $this->assertFalse($email->refresh()->is_starred);
    }

    /**
     * Graph reports 'complete' for a followup that was ticked off, which is
     * not the same as starred.
     */
    public function test_a_completed_followup_is_not_a_star(): void
    {
        $this->fakeDelta([[
            'id' => 'done-id',
            'subject' => 'Completed followup',
            'from' => ['emailAddress' => ['address' => 'a@example.com']],
            'receivedDateTime' => '2026-08-07T10:00:00Z',
            'isRead' => true,
            'flag' => ['flagStatus' => 'complete'],
        ]]);

        $this->service->sync($this->account);

        $this->assertFalse(Email::where('uid', 'done-id')->sole()->is_starred);
    }

    public function test_a_message_with_no_flag_field_is_not_starred(): void
    {
        $this->fakeDelta([[
            'id' => 'bare-id',
            'subject' => 'No flag reported',
            'from' => ['emailAddress' => ['address' => 'a@example.com']],
            'receivedDateTime' => '2026-08-07T10:00:00Z',
            'isRead' => true,
        ]]);

        $this->service->sync($this->account);

        $this->assertFalse(Email::where('uid', 'bare-id')->sole()->is_starred);
    }
}
