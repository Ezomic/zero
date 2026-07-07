<?php

namespace App\Jobs;

use App\Models\MailAccount;
use App\Services\Mail\ImapSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class SyncMailAccountJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    // The account may be deleted between dispatch and execution (e.g. via
    // the accounts page, or IdleMailboxCommand queuing one right before the
    // account is removed). SerializesModels can't re-resolve a deleted
    // MailAccount; without this the job would fail loudly instead of simply
    // no longer being needed.
    public bool $deleteWhenMissingModels = true;

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

    // The scheduler dispatches a job for every active account every 5
    // minutes regardless of whether a previous job for that account is
    // still queued or running. Without this, a single account whose sync
    // takes longer than 5 minutes (e.g. the initial bulk fetch of a large
    // mailbox) piles up duplicate jobs indefinitely. dontRelease() drops the
    // duplicate instead of requeuing it; expireAfter() is a failsafe so a
    // crashed worker can't leave the lock stuck forever.
    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping((string) $this->account->id))
                ->dontRelease()
                ->expireAfter($this->timeout + 60),
        ];
    }

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
