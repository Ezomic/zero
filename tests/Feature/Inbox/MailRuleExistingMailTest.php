<?php

namespace Tests\Feature\Inbox;

use App\Actions\Mail\ApplyRuleOutcome;
use App\Actions\Mail\ApplyRuleToExistingMail;
use App\Jobs\DrainMirrorActionsJob;
use App\Models\Email;
use App\Models\MailAccount;
use App\Models\MailFolder;
use App\Models\MailRule;
use App\Models\PendingMirrorAction;
use App\Models\User;
use App\Support\MailRules\RetroWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MailRuleExistingMailTest extends TestCase
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
        $this->account = MailAccount::factory()->create(['user_id' => $this->user->id]);
    }

    /** @param array<string, mixed> $attributes */
    private function rule(string $value, string $action = 'archive', array $attributes = []): MailRule
    {
        return MailRule::create($attributes + [
            'user_id' => $this->user->id,
            'mail_account_id' => null,
            'position' => 1,
            'field' => 'from',
            'operator' => 'contains',
            'value' => $value,
            'action' => $action,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function mail(string $from, array $attributes = []): Email
    {
        return Email::factory()->create($attributes + [
            'mail_account_id' => $this->account->id,
            'from_address' => $from,
            'from_name' => null,
            'remote_folder_path' => 'INBOX',
        ]);
    }

    private function preview(MailRule $rule, ?int $before = null): RetroWindow
    {
        return app(ApplyRuleToExistingMail::class)->preview($rule, $before);
    }

    public function test_the_preview_counts_matches_and_changes_nothing(): void
    {
        $rule = $this->rule('noisy');
        $this->mail('a@noisy.test');
        $this->mail('b@noisy.test');
        $this->mail('human@example.com');

        $window = $this->preview($rule);

        $this->assertSame(2, $window->matched);
        $this->assertSame(3, $window->scanned);
        $this->assertNull($window->nextBefore);
        $this->assertSame(0, Email::where('is_archived', true)->count());
        $this->assertDatabaseCount('pending_mirror_actions', 0);
    }

    public function test_apply_archives_only_matching_inbox_mail(): void
    {
        $rule = $this->rule('noisy');
        $hit = $this->mail('a@noisy.test');
        $miss = $this->mail('human@example.com');
        $sent = $this->mail('a@noisy.test', ['folder' => 'SENT']);
        $trashed = $this->mail('a@noisy.test', ['is_deleted' => true]);

        $window = app(ApplyRuleToExistingMail::class)->apply($rule, null);

        $this->assertSame(1, $window->matched);
        $this->assertTrue($hit->fresh()?->is_archived);
        $this->assertSame($rule->id, $hit->fresh()?->archived_by_rule_id);
        $this->assertFalse($miss->fresh()?->is_archived);
        $this->assertFalse($sent->fresh()?->is_archived);
        $this->assertFalse($trashed->fresh()?->is_archived);
        $this->assertTrue($trashed->fresh()?->is_deleted);
    }

    public function test_mail_already_archived_by_hand_is_left_alone_by_every_action(): void
    {
        MailFolder::create(['mail_account_id' => $this->account->id, 'local_name' => 'Shops', 'remote_path' => 'Shops']);
        $parked = $this->mail('a@noisy.test', ['is_archived' => true, 'uid' => '55']);

        foreach ([['star', []], ['mark_read', []], ['file', ['mail_account_id' => $this->account->id, 'folder' => 'Shops']]] as [$action, $extra]) {
            app(ApplyRuleToExistingMail::class)->apply($this->rule('noisy', $action, $extra), null);
        }

        $parked->refresh();
        $this->assertSame('INBOX', $parked->folder);
        $this->assertFalse($parked->is_starred);
        $this->assertFalse($parked->is_read);
        $this->assertDatabaseCount('pending_mirror_actions', 0);
    }

    public function test_apply_is_idempotent(): void
    {
        $rule = $this->rule('noisy', 'star');
        $this->mail('a@noisy.test');
        $apply = app(ApplyRuleToExistingMail::class);

        $first = $apply->apply($rule, null);
        $second = $apply->apply($rule, null);

        $this->assertSame(1, $first->matched);
        $this->assertSame(0, $second->matched);
        $this->assertDatabaseCount('pending_mirror_actions', 1);
    }

    public function test_apply_star_and_read_mirror_once_each(): void
    {
        $star = $this->rule('noisy', 'star');
        $read = $this->rule('noisy', 'mark_read');
        $this->mail('a@noisy.test');
        $this->mail('b@noisy.test', ['is_starred' => true]);
        $this->mail('c@noisy.test', ['is_read' => true]);

        app(ApplyRuleToExistingMail::class)->apply($star, null);
        app(ApplyRuleToExistingMail::class)->apply($read, null);

        $this->assertSame(2, PendingMirrorAction::where('action', 'star')->count());
        $this->assertSame(2, PendingMirrorAction::where('action', 'mark_read')->count());
        $this->assertSame(3, Email::where('is_starred', true)->count());
        $this->assertSame(3, Email::where('is_read', true)->count());
        Queue::assertPushed(DrainMirrorActionsJob::class);
    }

    public function test_apply_file_moves_from_the_source_uid_and_skips_rows_with_no_uid(): void
    {
        MailFolder::create(['mail_account_id' => $this->account->id, 'local_name' => 'Shops', 'remote_path' => 'Shops']);
        $rule = $this->rule('shop', 'file', ['mail_account_id' => $this->account->id, 'folder' => 'Shops']);
        $hit = $this->mail('a@shop.test', ['uid' => '77']);
        $noUid = $this->mail('b@shop.test', ['uid' => null]);

        $window = app(ApplyRuleToExistingMail::class)->apply($rule, null);

        $this->assertSame(1, $window->matched);
        $this->assertSame('Shops', $hit->fresh()?->folder);
        $this->assertNull($hit->fresh()?->uid);
        $this->assertDatabaseHas('pending_mirror_actions', ['email_id' => $hit->id, 'action' => 'move:Shops', 'uid' => '77']);
        $this->assertSame('INBOX', $noUid->fresh()?->folder);
    }

    public function test_a_file_rule_whose_folder_is_gone_changes_nothing(): void
    {
        $rule = $this->rule('shop', 'file', ['mail_account_id' => $this->account->id, 'folder' => 'Gone']);
        $this->mail('a@shop.test');

        $window = app(ApplyRuleToExistingMail::class)->apply($rule, null);

        $this->assertSame(0, $window->matched);
        $this->assertSame(0, Email::where('folder', 'Gone')->count());
        $this->assertDatabaseCount('pending_mirror_actions', 0);
    }

    public function test_it_never_touches_another_users_mail_or_accounts(): void
    {
        $rule = $this->rule('noisy');
        $theirs = Email::factory()->create(['from_address' => 'a@noisy.test', 'from_name' => null]);
        $otherAccountSameUser = MailAccount::factory()->create(['user_id' => $this->user->id]);
        $mine = Email::factory()->create(['mail_account_id' => $otherAccountSameUser->id, 'from_address' => 'a@noisy.test', 'from_name' => null]);

        app(ApplyRuleToExistingMail::class)->apply($rule, null);

        $this->assertFalse($theirs->fresh()?->is_archived);
        $this->assertTrue($mine->fresh()?->is_archived);
    }

    public function test_an_account_scoped_rule_only_reaches_that_account(): void
    {
        $second = MailAccount::factory()->create(['user_id' => $this->user->id]);
        $rule = $this->rule('noisy', 'archive', ['mail_account_id' => $second->id]);
        $onFirst = $this->mail('a@noisy.test');
        $onSecond = Email::factory()->create(['mail_account_id' => $second->id, 'from_address' => 'a@noisy.test', 'from_name' => null]);

        app(ApplyRuleToExistingMail::class)->apply($rule, null);

        $this->assertFalse($onFirst->fresh()?->is_archived);
        $this->assertTrue($onSecond->fresh()?->is_archived);
    }

    public function test_the_scan_is_bounded_and_hands_back_a_cursor_to_continue(): void
    {
        $rule = $this->rule('noisy');
        $emails = collect(range(1, 7))->map(fn (int $i): Email => $this->mail("m{$i}@noisy.test"));
        $apply = new ApplyRuleToExistingMail(app(ApplyRuleOutcome::class), scanLimit: 3);

        $first = $apply->apply($rule, null);

        $this->assertSame(3, $first->scanned);
        $this->assertSame(3, $first->matched);
        $this->assertNotNull($first->nextBefore);
        $this->assertSame(3, Email::where('is_archived', true)->count());
        $this->assertTrue($emails[6]->fresh()?->is_archived);
        $this->assertFalse($emails[0]->fresh()?->is_archived);

        $second = $apply->apply($rule, $first->nextBefore);
        $third = $apply->apply($rule, $second->nextBefore);

        $this->assertSame(3, $second->matched);
        $this->assertSame(1, $third->matched);
        $this->assertNull($third->nextBefore);
        $this->assertSame(7, Email::where('is_archived', true)->count());
    }

    public function test_the_cursor_can_walk_past_a_long_run_of_non_matches(): void
    {
        $rule = $this->rule('noisy');
        $old = $this->mail('a@noisy.test');
        foreach (range(1, 5) as $i) {
            $this->mail("human{$i}@example.com");
        }
        $apply = new ApplyRuleToExistingMail(app(ApplyRuleOutcome::class), scanLimit: 2);

        $window = $apply->apply($rule, null);
        while ($window->nextBefore !== null) {
            $window = $apply->apply($rule, $window->nextBefore);
        }

        $this->assertTrue($old->fresh()?->is_archived);
    }

    public function test_a_rule_that_matches_nothing_is_harmless(): void
    {
        $rule = $this->rule('zzz-never');
        $this->mail('a@noisy.test');

        $window = app(ApplyRuleToExistingMail::class)->apply($rule, null);

        $this->assertSame(0, $window->matched);
        $this->assertSame(0, Email::where('is_archived', true)->count());
    }

    public function test_retroactive_mail_is_never_deleted(): void
    {
        $this->rule('noisy', 'archive');
        $this->rule('noisy', 'star');
        $this->rule('noisy', 'mark_read');
        $this->mail('a@noisy.test');

        foreach (MailRule::all() as $rule) {
            app(ApplyRuleToExistingMail::class)->apply($rule, null);
        }

        $this->assertSame(0, Email::where('is_deleted', true)->count());
        $this->assertDatabaseMissing('pending_mirror_actions', ['action' => 'delete']);
    }

    public function test_the_preview_page_shows_the_count_and_apply_runs_it(): void
    {
        $rule = $this->rule('noisy');
        $this->mail('a@noisy.test');
        $this->mail('b@noisy.test');

        $this->actingAs($this->user)->get(route('mailRules.preview', $rule))
            ->assertOk()
            ->assertSee('2 of the 2 messages');

        $this->actingAs($this->user)->post(route('mailRules.apply', $rule), [])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame(2, Email::where('is_archived', true)->count());
    }

    public function test_apply_and_preview_are_404_for_another_users_rule(): void
    {
        $rule = $this->rule('noisy');
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get(route('mailRules.preview', $rule))->assertNotFound();
        $this->actingAs($stranger)->post(route('mailRules.apply', $rule), [])->assertNotFound();
    }

    public function test_a_garbage_cursor_is_rejected(): void
    {
        $rule = $this->rule('noisy');

        $this->actingAs($this->user)->get(route('mailRules.preview', [$rule, 'before' => 'abc']))->assertSessionHasErrors('before');
        $this->actingAs($this->user)->post(route('mailRules.apply', $rule), ['before' => '-5'])->assertSessionHasErrors('before');
    }
}
