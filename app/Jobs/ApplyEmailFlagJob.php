<?php

namespace App\Jobs;

use App\Models\Email;
use App\Services\Mail\ImapSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Mirrors a local read/unread/delete action back to the mail server. Fired
 * async so the UI never blocks on an IMAP round-trip; local state is always
 * updated optimistically before this job is dispatched.
 */
class ApplyEmailFlagJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        protected Email $email,
        protected string $action,
        protected ?string $sourceUid = null,
    ) {}

    public function handle(ImapSyncService $syncService): void
    {
        $syncService->applyAction($this->email, $this->action, $this->sourceUid);
    }
}
