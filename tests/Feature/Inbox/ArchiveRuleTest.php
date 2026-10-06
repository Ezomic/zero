<?php

namespace Tests\Feature\Inbox;

use App\Events\NewEmailArrived;
use App\Models\ArchiveRule;
use App\Models\Email;
use App\Models\MailAccount;
use App\Models\MailFolder;
use App\Models\User;
use App\Services\Mail\GraphMailSyncService;
use App\Services\Mail\OAuthTokenRefresher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class ArchiveRuleTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private MailAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        ArchiveRule::forgetMemo();

        $this->user = User::factory()->create();
        $this->account = MailAccount::factory()->create([
            'user_id' => $this->user->id,
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
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }

    private function syncFrom(string $address, string $uid = 'new-1'): void
    {
        Http::fake([
            '*/messages/delta*' => Http::response([
                'value' => [[
                    'id' => $uid,
                    'subject' => 'Hello',
                    'from' => ['emailAddress' => ['address' => $address]],
                    'receivedDateTime' => '2026-10-06T10:00:00Z',
                    'isRead' => false,
                    'conversationId' => "thread-{$uid}",
                    'internetMessageId' => "<{$uid}@example.com>",
                ]],
                '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox-folder-id/messages/delta?$deltatoken=next',
            ], 200),
            '*/me/mailFolders/inbox' => Http::response(['id' => 'inbox-folder-id', 'displayName' => 'Inbox'], 200),
            '*/me/mailFolders/*' => Http::response([], 404),
            '*/me/mailFolders*' => Http::response(['value' => []], 200),
            '*' => Http::response([], 200),
        ]);

        $refresher = Mockery::mock(OAuthTokenRefresher::class);
        $refresher->shouldReceive('freshAccessToken')->andReturn('token');
        (new GraphMailSyncService($refresher))->sync($this->account);
    }

    private function rule(string $kind, string $value, ?int $accountId = null): ArchiveRule
    {
        return ArchiveRule::create([
            'user_id' => $this->user->id,
            'mail_account_id' => $accountId,
            'kind' => $kind,
            'value' => $value,
        ]);
    }

    public function test_an_address_rule_archives_new_mail_on_arrival(): void
    {
        $rule = $this->rule('address', 'noreply@noisy.test');

        $this->syncFrom('NoReply@Noisy.test');

        $email = Email::where('uid', 'new-1')->sole();
        $this->assertTrue($email->is_archived);
        $this->assertSame($rule->id, $email->archived_by_rule_id);
    }

    public function test_a_domain_rule_matches_its_subdomains(): void
    {
        $this->rule('domain', 'noisy.test');

        $this->syncFrom('a@mail.noisy.test');

        $this->assertTrue(Email::where('uid', 'new-1')->sole()->is_archived);
    }

    public function test_a_domain_rule_does_not_match_a_lookalike_domain(): void
    {
        $this->rule('domain', 'noisy.test');

        $this->syncFrom('b@notnoisy.test');

        $this->assertFalse(Email::where('uid', 'new-1')->sole()->is_archived);
    }

    public function test_unmatched_mail_stays_in_the_inbox(): void
    {
        $this->rule('address', 'noreply@noisy.test');

        $this->syncFrom('a-human@example.com');

        $email = Email::where('uid', 'new-1')->sole();
        $this->assertFalse($email->is_archived);
        $this->assertNull($email->archived_by_rule_id);
    }

    public function test_a_rule_for_another_account_does_not_apply(): void
    {
        $other = MailAccount::factory()->create(['user_id' => $this->user->id]);
        $this->rule('address', 'noreply@noisy.test', $other->id);

        $this->syncFrom('noreply@noisy.test');

        $this->assertFalse(Email::where('uid', 'new-1')->sole()->is_archived);
    }

    public function test_another_users_rule_does_not_apply(): void
    {
        ArchiveRule::create([
            'user_id' => User::factory()->create()->id,
            'kind' => 'address',
            'value' => 'noreply@noisy.test',
        ]);

        $this->syncFrom('noreply@noisy.test');

        $this->assertFalse(Email::where('uid', 'new-1')->sole()->is_archived);
    }

    public function test_a_rule_archived_message_does_not_broadcast(): void
    {
        Event::fake([NewEmailArrived::class]);
        $this->rule('address', 'noreply@noisy.test');

        $this->syncFrom('noreply@noisy.test');

        Event::assertNotDispatched(NewEmailArrived::class);
    }

    public function test_the_rules_page_lists_filed_mail_and_undo_puts_it_back(): void
    {
        $rule = $this->rule('address', 'noreply@noisy.test');
        $email = Email::factory()->create([
            'mail_account_id' => $this->account->id,
            'from_address' => 'noreply@noisy.test',
            'subject' => 'Weekly noise',
            'is_archived' => true,
            'archived_by_rule_id' => $rule->id,
        ]);

        $this->actingAs($this->user)->get(route('archiveRules.index'))
            ->assertOk()
            ->assertSee('Weekly noise');

        $this->actingAs($this->user)->post(route('archiveRules.undo', $email))->assertRedirect();

        $email->refresh();
        $this->assertFalse($email->is_archived);
        $this->assertNull($email->archived_by_rule_id);
    }

    public function test_a_rule_is_created_normalised_and_validated(): void
    {
        $this->actingAs($this->user)
            ->post(route('archiveRules.store'), ['kind' => 'domain', 'value' => ' Noisy.TEST '])
            ->assertSessionHas('status');

        $this->assertDatabaseHas('archive_rules', ['kind' => 'domain', 'value' => 'noisy.test']);

        $this->actingAs($this->user)
            ->post(route('archiveRules.store'), ['kind' => 'address', 'value' => 'not-an-address'])
            ->assertSessionHas('error');

        $this->assertDatabaseCount('archive_rules', 1);
    }

    public function test_users_cannot_touch_each_others_rules_or_mail(): void
    {
        $rule = $this->rule('address', 'noreply@noisy.test');
        $email = Email::factory()->create(['mail_account_id' => $this->account->id, 'is_archived' => true]);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->delete(route('archiveRules.destroy', $rule))->assertNotFound();
        $this->actingAs($stranger)->post(route('archiveRules.undo', $email))->assertNotFound();
        $this->actingAs($stranger)
            ->post(route('archiveRules.store'), ['kind' => 'domain', 'value' => 'x.test', 'mail_account_id' => $this->account->id])
            ->assertSessionHasErrors('mail_account_id');
    }
}
