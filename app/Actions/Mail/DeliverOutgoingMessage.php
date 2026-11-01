<?php

namespace App\Actions\Mail;

use App\Models\Contact;
use App\Models\Draft;
use App\Models\MailAccount;
use App\Models\OutboundToken;
use App\Models\OutgoingMessage;
use App\Services\Mail\MailSenderService;
use App\Support\MailAttachment;
use App\Support\OutgoingConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Mime\Address;
use Throwable;

/**
 * Sends a held message once its hold has run out (ZERO-117).
 *
 * Queued jobs cannot be reliably deleted, so cancelling only changes the row
 * and this is where that is honoured: the first thing it does is claim the row
 * with a conditional UPDATE. A cancelled, already-sent, failed or in-flight
 * message claims nothing, which is also what makes a retried job harmless.
 * There is deliberately no retry once the claim is won: a send that failed
 * after the server accepted it would go out twice, so a failure is recorded
 * for the user to decide on instead.
 */
class DeliverOutgoingMessage
{
    private const ERROR_LENGTH = 500;

    public function __construct(
        protected MailSenderService $sender,
        protected PurgeOutgoingMessages $purge,
    ) {}

    /**
     * @return int Seconds to wait if called before the hold is over, else 0.
     */
    public function handle(OutgoingMessage $message): int
    {
        if ($message->send_at->isFuture()) {
            return max(1, (int) ceil(now()->diffInSeconds($message->send_at, true)));
        }

        if (! $this->claim($message)) {
            return 0;
        }

        try {
            $this->deliver($message);
        } catch (Throwable $e) {
            $this->fail($message, $e->getMessage());

            return 0;
        }

        $this->complete($message);

        return 0;
    }

    public function failUnconfirmed(OutgoingMessage $message): void
    {
        $this->fail(
            $message,
            'The send was interrupted, so it is not known whether this message went out. Check your Sent folder before sending it again.',
        );
    }

    protected function claim(OutgoingMessage $message): bool
    {
        return OutgoingMessage::query()
            ->whereKey($message->id)
            ->where('status', OutgoingMessage::HELD)
            ->where('send_at', '<=', now())
            ->update(['status' => OutgoingMessage::SENDING, 'sending_at' => now(), 'updated_at' => now()]) === 1;
    }

    protected function deliver(OutgoingMessage $message): void
    {
        $account = $message->mail_account_id === null ? null : MailAccount::find($message->mail_account_id);

        if ($account === null) {
            throw new RuntimeException('The sending account was removed before this message could go out.');
        }

        if ($message->via_api) {
            $this->assertApiSenderStillAllowed($message, $account);
        }

        $this->sender->send($account, [
            'to' => $message->to_addresses,
            'cc' => $message->cc_addresses,
            'subject' => $message->subject,
            'html' => nl2br(e($message->body)),
            'in_reply_to' => $message->in_reply_to,
            'references' => $message->references_header,
            'attachments' => $this->attachments($message),
        ]);
    }

    /**
     * A message accepted before its token was revoked, or its account
     * deactivated, must not go out after that (ZERO-131).
     */
    protected function assertApiSenderStillAllowed(OutgoingMessage $message, MailAccount $account): void
    {
        $token = $message->outbound_token_id === null ? null : OutboundToken::find($message->outbound_token_id);

        if ($token === null || $token->isRevoked() || ! $account->is_active) {
            throw new RuntimeException('The API token was revoked or its account deactivated before this message could go out.');
        }
    }

    /** @return list<MailAttachment> */
    protected function attachments(OutgoingMessage $message): array
    {
        $disk = Storage::disk(OutgoingConfig::disk());

        return array_map(
            fn (array $attachment): MailAttachment => new MailAttachment(
                $attachment['name'],
                $attachment['mime'],
                (string) $disk->get($attachment['path']),
            ),
            $message->attachments,
        );
    }

    protected function complete(OutgoingMessage $message): void
    {
        $message->forceFill(['status' => OutgoingMessage::SENT, 'sent_at' => now(), 'error' => null, 'body' => '', 'references_header' => null])->save();

        // The mail is out. Nothing below may turn that into a reported failure;
        // what it leaves behind, the sweep removes.
        try {
            if (! $message->via_api) {
                foreach ([...$message->to_addresses, ...$message->cc_addresses] as $recipient) {
                    Contact::remember($message->user_id, Address::create($recipient)->getAddress());
                }

                Contact::forgetHandledThisRun();
            }

            if ($message->draft_id !== null) {
                Draft::where('user_id', $message->user_id)->where('id', $message->draft_id)->delete();
            }

            $this->purge->deleteFiles($message);
        } catch (Throwable $e) {
            report($e);
        }
    }

    protected function fail(OutgoingMessage $message, string $reason): void
    {
        $failed = OutgoingMessage::query()
            ->whereKey($message->id)
            ->where('status', OutgoingMessage::SENDING)
            ->update([
                'status' => OutgoingMessage::FAILED,
                'failed_at' => now(),
                'updated_at' => now(),
                'error' => Str::limit($reason, self::ERROR_LENGTH),
                // Nothing reopens an API message, so keep no copy of its text
                // (a reset link) once it has failed.
                'body' => DB::raw($message->via_api ? "''" : 'body'),
            ]);

        // A composed message that fails is a card in the user's status strip.
        // An API one has no strip, so the log is where the failure shows.
        if ($failed === 1 && $message->via_api) {
            Log::warning('outbound.failed', [
                'token' => OutboundToken::find($message->outbound_token_id)?->name,
                'message_id' => $message->id,
                'error' => Str::limit($reason, self::ERROR_LENGTH),
            ]);
        }
    }
}
