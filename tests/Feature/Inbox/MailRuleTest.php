<?php

namespace Tests\Feature\Inbox;

use App\Events\NewEmailArrived;
use App\Jobs\DrainMirrorActionsJob;
use App\Models\Email;
use App\Models\MailAccount;
use App\Models\MailFolder;
use App\Models\MailRule;
use App\Models\MutedThread;
use App\Models\PendingMirrorAction;
use App\Models\User;
use App\Services\Mail\GraphMailSyncService;
use App\Services\Mail\OAuthTokenRefresher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

class MailRuleTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private MailAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        MailRule::forgetMemo();

        $this->user = User::factory()->create();
        $this->account = $this->outlookAccount($this->user);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }

    private function outlookAccount(User $user): MailAccount
    {
        $account = MailAccount::factory()->create([
            'user_id' => $user->id,
            'provider' => MailAccount::PROVIDER_OUTLOOK,
            'oauth_access_token' => 'token',
            'oauth_expires_at' => now()->addHour(),
        ]);

        MailFolder::create([
            'mail_account_id' => $account->id,
            'local_name' => 'INBOX',
            'remote_path' => 'inbox-folder-id',
            'delta_link' => 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox-folder-id/messages/delta?$deltatoken=abc',
        ]);

        return $account;
    }

    private function folder(string $name, ?MailAccount $account = null): MailFolder
    {
        return MailFolder::create([
            'mail_account_id' => ($account ?? $this->account)->id,
            'local_name' => $name,
            'remote_path' => 'remote-'.$name,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function graphMessage(string $uid, string $address, array $overrides = []): array
    {
        return $overrides + [
            'id' => $uid,
            'subject' => 'Hello',
            'from' => ['emailAddress' => ['address' => $address, 'name' => 'Sender']],
            'toRecipients' => [['emailAddress' => ['address' => 'me@example.com']]],
            'receivedDateTime' => '2026-10-06T10:00:00Z',
            'isRead' => false,
            'conversationId' => "thread-{$uid}",
            'internetMessageId' => "<{$uid}@example.com>",
        ];
    }

    /** @param list<array<string, mixed>> $messages */
    private function syncMessages(array $messages, ?MailAccount $account = null): void
    {
        Http::swap(new Factory);
        Http::fake([
            '*/messages/delta*' => Http::response([
                'value' => $messages,
                '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox-folder-id/messages/delta?$deltatoken=next',
            ], 200),
            '*/me/mailFolders/inbox' => Http::response(['id' => 'inbox-folder-id', 'displayName' => 'Inbox'], 200),
            '*/me/mailFolders/*' => Http::response([], 404),
            '*/me/mailFolders*' => Http::response(['value' => []], 200),
            '*' => Http::response([], 200),
        ]);

        $refresher = Mockery::mock(OAuthTokenRefresher::class);
        $refresher->shouldReceive('freshAccessToken')->andReturn('token');
        (new GraphMailSyncService($refresher))->sync($account ?? $this->account);
    }

    private function syncFrom(string $address, string $uid = 'new-1'): void
    {
        $this->syncMessages([$this->graphMessage($uid, $address)]);
    }

    /** @param array<string, mixed> $attributes */
    private function rule(string $operator, string $value, ?int $accountId = null, array $attributes = []): MailRule
    {
        return MailRule::create($attributes + [
            'user_id' => $this->user->id,
            'mail_account_id' => $accountId,
            'position' => (int) MailRule::where('user_id', $this->user->id)->max('position') + 1,
            'field' => 'from',
            'operator' => $operator,
            'value' => $value,
            'action' => 'archive',
        ]);
    }

    private function synced(string $uid = 'new-1'): Email
    {
        return Email::where('message_id', "<{$uid}@example.com>")->sole();
    }

    public function test_an_address_rule_archives_new_mail_on_arrival(): void
    {
        $rule = $this->rule('is', 'noreply@noisy.test');

        $this->syncFrom('NoReply@Noisy.test');

        $email = $this->synced();
        $this->assertTrue($email->is_archived);
        $this->assertSame($rule->id, $email->archived_by_rule_id);
    }

    public function test_a_domain_rule_matches_the_exact_domain(): void
    {
        $this->rule('domain', 'noisy.test');

        $this->syncFrom('a@noisy.test');

        $this->assertTrue($this->synced()->is_archived);
    }

    public function test_a_domain_rule_does_not_match_a_subdomain_or_lookalike(): void
    {
        $this->rule('domain', 'noisy.test');

        $this->syncMessages([
            $this->graphMessage('sub', 'a@mail.noisy.test'),
            $this->graphMessage('look', 'b@notnoisy.test'),
        ]);

        $this->assertFalse($this->synced('sub')->is_archived);
        $this->assertFalse($this->synced('look')->is_archived);
    }

    public function test_a_subject_rule_matches_case_insensitively(): void
    {
        $this->rule('contains', 'WEEKLY digest', attributes: ['field' => 'subject']);

        $this->syncMessages([$this->graphMessage('a', 'x@y.test', ['subject' => 'Your weekly Digest is here'])]);

        $this->assertTrue($this->synced('a')->is_archived);
    }

    public function test_a_recipient_rule_matches_to_and_cc(): void
    {
        $this->rule('is', 'list@club.test', attributes: ['field' => 'to']);

        $this->syncMessages([
            $this->graphMessage('to', 'x@y.test', ['toRecipients' => [['emailAddress' => ['address' => 'List@Club.test']]]]),
            $this->graphMessage('cc', 'x@y.test', ['ccRecipients' => [['emailAddress' => ['address' => 'list@club.test']]]]),
            $this->graphMessage('none', 'x@y.test'),
        ]);

        $this->assertTrue($this->synced('to')->is_archived);
        $this->assertTrue($this->synced('cc')->is_archived);
        $this->assertFalse($this->synced('none')->is_archived);
    }

    public function test_the_star_action_stars_the_message_and_mirrors_it(): void
    {
        $this->rule('is', 'boss@work.test', attributes: ['action' => 'star']);

        $this->syncFrom('boss@work.test');

        $email = $this->synced();
        $this->assertTrue($email->is_starred);
        $this->assertFalse($email->is_archived);
        $this->assertDatabaseHas('pending_mirror_actions', ['email_id' => $email->id, 'action' => 'star', 'uid' => 'new-1']);
    }

    public function test_the_star_action_does_not_mirror_a_message_the_server_already_starred(): void
    {
        $this->rule('is', 'boss@work.test', attributes: ['action' => 'star']);

        $this->syncMessages([$this->graphMessage('a', 'boss@work.test', ['flag' => ['flagStatus' => 'flagged']])]);

        $this->assertTrue($this->synced('a')->is_starred);
        $this->assertDatabaseCount('pending_mirror_actions', 0);
    }

    public function test_the_mark_read_action_reads_mirrors_and_stays_silent(): void
    {
        Event::fake([NewEmailArrived::class]);
        $this->rule('is', 'bot@ci.test', attributes: ['action' => 'mark_read']);

        $this->syncFrom('bot@ci.test');

        $email = $this->synced();
        $this->assertTrue($email->is_read);
        $this->assertDatabaseHas('pending_mirror_actions', ['email_id' => $email->id, 'action' => 'mark_read']);
        Event::assertNotDispatched(NewEmailArrived::class);
    }

    public function test_the_file_action_moves_the_message_and_mirrors_the_move_from_the_inbox(): void
    {
        $this->folder('Shops');
        $this->rule('domain', 'shop.test', $this->account->id, ['action' => 'file', 'folder' => 'Shops']);

        $this->syncFrom('orders@shop.test');

        $email = $this->synced('new-1');
        $this->assertSame('Shops', $email->folder);
        $this->assertNull($email->uid);
        $this->assertDatabaseHas('pending_mirror_actions', [
            'email_id' => $email->id,
            'action' => 'move:Shops',
            'uid' => 'new-1',
            'remote_folder_path' => 'inbox-folder-id',
        ]);
        Queue::assertPushed(DrainMirrorActionsJob::class, 1);
    }

    public function test_filing_into_a_folder_that_no_longer_exists_leaves_the_message_in_the_inbox(): void
    {
        $this->rule('domain', 'shop.test', $this->account->id, ['action' => 'file', 'folder' => 'Gone']);

        $this->syncFrom('orders@shop.test');

        $this->assertSame('INBOX', $this->synced()->folder);
        $this->assertDatabaseCount('pending_mirror_actions', 0);
    }

    public function test_a_folder_that_belongs_to_another_account_is_not_a_target(): void
    {
        $second = $this->outlookAccount($this->user);
        $this->folder('Shops', $second);
        $this->rule('domain', 'shop.test', $this->account->id, ['action' => 'file', 'folder' => 'Shops']);

        $this->syncFrom('orders@shop.test');

        $this->assertSame('INBOX', $this->synced()->folder);
    }

    public function test_a_file_rule_can_never_target_trash_or_the_inbox(): void
    {
        $this->folder('TRASH');
        $this->rule('domain', 'shop.test', $this->account->id, ['action' => 'file', 'folder' => 'TRASH']);

        $this->syncFrom('orders@shop.test');

        $email = $this->synced();
        $this->assertSame('INBOX', $email->folder);
        $this->assertFalse($email->is_deleted);
        $this->assertDatabaseCount('pending_mirror_actions', 0);
    }

    public function test_every_matching_rule_applies_in_position_order_and_the_first_folder_wins(): void
    {
        $this->folder('First');
        $this->folder('Second');
        $this->rule('contains', 'shop', $this->account->id, ['field' => 'subject', 'action' => 'file', 'folder' => 'Second', 'position' => 5]);
        $this->rule('contains', 'shop', $this->account->id, ['field' => 'subject', 'action' => 'file', 'folder' => 'First', 'position' => 1]);
        $this->rule('contains', 'shop', attributes: ['field' => 'subject', 'action' => 'star', 'position' => 3]);

        $this->syncMessages([$this->graphMessage('a', 'x@y.test', ['subject' => 'Shop order'])]);

        $email = $this->synced('a');
        $this->assertSame('First', $email->folder);
        $this->assertTrue($email->is_starred);
    }

    public function test_a_position_tie_is_broken_by_rule_id(): void
    {
        $this->folder('A');
        $this->folder('B');
        $this->rule('contains', 'x', $this->account->id, ['action' => 'file', 'folder' => 'A', 'position' => 1]);
        $this->rule('contains', 'x', $this->account->id, ['action' => 'file', 'folder' => 'B', 'position' => 1]);

        $this->syncFrom('x@y.test');

        $this->assertSame('A', $this->synced()->folder);
    }

    public function test_stop_on_match_prevents_later_rules(): void
    {
        $this->rule('contains', 'y.test', attributes: ['action' => 'star', 'stop_on_match' => true]);
        $this->rule('contains', 'y.test', attributes: ['action' => 'archive']);

        $this->syncFrom('x@y.test');

        $email = $this->synced();
        $this->assertTrue($email->is_starred);
        $this->assertFalse($email->is_archived);
    }

    public function test_a_rule_that_matches_nothing_is_harmless(): void
    {
        $this->rule('contains', 'zzz-never');

        $this->syncFrom('human@example.com');

        $email = $this->synced();
        $this->assertFalse($email->is_archived);
        $this->assertFalse($email->is_starred);
        $this->assertSame('INBOX', $email->folder);
        $this->assertNull($email->archived_by_rule_id);
    }

    public function test_a_broken_rule_does_not_fail_the_sync_or_stop_the_others(): void
    {
        DB::table('mail_rules')->insert([
            'user_id' => $this->user->id, 'mail_account_id' => null, 'position' => 1,
            'field' => 'from', 'operator' => 'contains', 'value' => 'noisy', 'action' => 'explode',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->rule('contains', 'noisy');

        $this->syncFrom('a@noisy.test');

        $this->assertTrue($this->synced()->is_archived);
    }

    public function test_the_rule_set_is_loaded_once_per_run_not_per_message(): void
    {
        $this->rule('contains', 'noisy');
        $this->rule('contains', 'other', attributes: ['action' => 'star']);

        DB::enableQueryLog();
        $this->syncMessages(array_map(
            fn (int $i): array => $this->graphMessage("m{$i}", "a{$i}@noisy.test"),
            range(1, 12),
        ));
        $queries = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql): bool => str_contains($sql, 'from "mail_rules"'));

        $this->assertCount(1, $queries);
        $this->assertSame(12, Email::where('is_archived', true)->count());
    }

    public function test_a_burst_of_mirrored_actions_schedules_one_drain(): void
    {
        $this->rule('contains', 'noisy', attributes: ['action' => 'star']);

        $this->syncMessages(array_map(
            fn (int $i): array => $this->graphMessage("m{$i}", "a{$i}@noisy.test"),
            range(1, 12),
        ));

        $this->assertSame(12, PendingMirrorAction::where('action', 'star')->count());
        Queue::assertPushed(DrainMirrorActionsJob::class, 1);
    }

    public function test_a_resynced_message_is_not_acted_on_again_after_the_user_changed_it(): void
    {
        $this->rule('is', 'noreply@noisy.test');
        $this->syncFrom('noreply@noisy.test');

        $this->synced()->update(['is_archived' => false, 'archived_by_rule_id' => null]);
        $this->syncFrom('noreply@noisy.test');

        $this->assertFalse($this->synced()->is_archived);
        $this->assertSame(1, Email::count());
    }

    public function test_a_message_the_user_filed_by_hand_is_not_pulled_back_by_a_later_rule(): void
    {
        $this->syncFrom('noreply@noisy.test');
        $this->synced()->update(['is_archived' => false]);

        $this->rule('is', 'noreply@noisy.test');
        $this->syncFrom('noreply@noisy.test');

        $this->assertFalse($this->synced()->is_archived);
    }

    public function test_a_rule_for_another_account_does_not_apply(): void
    {
        $other = MailAccount::factory()->create(['user_id' => $this->user->id]);
        $this->rule('is', 'noreply@noisy.test', $other->id);

        $this->syncFrom('noreply@noisy.test');

        $this->assertFalse($this->synced()->is_archived);
    }

    public function test_a_rule_for_a_removed_account_matches_nothing_rather_than_everything(): void
    {
        $this->rule('is', 'noreply@noisy.test', 99999);

        $this->syncFrom('noreply@noisy.test');

        $this->assertFalse($this->synced()->is_archived);
    }

    public function test_an_all_accounts_rule_applies_to_each_of_the_users_accounts(): void
    {
        $second = $this->outlookAccount($this->user);
        $this->rule('contains', 'noisy');

        $this->syncFrom('a@noisy.test', 'one');
        $this->syncMessages([$this->graphMessage('two', 'a@noisy.test')], $second);

        $this->assertTrue($this->synced('one')->is_archived);
        $this->assertTrue($this->synced('two')->is_archived);
    }

    public function test_another_users_rule_never_acts_on_this_users_mail(): void
    {
        $stranger = User::factory()->create();
        $strangerAccount = $this->outlookAccount($stranger);
        $this->folder('Shops');
        foreach (['archive', 'star', 'mark_read', 'file'] as $action) {
            MailRule::create([
                'user_id' => $stranger->id, 'mail_account_id' => null, 'position' => 1, 'field' => 'from',
                'operator' => 'contains', 'value' => 'noisy', 'action' => $action, 'folder' => $action === 'file' ? 'Shops' : null,
            ]);
        }
        MailRule::create([
            'user_id' => $stranger->id, 'mail_account_id' => $strangerAccount->id, 'position' => 1, 'field' => 'from',
            'operator' => 'contains', 'value' => 'noisy', 'action' => 'archive',
        ]);

        $this->syncFrom('a@noisy.test');

        $email = $this->synced();
        $this->assertFalse($email->is_archived);
        $this->assertFalse($email->is_starred);
        $this->assertFalse($email->is_read);
        $this->assertSame('INBOX', $email->folder);
        $this->assertDatabaseCount('pending_mirror_actions', 0);
    }

    public function test_a_muted_thread_is_left_to_the_mute(): void
    {
        MutedThread::create(['mail_account_id' => $this->account->id, 'thread_id' => 'thread-new-1']);
        $this->rule('contains', 'noisy', attributes: ['action' => 'star']);

        $this->syncFrom('a@noisy.test');

        $email = $this->synced();
        $this->assertTrue($email->is_archived);
        $this->assertFalse($email->is_starred);
    }

    public function test_a_rule_archived_message_does_not_broadcast(): void
    {
        Event::fake([NewEmailArrived::class]);
        $this->rule('is', 'noreply@noisy.test');

        $this->syncFrom('noreply@noisy.test');

        Event::assertNotDispatched(NewEmailArrived::class);
    }

    public function test_a_rule_filed_message_does_not_broadcast(): void
    {
        Event::fake([NewEmailArrived::class]);
        $this->folder('Shops');
        $this->rule('contains', 'shop', $this->account->id, ['action' => 'file', 'folder' => 'Shops']);

        $this->syncFrom('a@shop.test');

        Event::assertNotDispatched(NewEmailArrived::class);
    }

    public function test_a_starred_only_message_still_broadcasts(): void
    {
        Event::fake([NewEmailArrived::class]);
        $this->rule('contains', 'boss', attributes: ['action' => 'star']);

        $this->syncFrom('boss@work.test');

        Event::assertDispatched(NewEmailArrived::class);
    }

    public function test_the_rules_page_lists_rules_in_order_and_filed_mail_and_undo_puts_it_back(): void
    {
        $second = $this->rule('contains', 'second-rule-pattern', attributes: ['position' => 2]);
        $this->rule('contains', 'first-rule-pattern', attributes: ['position' => 1]);
        $email = Email::factory()->create([
            'mail_account_id' => $this->account->id,
            'from_address' => 'noreply@noisy.test',
            'subject' => 'Weekly noise',
            'is_archived' => true,
            'archived_by_rule_id' => $second->id,
        ]);

        $this->actingAs($this->user)->get(route('mailRules.index'))
            ->assertOk()
            ->assertSeeInOrder(['first-rule-pattern', 'second-rule-pattern'])
            ->assertSee('Weekly noise');

        $this->actingAs($this->user)->post(route('mailRules.undo', $email))->assertRedirect();

        $email->refresh();
        $this->assertFalse($email->is_archived);
        $this->assertNull($email->archived_by_rule_id);
    }

    public function test_undo_refuses_mail_no_rule_archived(): void
    {
        $email = Email::factory()->create(['mail_account_id' => $this->account->id, 'is_archived' => true]);

        $this->actingAs($this->user)->post(route('mailRules.undo', $email))->assertNotFound();
    }

    public function test_the_old_url_redirects_to_the_rules_page(): void
    {
        $this->actingAs($this->user)->get('/archive-rules')->assertRedirect(route('mailRules.index'));
    }

    /** @param array<string, mixed> $data */
    private function store(array $data): TestResponse
    {
        return $this->actingAs($this->user)->post(route('mailRules.store'), $data);
    }

    public function test_a_rule_is_created_trimmed_lowercased_and_appended_last(): void
    {
        $this->rule('contains', 'existing');

        $this->store(['field' => 'from', 'operator' => 'domain', 'value' => ' Noisy.TEST ', 'action' => 'archive'])
            ->assertSessionHas('status');

        $this->assertDatabaseHas('mail_rules', ['operator' => 'domain', 'value' => 'noisy.test', 'position' => 2, 'user_id' => $this->user->id]);
    }

    public function test_a_pattern_that_grows_when_lowercased_is_rejected_not_truncated(): void
    {
        $this->store(['field' => 'subject', 'operator' => 'contains', 'value' => str_repeat("\u{0130}", 255), 'action' => 'archive'])
            ->assertSessionHasErrors('value');

        $this->assertDatabaseCount('mail_rules', 0);
    }

    public function test_an_invisible_only_pattern_is_rejected(): void
    {
        $this->store(['field' => 'subject', 'operator' => 'contains', 'value' => "\u{200B}\u{FEFF}", 'action' => 'archive'])
            ->assertSessionHasErrors('value');
    }

    public function test_saving_the_same_rule_twice_keeps_one(): void
    {
        $data = ['field' => 'from', 'operator' => 'is', 'value' => 'a@noisy.test', 'action' => 'archive'];

        $this->store($data);
        $this->store([...$data, 'value' => 'A@Noisy.test']);

        $this->assertDatabaseCount('mail_rules', 1);
    }

    public function test_a_folder_must_be_named_exactly_as_it_is(): void
    {
        $this->folder('Work');

        $this->store(['field' => 'from', 'operator' => 'contains', 'value' => 'x', 'action' => 'file', 'folder' => 'WORK', 'mail_account_id' => $this->account->id])
            ->assertSessionHasErrors('folder');
    }

    public function test_undo_refuses_mail_that_has_since_been_deleted_or_moved(): void
    {
        $rule = $this->rule('contains', 'x');
        $deleted = Email::factory()->create(['mail_account_id' => $this->account->id, 'is_archived' => true, 'archived_by_rule_id' => $rule->id, 'is_deleted' => true]);
        $moved = Email::factory()->create(['mail_account_id' => $this->account->id, 'is_archived' => true, 'archived_by_rule_id' => $rule->id, 'folder' => 'TRASH']);

        $this->actingAs($this->user)->post(route('mailRules.undo', $deleted))->assertNotFound();
        $this->actingAs($this->user)->post(route('mailRules.undo', $moved))->assertNotFound();
    }

    public function test_a_rule_deleted_mid_run_stops_acting_once_the_memo_is_a_minute_old(): void
    {
        $rule = $this->rule('contains', 'noisy');
        $this->syncMessages([$this->graphMessage('first', 'a@noisy.test')]);
        $this->assertTrue($this->synced('first')->is_archived);

        DB::table('mail_rules')->where('id', $rule->id)->delete();
        $this->travel(2)->minutes();
        $this->syncMessages([$this->graphMessage('second', 'a@noisy.test')]);

        $this->assertFalse($this->synced('second')->is_archived);
    }

    public function test_a_filed_message_that_could_not_be_moved_still_notifies(): void
    {
        Event::fake([NewEmailArrived::class]);
        $this->folder('Shops');
        $this->rule('contains', 'shop', $this->account->id, ['action' => 'file', 'folder' => 'Shops']);

        $this->syncMessages([$this->graphMessage('', 'a@shop.test', ['internetMessageId' => '<no-uid@example.com>'])]);

        $this->assertSame('INBOX', Email::where('message_id', '<no-uid@example.com>')->sole()->folder);
        Event::assertDispatched(NewEmailArrived::class);
    }

    public function test_regex_like_input_is_stored_as_literal_text(): void
    {
        $this->store(['field' => 'subject', 'operator' => 'contains', 'value' => '(a+)+$', 'action' => 'archive'])
            ->assertSessionHas('status');

        $this->assertDatabaseHas('mail_rules', ['value' => '(a+)+$']);
    }

    public function test_empty_whitespace_and_oversized_patterns_are_rejected(): void
    {
        foreach (['', '   ', "\t\n  ", str_repeat('a', 256)] as $value) {
            $this->store(['field' => 'subject', 'operator' => 'contains', 'value' => $value, 'action' => 'archive'])
                ->assertSessionHasErrors('value');
        }

        $this->assertDatabaseCount('mail_rules', 0);
    }

    public function test_unknown_field_operator_and_action_are_rejected(): void
    {
        $base = ['field' => 'from', 'operator' => 'contains', 'value' => 'x', 'action' => 'archive'];

        $this->store([...$base, 'field' => 'body'])->assertSessionHasErrors('field');
        $this->store([...$base, 'operator' => 'regex'])->assertSessionHasErrors('operator');
        $this->store([...$base, 'action' => 'delete'])->assertSessionHasErrors('action');
        $this->assertDatabaseCount('mail_rules', 0);
    }

    public function test_an_address_or_domain_rule_has_to_look_like_one(): void
    {
        $this->store(['field' => 'from', 'operator' => 'is', 'value' => 'not-an-address', 'action' => 'archive'])->assertSessionHas('error');
        $this->store(['field' => 'from', 'operator' => 'domain', 'value' => 'no spaces.test', 'action' => 'archive'])->assertSessionHas('error');
        $this->store(['field' => 'subject', 'operator' => 'domain', 'value' => 'noisy.test', 'action' => 'archive'])->assertSessionHasErrors('operator');

        $this->assertDatabaseCount('mail_rules', 0);
    }

    public function test_a_file_rule_needs_an_account_and_one_of_that_accounts_folders(): void
    {
        $this->folder('Shops');
        $data = ['field' => 'from', 'operator' => 'contains', 'value' => 'shop', 'action' => 'file', 'folder' => 'Shops'];

        $this->store($data)->assertSessionHasErrors('mail_account_id');
        $this->store([...$data, 'mail_account_id' => $this->account->id, 'folder' => 'Nope'])->assertSessionHasErrors('folder');
        $this->store([...$data, 'mail_account_id' => $this->account->id, 'folder' => 'TRASH'])->assertSessionHasErrors('folder');
        $this->store([...$data, 'mail_account_id' => $this->account->id, 'folder' => 'INBOX'])->assertSessionHasErrors('folder');
        $this->assertDatabaseCount('mail_rules', 0);

        $this->store([...$data, 'mail_account_id' => $this->account->id])->assertSessionHas('status');
        $this->assertDatabaseHas('mail_rules', ['action' => 'file', 'folder' => 'Shops', 'mail_account_id' => $this->account->id]);
    }

    public function test_a_folder_of_another_users_account_cannot_be_targeted(): void
    {
        $stranger = User::factory()->create();
        $theirs = $this->outlookAccount($stranger);
        $this->folder('Secret', $theirs);

        $this->store(['field' => 'from', 'operator' => 'contains', 'value' => 'x', 'action' => 'file', 'folder' => 'Secret', 'mail_account_id' => $theirs->id])
            ->assertSessionHasErrors('mail_account_id');
        $this->store(['field' => 'from', 'operator' => 'contains', 'value' => 'x', 'action' => 'file', 'folder' => 'Secret', 'mail_account_id' => $this->account->id])
            ->assertSessionHasErrors('folder');

        $this->assertDatabaseCount('mail_rules', 0);
    }

    public function test_the_folder_is_ignored_for_actions_that_do_not_file(): void
    {
        $this->store(['field' => 'from', 'operator' => 'contains', 'value' => 'x', 'action' => 'star', 'folder' => 'Whatever'])
            ->assertSessionHas('status');

        $this->assertDatabaseHas('mail_rules', ['action' => 'star', 'folder' => null]);
    }

    public function test_a_rule_can_be_moved_up_and_down_and_positions_are_renumbered(): void
    {
        $a = $this->rule('contains', 'a', attributes: ['position' => 4]);
        $b = $this->rule('contains', 'b', attributes: ['position' => 9]);
        $c = $this->rule('contains', 'c', attributes: ['position' => 9]);

        $this->actingAs($this->user)->post(route('mailRules.move', $c), ['direction' => 'up'])->assertRedirect();

        $this->assertSame([$a->id, $c->id, $b->id], MailRule::where('user_id', $this->user->id)->orderBy('position')->pluck('id')->all());
        $this->assertSame([1, 2, 3], MailRule::where('user_id', $this->user->id)->orderBy('position')->pluck('position')->all());

        $this->actingAs($this->user)->post(route('mailRules.move', $a), ['direction' => 'up'])->assertRedirect();
        $this->actingAs($this->user)->post(route('mailRules.move', $b), ['direction' => 'down'])->assertRedirect();
        $this->assertSame([$a->id, $c->id, $b->id], MailRule::where('user_id', $this->user->id)->orderBy('position')->pluck('id')->all());

        $this->actingAs($this->user)->post(route('mailRules.move', $a), ['direction' => 'down'])->assertRedirect();
        $this->assertSame([$c->id, $a->id, $b->id], MailRule::where('user_id', $this->user->id)->orderBy('position')->pluck('id')->all());
    }

    public function test_moving_a_rule_takes_effect_on_the_next_sync(): void
    {
        $this->folder('First');
        $this->folder('Second');
        $first = $this->rule('contains', 'x', $this->account->id, ['action' => 'file', 'folder' => 'First']);
        $second = $this->rule('contains', 'x', $this->account->id, ['action' => 'file', 'folder' => 'Second']);

        $this->actingAs($this->user)->post(route('mailRules.move', $second), ['direction' => 'up']);
        $this->syncFrom('x@y.test');

        $this->assertSame('Second', $this->synced()->folder);
        $this->assertNotNull($first->fresh());
    }

    public function test_deleting_a_rule_keeps_the_order_dense(): void
    {
        $a = $this->rule('contains', 'a');
        $b = $this->rule('contains', 'b');
        $c = $this->rule('contains', 'c');

        $this->actingAs($this->user)->delete(route('mailRules.destroy', $b))->assertRedirect();

        $this->assertSame([1, 2], MailRule::orderBy('position')->pluck('position')->all());
        $this->assertSame([$a->id, $c->id], MailRule::orderBy('position')->pluck('id')->all());
    }

    public function test_users_cannot_touch_each_others_rules_or_mail(): void
    {
        $rule = $this->rule('is', 'noreply@noisy.test');
        $email = Email::factory()->create(['mail_account_id' => $this->account->id, 'is_archived' => true]);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->delete(route('mailRules.destroy', $rule))->assertNotFound();
        $this->actingAs($stranger)->post(route('mailRules.move', $rule), ['direction' => 'down'])->assertNotFound();
        $this->actingAs($stranger)->post(route('mailRules.undo', $email))->assertNotFound();
        $this->actingAs($stranger)
            ->post(route('mailRules.store'), ['field' => 'from', 'operator' => 'domain', 'value' => 'x.test', 'action' => 'archive', 'mail_account_id' => $this->account->id])
            ->assertSessionHasErrors('mail_account_id');
        $this->assertNotNull($rule->fresh());
    }

    public function test_the_rules_page_never_lists_another_users_rules(): void
    {
        $this->rule('contains', 'mine-pattern');
        MailRule::create(['user_id' => User::factory()->create()->id, 'position' => 1, 'field' => 'from', 'operator' => 'contains', 'value' => 'theirs-pattern', 'action' => 'archive']);

        $this->actingAs($this->user)->get(route('mailRules.index'))
            ->assertSee('mine-pattern')
            ->assertDontSee('theirs-pattern');
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get(route('mailRules.index'))->assertRedirect();
    }
}
