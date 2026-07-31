<?php

namespace App\Actions\Mail;

use App\Jobs\DrainMirrorActionsJob;
use App\Models\Email;
use App\Models\PendingMirrorAction;

/**
 * Records a local action for later mirroring to the mail server and nudges
 * the drain. Recording it rather than queueing a job per message is what lets
 * the drain apply a whole folder's worth of changes in one IMAP session
 * (ZERO-78).
 */
class QueueMirrorAction
{
    /**
     * $sourceUid overrides the email's own uid. Moves need it: the caller
     * nulls the uid column before this runs, because the old value is only
     * meaningful in the folder the message is leaving.
     */
    public function handle(Email $email, string $action, ?string $sourceUid = null): void
    {
        $uid = $sourceUid ?? $email->uid;

        if ($uid === null || $uid === '') {
            return;
        }

        PendingMirrorAction::create([
            'mail_account_id' => $email->mail_account_id,
            'email_id' => $email->id,
            'action' => $action,
            'remote_folder_path' => $email->remote_folder_path ?: $email->folder,
            'uid' => $uid,
        ]);

        DrainMirrorActionsJob::dispatch($email->requireMailAccount());
    }
}
