<?php

namespace App\Jobs;

use App\Actions\Mail\DeliverOutgoingMessage;
use App\Models\OutgoingMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Carries only the id. Whether the message goes out is decided by the row's
 * status when this runs, never by the fact that the job exists (ZERO-117).
 */
class SendOutgoingMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    // Retries are only for the claim step failing before anything was sent.
    // Once the row is claimed the action never throws, and a later attempt
    // finds it no longer `held` and does nothing.
    public int $tries = 3;

    // Under the database queue's retry_after (90s) so a slow send is killed
    // here rather than picked up a second time while it is still running.
    public int $timeout = 80;

    /** @return list<int> */
    public function backoff(): array
    {
        return [5, 30];
    }

    public function __construct(
        public readonly int $messageId,
    ) {}

    public function handle(DeliverOutgoingMessage $deliver): void
    {
        $message = OutgoingMessage::find($this->messageId);

        if ($message === null) {
            return;
        }

        $wait = $deliver->handle($message);

        if ($wait > 0) {
            $this->release($wait);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $message = OutgoingMessage::find($this->messageId);

        if ($message?->status === OutgoingMessage::SENDING) {
            app(DeliverOutgoingMessage::class)->failUnconfirmed($message);
        }
    }
}
