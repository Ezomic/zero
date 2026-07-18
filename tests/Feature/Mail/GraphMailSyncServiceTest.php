<?php

namespace Tests\Feature\Mail;

use App\Events\NewEmailArrived;
use App\Models\Email;
use App\Models\EmailAttachment;
use App\Models\MailAccount;
use App\Models\MailFolder;
use App\Models\User;
use App\Services\Mail\GraphMailSyncService;
use App\Services\Mail\OAuthTokenRefresher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

// Subclasses that expose protected internals for unit testing without going
// through the full sync()'s multi-folder HTTP flow every time.
class TestableGraphMailSyncService extends GraphMailSyncService
{
    public function callStoreMessage(MailAccount $account, string $folderName, string $graphFolderId, array $message, bool $broadcastNew): void
    {
        $this->storeMessage($account, $folderName, $graphFolderId, $message, $broadcastNew);
    }

    public function callSyncFolderPage(MailAccount $account, string $accessToken, string $graphFolderId, string $localName, MailFolder $folderRecord): void
    {
        $this->syncFolderPage($account, $accessToken, $graphFolderId, $localName, $folderRecord);
    }
}

class GraphMailSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private TestableGraphMailSyncService $service;

    private MailAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $refresher = Mockery::mock(OAuthTokenRefresher::class);
        $refresher->shouldReceive('freshAccessToken')->andReturn('graph-token')->byDefault();
        $this->service = new TestableGraphMailSyncService($refresher);

        $user = User::factory()->create();
        $this->account = MailAccount::factory()->outlook()->create(['user_id' => $user->id]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }

    private function graphMessage(array $overrides = []): array
    {
        return array_merge([
            'id' => 'msg-1',
            'subject' => 'Test subject',
            'from' => ['emailAddress' => ['address' => 'sender@example.com', 'name' => 'Sender Name']],
            'toRecipients' => [['emailAddress' => ['address' => 'me@outlook.com', 'name' => 'Me']]],
            'ccRecipients' => [],
            'receivedDateTime' => '2026-01-01T00:00:00Z',
            'isRead' => false,
            'conversationId' => 'conv-1',
            'internetMessageId' => '<msg-1@example.com>',
            'hasAttachments' => false,
        ], $overrides);
    }

    // --- storeMessage() ---------------------------------------------------

    public function test_inserts_a_new_message(): void
    {
        $this->service->callStoreMessage($this->account, 'INBOX', 'inbox-id', $this->graphMessage(), broadcastNew: false);

        $this->assertDatabaseHas('emails', [
            'mail_account_id' => $this->account->id,
            'folder' => 'INBOX',
            'uid' => 'msg-1',
            'remote_folder_path' => 'inbox-id',
            'subject' => 'Test subject',
            'from_address' => 'sender@example.com',
            'thread_id' => 'conv-1',
            'is_read' => false,
        ]);
    }

    public function test_does_not_duplicate_an_existing_message(): void
    {
        $this->service->callStoreMessage($this->account, 'INBOX', 'inbox-id', $this->graphMessage(['id' => 'msg-2']), broadcastNew: false);
        $this->service->callStoreMessage($this->account, 'INBOX', 'inbox-id', $this->graphMessage(['id' => 'msg-2']), broadcastNew: false);

        $this->assertSame(1, Email::where('mail_account_id', $this->account->id)->where('uid', 'msg-2')->count());
    }

    public function test_reconciles_read_flag_for_an_existing_message(): void
    {
        $this->service->callStoreMessage($this->account, 'INBOX', 'inbox-id', $this->graphMessage(['id' => 'msg-3', 'isRead' => false]), broadcastNew: false);
        $this->service->callStoreMessage($this->account, 'INBOX', 'inbox-id', $this->graphMessage(['id' => 'msg-3', 'isRead' => true]), broadcastNew: false);

        $this->assertDatabaseHas('emails', ['uid' => 'msg-3', 'is_read' => true]);
    }

    public function test_broadcasts_new_unread_inbox_message_when_broadcast_new_is_true(): void
    {
        Event::fake([NewEmailArrived::class]);

        $this->service->callStoreMessage($this->account, 'INBOX', 'inbox-id', $this->graphMessage(['id' => 'msg-4']), broadcastNew: true);

        $email = Email::where('mail_account_id', $this->account->id)->where('uid', 'msg-4')->firstOrFail();

        Event::assertDispatched(NewEmailArrived::class, fn (NewEmailArrived $e) => $e->emailId === $email->id);
    }

    public function test_does_not_broadcast_when_broadcast_new_is_false(): void
    {
        Event::fake([NewEmailArrived::class]);

        $this->service->callStoreMessage($this->account, 'INBOX', 'inbox-id', $this->graphMessage(['id' => 'msg-5']), broadcastNew: false);

        Event::assertNotDispatched(NewEmailArrived::class);
    }

    // --- syncFolderPage() / delta query ------------------------------------

    public function test_first_sync_uses_base_delta_url_and_stores_the_returned_link(): void
    {
        Http::fake([
            'https://graph.microsoft.com/v1.0/me/mailFolders/inbox-id/messages/delta*' => Http::response([
                'value' => [$this->graphMessage(['id' => 'msg-10'])],
                '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox-id/messages/delta?$deltatoken=abc',
            ]),
        ]);

        $folderRecord = MailFolder::create(['mail_account_id' => $this->account->id, 'local_name' => 'INBOX', 'remote_path' => 'inbox-id']);

        $this->service->callSyncFolderPage($this->account, 'graph-token', 'inbox-id', 'INBOX', $folderRecord);

        $this->assertDatabaseHas('emails', ['uid' => 'msg-10']);
        $this->assertSame(
            'https://graph.microsoft.com/v1.0/me/mailFolders/inbox-id/messages/delta?$deltatoken=abc',
            $folderRecord->fresh()->delta_link
        );

        Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), '$select=') && str_contains(urldecode($request->url()), '$top='));
    }

    public function test_replays_stored_delta_link_verbatim_without_adding_query_params(): void
    {
        $storedLink = 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox-id/messages/delta?$deltatoken=previous';

        Http::fake([
            'https://graph.microsoft.com/*/messages/delta*' => Http::response(['value' => [], '@odata.deltaLink' => $storedLink]),
        ]);

        $folderRecord = MailFolder::create([
            'mail_account_id' => $this->account->id,
            'local_name' => 'INBOX',
            'remote_path' => 'inbox-id',
            'delta_link' => $storedLink,
        ]);

        $this->service->callSyncFolderPage($this->account, 'graph-token', 'inbox-id', 'INBOX', $folderRecord);

        Http::assertSent(fn ($request) => $request->url() === $storedLink);
    }

    public function test_410_gone_clears_the_stored_delta_link(): void
    {
        $storedLink = 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox-id/messages/delta?$deltatoken=expired';

        Http::fake(['https://graph.microsoft.com/*/messages/delta*' => Http::response(['error' => ['code' => 'resyncRequired']], 410)]);

        $folderRecord = MailFolder::create([
            'mail_account_id' => $this->account->id,
            'local_name' => 'INBOX',
            'remote_path' => 'inbox-id',
            'delta_link' => $storedLink,
        ]);

        $this->service->callSyncFolderPage($this->account, 'graph-token', 'inbox-id', 'INBOX', $folderRecord);

        $this->assertNull($folderRecord->fresh()->delta_link);
    }

    public function test_broadcasts_only_once_the_folder_has_reached_a_real_delta_link(): void
    {
        Event::fake([NewEmailArrived::class]);

        // Http::fake() calls accumulate rather than replace within a test, so
        // a single sequenced fake (not two separate fake() calls) is needed
        // to give the two syncFolderPage() calls below different responses.
        Http::fakeSequence('https://graph.microsoft.com/*/messages/delta*')
            // First page ever (no stored link) -- still catching up on history, no broadcast.
            ->push([
                'value' => [$this->graphMessage(['id' => 'msg-20'])],
                '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox-id/messages/delta?$skiptoken=page2',
            ])
            // Folder has now genuinely caught up (delta_link has a deltatoken) --
            // the next new message should broadcast.
            ->push([
                'value' => [$this->graphMessage(['id' => 'msg-21'])],
                '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox-id/messages/delta?$deltatoken=next',
            ]);

        $folderRecord = MailFolder::create(['mail_account_id' => $this->account->id, 'local_name' => 'INBOX', 'remote_path' => 'inbox-id']);
        $this->service->callSyncFolderPage($this->account, 'graph-token', 'inbox-id', 'INBOX', $folderRecord);

        Event::assertNotDispatched(NewEmailArrived::class);

        $folderRecord->update(['delta_link' => 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox-id/messages/delta?$deltatoken=caught-up']);

        $this->service->callSyncFolderPage($this->account, 'graph-token', 'inbox-id', 'INBOX', $folderRecord);

        $this->assertDatabaseHas('emails', ['uid' => 'msg-21']);
        Event::assertDispatched(NewEmailArrived::class, fn (NewEmailArrived $e) => $e->subject === 'Test subject');
    }

    // --- sync() folder discovery --------------------------------------------

    public function test_sync_maps_well_known_folders_and_discovers_custom_folders(): void
    {
        Http::fake([
            'https://graph.microsoft.com/v1.0/me/mailFolders/inbox' => Http::response(['id' => 'inbox-id', 'displayName' => 'Inbox']),
            'https://graph.microsoft.com/v1.0/me/mailFolders/sentitems' => Http::response(['id' => 'sent-id', 'displayName' => 'Sent Items']),
            'https://graph.microsoft.com/v1.0/me/mailFolders/drafts' => Http::response(['id' => 'drafts-id', 'displayName' => 'Drafts']),
            'https://graph.microsoft.com/v1.0/me/mailFolders/deleteditems' => Http::response(['id' => 'trash-id', 'displayName' => 'Deleted Items']),
            'https://graph.microsoft.com/v1.0/me/mailFolders/junkemail' => Http::response(['id' => 'junk-id', 'displayName' => 'Junk Email']),
            'https://graph.microsoft.com/v1.0/me/mailFolders/archive' => Http::response([], 404),
            'https://graph.microsoft.com/v1.0/me/mailFolders/outbox' => Http::response([], 404),
            'https://graph.microsoft.com/v1.0/me/mailFolders/conversationhistory' => Http::response([], 404),
            'https://graph.microsoft.com/v1.0/me/mailFolders/clutter' => Http::response([], 404),
            'https://graph.microsoft.com/v1.0/me/mailFolders?*' => Http::response(['value' => [
                ['id' => 'inbox-id', 'displayName' => 'Inbox'],
                ['id' => 'sent-id', 'displayName' => 'Sent Items'],
                ['id' => 'drafts-id', 'displayName' => 'Drafts'],
                ['id' => 'trash-id', 'displayName' => 'Deleted Items'],
                ['id' => 'junk-id', 'displayName' => 'Junk Email'],
                ['id' => 'custom-id', 'displayName' => 'Projects'],
            ]]),
            'https://graph.microsoft.com/v1.0/me/mailFolders/*/messages/delta*' => Http::response(['value' => [], '@odata.deltaLink' => 'done']),
        ]);

        $this->service->sync($this->account);

        $this->assertDatabaseHas('mail_folders', ['mail_account_id' => $this->account->id, 'remote_path' => 'inbox-id', 'local_name' => 'INBOX']);
        $this->assertDatabaseHas('mail_folders', ['mail_account_id' => $this->account->id, 'remote_path' => 'sent-id', 'local_name' => 'SENT']);
        $this->assertDatabaseHas('mail_folders', ['mail_account_id' => $this->account->id, 'remote_path' => 'drafts-id', 'local_name' => 'DRAFTS']);
        $this->assertDatabaseHas('mail_folders', ['mail_account_id' => $this->account->id, 'remote_path' => 'trash-id', 'local_name' => 'TRASH']);
        $this->assertDatabaseHas('mail_folders', ['mail_account_id' => $this->account->id, 'remote_path' => 'custom-id', 'local_name' => 'Projects']);
        $this->assertDatabaseMissing('mail_folders', ['mail_account_id' => $this->account->id, 'remote_path' => 'junk-id']);
        $this->assertSame('idle', $this->account->fresh()->sync_status);
    }

    public function test_sync_sets_error_status_when_a_request_fails(): void
    {
        Http::fake([
            'https://graph.microsoft.com/v1.0/me/mailFolders/*' => Http::response(['error' => 'boom'], 500),
        ]);

        try {
            $this->service->sync($this->account);
        } catch (\Throwable) {
            // Expected -- sync() rethrows after recording the error.
        }

        $this->assertSame('error', $this->account->fresh()->sync_status);
        $this->assertNotNull($this->account->fresh()->sync_error);
    }

    // --- fetchBody() ---------------------------------------------------------

    public function test_fetch_body_sets_html_body(): void
    {
        $email = Email::factory()->create(['mail_account_id' => $this->account->id, 'uid' => 'msg-30', 'body_text' => null]);

        Http::fake([
            'https://graph.microsoft.com/v1.0/me/messages/msg-30*' => Http::response([
                'body' => ['contentType' => 'html', 'content' => '<p>Hello</p>'],
                'hasAttachments' => false,
            ]),
        ]);

        $this->service->fetchBody($email);

        $this->assertSame('<p>Hello</p>', $email->fresh()->body_html);
        $this->assertNull($email->fresh()->body_text);
    }

    public function test_fetch_body_downloads_attachments(): void
    {
        $email = Email::factory()->create(['mail_account_id' => $this->account->id, 'uid' => 'msg-31', 'body_text' => null]);

        Http::fake([
            'https://graph.microsoft.com/v1.0/me/messages/msg-31?*' => Http::response([
                'body' => ['contentType' => 'text', 'content' => 'plain body'],
                'hasAttachments' => true,
            ]),
            'https://graph.microsoft.com/v1.0/me/messages/msg-31/attachments' => Http::response(['value' => [
                [
                    '@odata.type' => '#microsoft.graph.fileAttachment',
                    'name' => 'file.txt',
                    'contentType' => 'text/plain',
                    'size' => 11,
                    'contentBytes' => base64_encode('hello world'),
                ],
            ]]),
        ]);

        $this->service->fetchBody($email);

        $this->assertSame(1, EmailAttachment::where('email_id', $email->id)->count());
        $this->assertDatabaseHas('email_attachments', ['email_id' => $email->id, 'filename' => 'file.txt']);
    }

    // --- applyAction() -------------------------------------------------------

    public function test_apply_action_mark_read_patches_is_read_true(): void
    {
        $email = Email::factory()->create(['mail_account_id' => $this->account->id, 'uid' => 'msg-40']);

        Http::fake(['https://graph.microsoft.com/v1.0/me/messages/msg-40' => Http::response([], 200)]);

        $this->service->applyAction($email, 'mark_read');

        Http::assertSent(fn ($request) => $request->method() === 'PATCH' && $request['isRead'] === true);
    }

    public function test_apply_action_delete_sends_delete_request(): void
    {
        $email = Email::factory()->create(['mail_account_id' => $this->account->id, 'uid' => 'msg-41']);

        Http::fake(['https://graph.microsoft.com/v1.0/me/messages/msg-41' => Http::response([], 204)]);

        $this->service->applyAction($email, 'delete');

        Http::assertSent(fn ($request) => $request->method() === 'DELETE');
    }

    public function test_apply_action_move_updates_uid_and_remote_folder_path(): void
    {
        $email = Email::factory()->create(['mail_account_id' => $this->account->id, 'uid' => 'msg-42', 'folder' => 'INBOX']);
        MailFolder::create(['mail_account_id' => $this->account->id, 'local_name' => 'Projects', 'remote_path' => 'projects-id']);

        Http::fake([
            'https://graph.microsoft.com/v1.0/me/messages/msg-42/move' => Http::response(['id' => 'msg-42-moved']),
        ]);

        $this->service->applyAction($email, 'move:Projects');

        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request['destinationId'] === 'projects-id');
        $this->assertSame('msg-42-moved', $email->fresh()->uid);
        $this->assertSame('projects-id', $email->fresh()->remote_folder_path);
    }
}
