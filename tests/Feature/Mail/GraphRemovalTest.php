<?php

namespace Tests\Feature\Mail;

use App\Models\Email;
use App\Models\MailAccount;
use App\Models\MailFolder;
use App\Models\PendingMirrorAction;
use App\Models\User;
use App\Services\Mail\GraphMailSyncService;
use App\Services\Mail\OAuthTokenRefresher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * ZERO-90 taught the IMAP path to notice messages deleted elsewhere. Outlook
 * still skipped them: delta reports removals explicitly through an @removed
 * marker and syncFolderPage() walked straight past it (ZERO-103).
 */
class GraphRemovalTest extends TestCase
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
            // A real deltaLink, so the folder counts as caught up.
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

    private function storedMessage(string $uid, array $attributes = []): Email
    {
        return Email::factory()->create([
            'mail_account_id' => $this->account->id,
            'folder' => 'INBOX',
            'remote_folder_path' => 'inbox-folder-id',
            'uid' => $uid,
            'is_deleted' => false,
            ...$attributes,
        ]);
    }

    /** @param list<array<string, mixed>> $value */
    private function fakeDelta(array $value): void
    {
        // Order matters: Http::fake uses the first pattern that matches, and
        // the delta URL also looks like a mailFolders lookup.
        Http::fake([
            '*/messages/delta*' => Http::response([
                'value' => $value,
                '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox-folder-id/messages/delta?$deltatoken=next',
            ], 200),
            '*/me/mailFolders/inbox' => Http::response(['id' => 'inbox-folder-id', 'displayName' => 'Inbox'], 200),
            // The other well-known folders this account does not have.
            '*/me/mailFolders/*' => Http::response([], 404),
            '*/me/mailFolders*' => Http::response(['value' => []], 200),
        ]);
    }

    public function test_a_removed_message_is_marked_deleted(): void
    {
        $email = $this->storedMessage('gone-id');
        $this->fakeDelta([['@removed' => ['reason' => 'deleted'], 'id' => 'gone-id']]);

        $this->service->sync($this->account);

        $this->assertTrue($email->refresh()->is_deleted);
    }

    /**
     * 'changed' means it left this folder's result set some other way, which
     * for a per-folder row is the same outcome.
     */
    public function test_a_removal_with_reason_changed_is_treated_the_same(): void
    {
        $email = $this->storedMessage('moved-id');
        $this->fakeDelta([['@removed' => ['reason' => 'changed'], 'id' => 'moved-id']]);

        $this->service->sync($this->account);

        $this->assertTrue($email->refresh()->is_deleted);
    }

    public function test_other_messages_in_the_folder_are_untouched(): void
    {
        $gone = $this->storedMessage('gone-id');
        $kept = $this->storedMessage('kept-id');

        $this->fakeDelta([['@removed' => ['reason' => 'deleted'], 'id' => 'gone-id']]);

        $this->service->sync($this->account);

        $this->assertTrue($gone->refresh()->is_deleted);
        $this->assertFalse($kept->refresh()->is_deleted);
    }

    public function test_another_accounts_message_with_the_same_id_is_untouched(): void
    {
        $theirs = Email::factory()->create([
            'mail_account_id' => MailAccount::factory()->create(['user_id' => User::factory()])->id,
            'folder' => 'INBOX',
            'uid' => 'gone-id',
            'is_deleted' => false,
        ]);

        $this->fakeDelta([['@removed' => ['reason' => 'deleted'], 'id' => 'gone-id']]);

        $this->service->sync($this->account);

        $this->assertFalse($theirs->refresh()->is_deleted);
    }

    /**
     * Until the drain runs, what the server reports and what the user asked
     * for are legitimately out of step. Matches the IMAP reconciliation.
     */
    public function test_a_message_with_an_action_still_queued_is_left_alone(): void
    {
        $email = $this->storedMessage('queued-id');

        PendingMirrorAction::create([
            'mail_account_id' => $this->account->id,
            'email_id' => $email->id,
            'action' => 'move:Archive',
            'remote_folder_path' => 'inbox-folder-id',
            'uid' => 'queued-id',
        ]);

        $this->fakeDelta([['@removed' => ['reason' => 'changed'], 'id' => 'queued-id']]);

        $this->service->sync($this->account);

        $this->assertFalse($email->refresh()->is_deleted);
    }

    public function test_additions_in_the_same_page_are_still_stored(): void
    {
        $gone = $this->storedMessage('gone-id');

        $this->fakeDelta([
            ['@removed' => ['reason' => 'deleted'], 'id' => 'gone-id'],
            [
                'id' => 'brand-new-id',
                'subject' => 'Fresh arrival',
                'from' => ['emailAddress' => ['address' => 'sender@example.com', 'name' => 'Sender']],
                'receivedDateTime' => '2026-08-07T10:00:00Z',
                'isRead' => false,
                'internetMessageId' => '<new@example.com>',
            ],
        ]);

        $this->service->sync($this->account);

        $this->assertTrue($gone->refresh()->is_deleted);
        $this->assertDatabaseHas('emails', ['uid' => 'brand-new-id', 'subject' => 'Fresh arrival', 'is_deleted' => false]);
    }

    public function test_a_removal_for_a_message_we_never_had_is_harmless(): void
    {
        $this->fakeDelta([['@removed' => ['reason' => 'deleted'], 'id' => 'unknown-id']]);

        $this->service->sync($this->account);

        $this->assertSame('idle', $this->account->refresh()->sync_status);
    }
}
