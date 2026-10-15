<?php

namespace App\Actions\Mail;

use App\Models\OutgoingMessage;
use App\Support\OutgoingConfig;
use Illuminate\Support\Facades\Storage;

/**
 * Everything that removes a held message's files or rows, in one place so the
 * lifecycle can be read top to bottom (ZERO-117).
 *
 * Files go when the message is sent, when the user dismisses it, when the
 * reopened composer takes it over, and when a taken-back or failed message
 * outlives the restore window. The sweep also removes directories that no row
 * points at, which is what an account or user deletion leaves behind.
 */
class PurgeOutgoingMessages
{
    public function discard(OutgoingMessage $message): void
    {
        $this->deleteFiles($message);
        $message->delete();
    }

    public function deleteFiles(OutgoingMessage $message): void
    {
        Storage::disk(OutgoingConfig::disk())->deleteDirectory($message->attachmentDirectory());
    }

    /**
     * Rows past their useful life, and the files of rows that no longer need
     * any. Returns how many rows were removed.
     */
    public function expired(): int
    {
        $restoreCutoff = now()->subHours(config()->integer('outgoing.restore_ttl_hours'));
        $removed = 0;

        OutgoingMessage::query()
            ->where(function ($query) use ($restoreCutoff): void {
                $query->where('status', OutgoingMessage::CANCELLED)->where('cancelled_at', '<', $restoreCutoff);
            })
            ->orWhere(function ($query) use ($restoreCutoff): void {
                $query->where('status', OutgoingMessage::FAILED)->where('failed_at', '<', $restoreCutoff);
            })
            ->orWhere(function ($query): void {
                $query->where('status', OutgoingMessage::SENT)->where('sent_at', '<', now()->subDay());
            })
            ->each(function (OutgoingMessage $message) use (&$removed): void {
                $this->discard($message);
                $removed++;
            });

        return $removed;
    }

    public function orphanedDirectories(): int
    {
        $disk = Storage::disk(OutgoingConfig::disk());
        $removed = 0;

        foreach ($disk->directories() as $directory) {
            $message = ctype_digit($directory) ? OutgoingMessage::find((int) $directory) : null;

            if ($message !== null && $this->needsFiles($message)) {
                continue;
            }

            if ($this->newerThanOneHour($directory)) {
                continue;
            }

            $disk->deleteDirectory($directory);
            $removed++;
        }

        return $removed;
    }

    protected function needsFiles(OutgoingMessage $message): bool
    {
        return $message->status !== OutgoingMessage::SENT;
    }

    protected function newerThanOneHour(string $directory): bool
    {
        $disk = Storage::disk(OutgoingConfig::disk());

        foreach ($disk->files($directory) as $file) {
            if ($disk->lastModified($file) > now()->subHour()->getTimestamp()) {
                return true;
            }
        }

        return false;
    }
}
