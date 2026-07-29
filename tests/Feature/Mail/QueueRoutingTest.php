<?php

namespace Tests\Feature\Mail;

use App\Jobs\ApplyEmailFlagJob;
use App\Jobs\SyncMailAccountJob;
use App\Models\Email;
use App\Models\MailAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class QueueRoutingTest extends TestCase
{
    use RefreshDatabase;

    private function account(): MailAccount
    {
        return MailAccount::factory()->for(User::factory())->create();
    }

    public function test_mirror_backs_go_to_the_flags_queue(): void
    {
        Queue::fake();

        $account = $this->account();
        $email = Email::factory()->for($account, 'mailAccount')->create();

        ApplyEmailFlagJob::dispatch($email, 'mark_read');

        Queue::assertPushedOn('flags', ApplyEmailFlagJob::class);
    }

    public function test_syncs_stay_off_the_flags_queue(): void
    {
        Queue::fake();

        SyncMailAccountJob::dispatch($this->account());

        Queue::assertPushed(
            SyncMailAccountJob::class,
            fn (SyncMailAccountJob $job): bool => $job->queue !== 'flags',
        );
    }

    public function test_a_duplicate_sync_for_the_same_account_is_not_queued(): void
    {
        Queue::fake();

        $account = $this->account();

        SyncMailAccountJob::dispatch($account);
        SyncMailAccountJob::dispatch($account);

        Queue::assertPushed(SyncMailAccountJob::class, 1);
    }

    public function test_syncs_for_different_accounts_both_queue(): void
    {
        Queue::fake();

        SyncMailAccountJob::dispatch($this->account());
        SyncMailAccountJob::dispatch($this->account());

        Queue::assertPushed(SyncMailAccountJob::class, 2);
    }
}
