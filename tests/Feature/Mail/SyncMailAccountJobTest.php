<?php

namespace Tests\Feature\Mail;

use App\Jobs\SyncMailAccountJob;
use App\Models\MailAccount;
use App\Models\User;
use App\Services\Mail\GraphMailSyncService;
use App\Services\Mail\ImapSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Mockery;
use Tests\TestCase;

class SyncMailAccountJobTest extends TestCase
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
            'sync_status' => 'syncing',
        ]);
    }

    public function test_failed_sets_sync_status_to_error_with_exception_message(): void
    {
        $job = new SyncMailAccountJob($this->account);
        $job->failed(new \RuntimeException('NO AUTHENTICATE failed.'));

        $this->account->refresh();
        $this->assertSame('error', $this->account->sync_status);
        $this->assertSame('NO AUTHENTICATE failed.', $this->account->sync_error);
    }

    public function test_inactive_account_is_skipped_without_calling_sync(): void
    {
        $this->account->update(['is_active' => false, 'sync_status' => 'idle']);

        $imapService = Mockery::mock(ImapSyncService::class);
        $imapService->shouldNotReceive('sync');
        $graphService = Mockery::mock(GraphMailSyncService::class);
        $graphService->shouldNotReceive('sync');

        $job = new SyncMailAccountJob($this->account);
        $job->handle($imapService, $graphService);

        $this->account->refresh();
        $this->assertSame('idle', $this->account->sync_status);
    }

    public function test_outlook_account_routes_to_graph_mail_sync_service(): void
    {
        $this->account->update(['provider' => MailAccount::PROVIDER_OUTLOOK]);

        $imapService = Mockery::mock(ImapSyncService::class);
        $imapService->shouldNotReceive('sync');
        $graphService = Mockery::mock(GraphMailSyncService::class);
        $graphService->shouldReceive('sync')->once()->with($this->account);

        $job = new SyncMailAccountJob($this->account);
        $job->handle($imapService, $graphService);
    }

    public function test_non_outlook_account_routes_to_imap_sync_service(): void
    {
        $imapService = Mockery::mock(ImapSyncService::class);
        $imapService->shouldReceive('sync')->once()->with($this->account);
        $graphService = Mockery::mock(GraphMailSyncService::class);
        $graphService->shouldNotReceive('sync');

        $job = new SyncMailAccountJob($this->account);
        $job->handle($imapService, $graphService);
    }

    public function test_middleware_prevents_overlapping_jobs_for_the_same_account(): void
    {
        $job = new SyncMailAccountJob($this->account);
        $middleware = $job->middleware();

        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
        $this->assertSame((string) $this->account->id, $middleware[0]->key);
    }

    public function test_job_is_silently_dropped_instead_of_failing_when_its_account_is_gone(): void
    {
        // Laravel's queue worker checks this property when SerializesModels can't
        // re-resolve the account (because it was deleted between dispatch and
        // execution) — true means the job is quietly discarded instead of failing.
        $job = new SyncMailAccountJob($this->account);

        $this->assertTrue($job->deleteWhenMissingModels);
    }
}
