<?php

namespace App\Jobs;

use App\Models\Email;
use App\Models\MailAccount;
use App\Services\Mail\GraphMailSyncService;
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

    // Declared as a real property (not constructor-promoted) so it carries a
    // genuine class-level default. A promoted param's default only applies to
    // the constructor argument; old queued payloads serialized before this
    // property existed (added in ZERO-9) deserialize without running the
    // constructor and would leave a promoted property uninitialized.
    protected ?string $sourceUid = null;

    public function __construct(
        protected Email $email,
        protected string $action,
        ?string $sourceUid = null,
    ) {
        $this->sourceUid = $sourceUid;
    }

    public function handle(ImapSyncService $imapSyncService, GraphMailSyncService $graphMailSyncService): void
    {
        if ($this->email->requireMailAccount()->provider === MailAccount::PROVIDER_OUTLOOK) {
            $graphMailSyncService->applyAction($this->email, $this->action, $this->sourceUid);

            return;
        }

        $imapSyncService->applyAction($this->email, $this->action, $this->sourceUid);
    }
}
