<?php

namespace App\Actions\Mail;

use App\Jobs\SendOutgoingMessageJob;
use App\Models\OutboundToken;
use App\Models\OutgoingMessage;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Queues one message sent through the API (ZERO-131).
 *
 * It is an ordinary OutgoingMessage, so the existing job, claim, sweep and
 * MailSenderService carry it. What differs from the composer is only that
 * nobody is there to undo it: `send_at` is now and the job has no delay, so it
 * goes out as soon as a worker takes it. The owner is the owner of the token's
 * account, which is what lets the row satisfy `user_id` without a session.
 */
class QueueOutboundMessage
{
    public function handle(OutboundToken $token, string $to, string $subject, string $text): OutgoingMessage
    {
        $account = $token->mailAccount ?? throw new RuntimeException('The token has no sending account.');

        $message = OutgoingMessage::create([
            'user_id' => $account->user_id,
            'mail_account_id' => $account->id,
            'via_api' => true,
            'outbound_token_id' => $token->id,
            'status' => OutgoingMessage::HELD,
            'send_at' => now()->startOfSecond(),
            'to_addresses' => [$to],
            'cc_addresses' => [],
            'subject' => $subject,
            'body' => $text,
            'attachments' => [],
            'dispatched_at' => now(),
        ]);

        // A request that fails must not leave a row behind: the sweep would
        // send it anyway and a client retrying the 500 would deliver twice.
        try {
            Bus::dispatch(new SendOutgoingMessageJob($message->id));
        } catch (Throwable $e) {
            $message->delete();

            throw $e;
        }

        Log::info('outbound.accepted', [
            'token' => $token->name,
            'to' => $to,
            'message_id' => $message->id,
        ]);

        return $message;
    }
}
