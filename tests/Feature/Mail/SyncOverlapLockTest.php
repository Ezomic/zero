<?php

namespace Tests\Feature\Mail;

use App\Jobs\SyncMailAccountJob;
use App\Models\MailAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

class SyncOverlapLockTest extends TestCase
{
    use RefreshDatabase;

    private function lockKey(SyncMailAccountJob $job, MailAccount $account): string
    {
        return (new WithoutOverlapping((string) $account->id))->getLockKey($job);
    }

    public function test_a_failed_sync_frees_the_overlap_lock(): void
    {
        $account = MailAccount::factory()->for(User::factory())->create();
        $job = new SyncMailAccountJob($account);
        $key = $this->lockKey($job, $account);

        // Stand in for the lock the middleware holds while the sync runs.
        $this->assertTrue(Cache::lock($key, 1860)->get());

        $job->failed(new RuntimeException('database is locked'));

        $this->assertTrue(
            Cache::lock($key, 1860)->get(),
            'the next sync for this account must be able to take the lock',
        );
    }

    public function test_a_failed_sync_still_records_the_error(): void
    {
        $account = MailAccount::factory()->for(User::factory())->create(['sync_status' => 'syncing']);

        (new SyncMailAccountJob($account))->failed(new RuntimeException('database is locked'));

        $account->refresh();
        $this->assertSame('error', $account->sync_status);
        $this->assertSame('database is locked', $account->sync_error);
        $this->assertTrue($account->is_active, 'a lock error is not an auth failure');
    }

    public function test_an_auth_failure_still_deactivates_the_account(): void
    {
        $account = MailAccount::factory()->for(User::factory())->create();

        (new SyncMailAccountJob($account))->failed(new RuntimeException('AUTHENTICATE failed'));

        $this->assertFalse($account->refresh()->is_active);
    }
}
