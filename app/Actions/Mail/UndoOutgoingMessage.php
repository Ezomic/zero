<?php

namespace App\Actions\Mail;

use App\Models\OutgoingMessage;

/**
 * Takes a held message back. Wins only while the row is still `held`, in one
 * conditional UPDATE, so it cannot overlap with the job claiming the same row:
 * exactly one of them changes it. A message whose time has passed but that no
 * worker has picked up yet can still be taken back, because nothing has left.
 *
 * The attachments stay on disk. They are what the reopened composer lists, and
 * they are removed when that composer is sent or the restore window passes.
 */
class UndoOutgoingMessage
{
    public function handle(OutgoingMessage $message): bool
    {
        return OutgoingMessage::query()
            ->whereKey($message->id)
            ->where('status', OutgoingMessage::HELD)
            ->update([
                'status' => OutgoingMessage::CANCELLED,
                'cancelled_at' => now(),
                'updated_at' => now(),
            ]) === 1;
    }
}
