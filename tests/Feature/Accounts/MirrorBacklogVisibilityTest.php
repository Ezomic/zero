<?php

namespace Tests\Feature\Accounts;

use App\Models\Email;
use App\Models\MailAccount;
use App\Models\PendingMirrorAction;
use App\Models\User;
use App\Support\MirrorBacklog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MirrorBacklogVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private MailAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->account = MailAccount::factory()->create([
            'user_id' => $this->user->id,
            'sync_status' => 'idle',
            'last_synced_at' => now(),
        ]);
    }

    private function queue(int $count, ?string $queuedAt = null, bool $abandoned = false): void
    {
        foreach (range(1, $count) as $i) {
            $email = Email::factory()->create(['mail_account_id' => $this->account->id]);

            $action = PendingMirrorAction::create([
                'mail_account_id' => $this->account->id,
                'email_id' => $email->id,
                'action' => 'mark_read',
                'remote_folder_path' => 'INBOX',
                'uid' => (string) $i,
                'failed_at' => $abandoned ? now() : null,
            ]);

            if ($queuedAt) {
                $action->forceFill(['created_at' => $queuedAt])->save();
            }
        }
    }

    public function test_a_healthy_account_shows_no_backlog_noise(): void
    {
        $this->actingAs($this->user)
            ->get(route('accounts.index'))
            ->assertOk()
            ->assertDontSee('waiting to reach the server');
    }

    public function test_outstanding_actions_are_counted_on_the_card(): void
    {
        $this->queue(3);

        $this->actingAs($this->user)
            ->get(route('accounts.index'))
            ->assertOk()
            ->assertSee('3 waiting to reach the server');
    }

    public function test_a_stalled_backlog_is_called_out_even_though_the_sync_looks_healthy(): void
    {
        $this->queue(2, now()->subHours(3)->toDateTimeString());

        $response = $this->actingAs($this->user)->get(route('accounts.index'));

        $response->assertOk();
        $response->assertSee('Synced');
        $response->assertSee('Nothing has drained for');
    }

    public function test_a_fresh_backlog_is_not_reported_as_stalled(): void
    {
        $this->queue(2, now()->subMinutes(MirrorBacklog::STALLED_AFTER_MINUTES - 5)->toDateTimeString());

        $this->actingAs($this->user)
            ->get(route('accounts.index'))
            ->assertOk()
            ->assertSee('2 waiting to reach the server')
            ->assertDontSee('Nothing has drained for');
    }

    public function test_abandoned_actions_are_reported_separately_from_pending_ones(): void
    {
        $this->queue(1);
        $this->queue(2, abandoned: true);

        $this->actingAs($this->user)
            ->get(route('accounts.index'))
            ->assertOk()
            ->assertSee('1 waiting to reach the server')
            ->assertSee('2 gave up after');
    }

    public function test_another_users_backlog_is_not_counted(): void
    {
        $this->queue(4);

        $intruder = User::factory()->create();
        MailAccount::factory()->create(['user_id' => $intruder->id]);

        $this->actingAs($intruder)
            ->get(route('accounts.index'))
            ->assertOk()
            ->assertDontSee('waiting to reach the server');
    }
}
