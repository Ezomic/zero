<?php

namespace App\Jobs;

use App\Models\MailAccount;
use App\Models\PendingMirrorAction;
use App\Services\Mail\GraphMailSyncService;
use App\Services\Mail\ImapSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * Applies everything outstanding for one account in as few IMAP sessions as
 * possible. Replaces the job-per-message model, where each flag change paid
 * for its own connect, folder select and round-trip — 34-38s per message on
 * prod, about 4.5 messages a minute (ZERO-78).
 */
class DrainMirrorActionsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    // Failures are recorded per action row and retried by a later drain, so
    // the job itself never needs the queue's retry.
    public int $timeout = 900;

    public bool $deleteWhenMissingModels = true;

    // Long enough that a burst of triage clicks collapses into one drain,
    // short enough that a drain which dies without running failed() can't
    // block the account for long.
    public int $uniqueFor = 1200;

    public function __construct(
        protected MailAccount $account,
    ) {
        $this->onQueue('flags');
    }

    public function uniqueId(): string
    {
        return (string) $this->account->id;
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        // A dropped duplicate loses nothing: the actions stay in the table and
        // the running drain picks up whatever arrived while it was working.
        return [(new WithoutOverlapping((string) $this->account->id))->dontRelease()->expireAfter($this->timeout + 60)];
    }

    public function handle(ImapSyncService $imapSyncService, GraphMailSyncService $graphMailSyncService): void
    {
        $actions = PendingMirrorAction::query()
            ->where('mail_account_id', $this->account->id)
            ->pending()
            ->orderBy('id')
            ->get();

        if ($actions->isEmpty()) {
            return;
        }

        if ($this->account->provider === MailAccount::PROVIDER_OUTLOOK) {
            $this->drainViaGraph($actions, $graphMailSyncService);

            return;
        }

        $imapSyncService->applyPendingActions($this->account, $actions);
    }

    /**
     * Graph has no equivalent of a UID set, so Outlook keeps applying one
     * message at a time. It still goes through the same table, so the
     * outstanding count on the accounts page means the same thing for every
     * provider.
     *
     * @param  Collection<int, PendingMirrorAction>  $actions
     */
    private function drainViaGraph($actions, GraphMailSyncService $graphMailSyncService): void
    {
        foreach ($actions as $action) {
            $email = $action->email;

            if (! $email) {
                $action->delete();

                continue;
            }

            try {
                $graphMailSyncService->applyAction($email, $action->action, $action->uid);
                $action->delete();
            } catch (\Throwable $e) {
                $action->recordFailure($e->getMessage());
            }
        }
    }
}
