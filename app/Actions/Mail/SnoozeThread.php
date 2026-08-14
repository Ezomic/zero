<?php

namespace App\Actions\Mail;

use App\Models\Email;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Puts a whole conversation out of sight until a chosen time, and takes it
 * back out again (ZERO-114).
 *
 * Whole conversation rather than one message, matching how archive and move
 * already behave: hiding the message you happened to be looking at while its
 * siblings stayed in the inbox would not be snoozing anything.
 *
 * Nothing is mirrored to the mail server. IMAP has no concept of snoozing,
 * and inventing a folder for it would make the message vanish from every
 * other client, so this stays local and the UI says so.
 */
class SnoozeThread
{
    public function handle(Email $email, CarbonInterface $until): int
    {
        return $this->threadQuery($email)->update(['snoozed_until' => $until]);
    }

    public function wake(Email $email): int
    {
        return $this->threadQuery($email)->update(['snoozed_until' => null]);
    }

    /**
     * @return Builder<Email>
     */
    protected function threadQuery(Email $email): Builder
    {
        return Email::query()
            ->where('mail_account_id', $email->mail_account_id)
            ->where('thread_id', $email->thread_id);
    }
}
