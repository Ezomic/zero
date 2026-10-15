<?php

namespace App\Actions\Mail;

use App\Jobs\SendOutgoingMessageJob;
use App\Models\OutgoingMessage;
use Illuminate\Support\Facades\Bus;

/**
 * The safety net under the queue, run every minute (ZERO-117).
 *
 * A held message is only sent by its job, and a job can be lost (the queue
 * table cleared, a worker down through a deploy). Re-queueing is safe because
 * the job claims the row before it sends. A message stuck in `sending` belongs
 * to a worker that died mid-send; it is failed with that stated rather than
 * sent again.
 */
class SweepOutgoingMessages
{
    public function __construct(
        protected DeliverOutgoingMessage $deliver,
        protected PurgeOutgoingMessages $purge,
    ) {}

    public function handle(): void
    {
        $this->requeueLost();
        $this->failStuck();
        $this->purge->expired();
        $this->purge->orphanedDirectories();
    }

    protected function requeueLost(): void
    {
        $overdue = now()->subSeconds(config()->integer('outgoing.redispatch_after_seconds'));

        OutgoingMessage::query()
            ->where('status', OutgoingMessage::HELD)
            ->where('send_at', '<', $overdue)
            ->where(fn ($query) => $query->whereNull('dispatched_at')->orWhere('dispatched_at', '<', $overdue))
            ->each(function (OutgoingMessage $message): void {
                Bus::dispatch(new SendOutgoingMessageJob($message->id));
                $message->forceFill(['dispatched_at' => now()])->save();
            });
    }

    protected function failStuck(): void
    {
        OutgoingMessage::query()
            ->where('status', OutgoingMessage::SENDING)
            ->where('sending_at', '<', now()->subMinutes(config()->integer('outgoing.stale_sending_minutes')))
            ->each(fn (OutgoingMessage $message) => $this->deliver->failUnconfirmed($message));
    }
}
