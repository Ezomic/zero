<?php

namespace Tests\Feature\Mail;

use App\Jobs\SyncMailAccountJob;
use App\Models\MailAccount;
use App\Models\User;
use App\Services\Mail\ImapSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $service = Mockery::mock(ImapSyncService::class);
        $service->shouldNotReceive('sync');

        $job = new SyncMailAccountJob($this->account);
        $job->handle($service);

        $this->account->refresh();
        $this->assertSame('idle', $this->account->sync_status);
    }
}
