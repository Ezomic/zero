<?php

namespace App\Jobs;

use App\Models\MailAccount;
use App\Services\Mail\ImapSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncMailAccountJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    // Headers-only bulk sync is much faster than a full-body fetch, but a
    // large mailbox (thousands of messages) can still take a while the first
    // time. Incremental runs (last_uid > 0) are fast; only the initial bulk
    // fetch of a large mailbox needs this headroom.
    public int $timeout = 1800;

    // Space out retries: 1 min, then 5 min — gives transient issues time to
    // resolve without holding up the queue for long.
    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function __construct(
        protected MailAccount $account,
    ) {}

    public function handle(ImapSyncService $syncService): void
    {
        if (! $this->account->is_active) {
            return;
        }

        $syncService->sync($this->account);
    }

    public function failed(\Throwable $e): void
    {
        $updates = [
            'sync_status' => 'error',
            'sync_status_since' => now(),
            'sync_error' => $e->getMessage(),
        ];

        // Auth failures require user action — retrying is pointless and clogs
        // the queue. Deactivate immediately so the scheduler stops dispatching.
        if ($this->isAuthError($e)) {
            $updates['is_active'] = false;
        }

        $this->account->update($updates);
    }

    private function isAuthError(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'authenticate')
            || str_contains($message, 'application-specific password')
            || str_contains($message, 'invalid credentials')
            || str_contains($message, 'authentication failed');
    }
}
