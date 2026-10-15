<?php

namespace App\Actions\Mail;

use App\Jobs\DrainMirrorActionsJob;
use App\Models\Email;
use App\Models\MailAccount;
use App\Support\MailRules\RuleOutcome;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Makes the server-side half of a rule outcome happen: the same mirror
 * actions a manual star, read or move queues, in the same order the triage
 * move queues them (flags first, then the move), so the drain applies them as
 * it would for the user's own click.
 *
 * The local flags are not set here. A message that is being stored gets them
 * in its insert, and existing mail has them set by the caller.
 *
 * Mirror rows are only recorded; the drain is dispatched once per account when
 * the run ends, so a first sync that files thousands of messages is one drain
 * (one IMAP session), not thousands of jobs.
 */
class ApplyRuleOutcome
{
    /** @var array<int, true> */
    protected static array $accountsToDrain = [];

    public function __construct(
        protected QueueMirrorAction $queueMirror,
    ) {}

    public function handle(Email $email, RuleOutcome $outcome, bool $wasStarred, bool $wasRead): void
    {
        try {
            $sourceUid = $email->uid;
            $recorded = [];

            if ($outcome->star && ! $wasStarred) {
                $recorded[] = $this->queueMirror->record($email, 'star', $sourceUid);
            }

            if ($outcome->markRead && ! $wasRead) {
                $recorded[] = $this->queueMirror->record($email, 'mark_read', $sourceUid);
            }

            if ($outcome->folder !== null) {
                $moved = $this->queueMirror->record($email, 'move:'.$outcome->folder, $sourceUid);

                if ($moved) {
                    // The old uid only means something in the folder the message is
                    // leaving; the drain reports the new one (see InboxController::move).
                    $email->update(['folder' => $outcome->folder, 'uid' => null]);
                }

                $recorded[] = $moved;
            }

            if (in_array(true, $recorded, true)) {
                static::$accountsToDrain[(int) $email->mail_account_id] = true;
            }
        } catch (Throwable $e) {
            Log::warning("Mail rule actions failed for email {$email->id}: ".$e->getMessage());
        }
    }

    public static function dispatchQueued(MailAccount $account): void
    {
        if (! isset(static::$accountsToDrain[(int) $account->id])) {
            return;
        }

        unset(static::$accountsToDrain[(int) $account->id]);

        // The rows are already recorded, so a failed dispatch only delays the
        // drain until the next one. It must not replace the sync's own outcome.
        try {
            DrainMirrorActionsJob::dispatch($account);
        } catch (Throwable $e) {
            Log::warning("Could not schedule the mirror drain for account {$account->id}: ".$e->getMessage());
        }
    }
}
