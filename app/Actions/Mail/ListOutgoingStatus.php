<?php

namespace App\Actions\Mail;

use App\Models\OutgoingMessage;
use App\Models\User;

/**
 * What the on-page status strip needs to know about a user's own messages: the
 * ones still in flight, one that just went, and any that failed and have not
 * been dealt with. Scoped through the user's relation, never by a bare id
 * (ZERO-117).
 */
class ListOutgoingStatus
{
    /** How long after sending the strip keeps saying so. */
    private const SENT_VISIBLE_SECONDS = 20;

    /** A queued message this long past its time means no worker has taken it. */
    private const DELAYED_AFTER_SECONDS = 20;

    /**
     * @return list<array{id: int, status: string, subject: string, to: string, seconds_left: int, delayed: bool, error: ?string, undo_url: string, dismiss_url: string, restore_url: string}>
     */
    public function handle(User $user): array
    {
        $messages = $user->outgoingMessages()
            ->where(function ($query): void {
                $query->whereIn('status', [OutgoingMessage::HELD, OutgoingMessage::SENDING])
                    ->orWhere(fn ($failed) => $failed->where('status', OutgoingMessage::FAILED)->whereNull('acknowledged_at'))
                    ->orWhere(fn ($sent) => $sent->where('status', OutgoingMessage::SENT)->where('sent_at', '>', now()->subSeconds(self::SENT_VISIBLE_SECONDS)));
            })
            ->orderBy('id')
            ->get()
            ->map(fn (OutgoingMessage $message): array => $this->present($message));

        return array_values($messages->all());
    }

    /** @return array{id: int, status: string, subject: string, to: string, seconds_left: int, delayed: bool, error: ?string, undo_url: string, dismiss_url: string, restore_url: string} */
    protected function present(OutgoingMessage $message): array
    {
        $held = $message->status === OutgoingMessage::HELD;
        $first = $message->to_addresses[0] ?? '';
        $others = count($message->to_addresses) + count($message->cc_addresses) - 1;

        return [
            'id' => $message->id,
            'status' => $message->status,
            'subject' => $message->subject,
            'to' => $others > 0 ? "{$first} and {$others} more" : $first,
            'seconds_left' => $held ? max(0, (int) ceil(now()->diffInSeconds($message->send_at, false))) : 0,
            'delayed' => $held && $message->send_at->addSeconds(self::DELAYED_AFTER_SECONDS)->isPast(),
            'error' => $message->error,
            'undo_url' => route('outgoing.undo', $message->id),
            'dismiss_url' => route('outgoing.dismiss', $message->id),
            'restore_url' => route('compose.create', ['restore' => $message->id]),
        ];
    }
}
