<?php

namespace App\Actions\Mail;

use App\Jobs\SendOutgoingMessageJob;
use App\Models\MailAccount;
use App\Models\OutgoingMessage;
use App\Models\User;
use App\Support\OutgoingConfig;
use App\Support\StoredAttachmentName;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Puts a composed message on hold instead of sending it (ZERO-117).
 *
 * Attachments arrive as uploads on this one request, so they are copied to the
 * private `outgoing` disk under the message's own directory and read back by
 * the queued job. They are deleted when the message is sent, discarded, or
 * swept; see PurgeOutgoingMessages for the rest of that lifecycle.
 */
class HoldOutgoingMessage
{
    private const MAX_NAME_LENGTH = 150;

    public function __construct(
        protected PurgeOutgoingMessages $purge,
    ) {}

    /**
     * @param  array{to: list<string>, cc: list<string>, subject: string, body: string, in_reply_to: ?string, references: ?string}  $fields
     * @param  list<UploadedFile>  $uploads
     * @param  list<int>  $keep  Positions of $restoredFrom's attachments to carry over.
     */
    public function handle(
        User $user,
        MailAccount $account,
        array $fields,
        array $uploads,
        ?OutgoingMessage $restoredFrom = null,
        array $keep = [],
        ?int $draftId = null,
    ): OutgoingMessage {
        $sendAt = now()->startOfSecond()->addSeconds(OutgoingConfig::holdSeconds());

        $message = OutgoingMessage::create([
            'user_id' => $user->id,
            'mail_account_id' => $account->id,
            'status' => OutgoingMessage::HELD,
            'send_at' => $sendAt,
            'to_addresses' => $fields['to'],
            'cc_addresses' => $fields['cc'],
            'subject' => $fields['subject'],
            'body' => $fields['body'],
            'in_reply_to' => $fields['in_reply_to'],
            'references_header' => $fields['references'],
            'attachments' => [],
            'draft_id' => $draftId,
            'dispatched_at' => now(),
        ]);

        try {
            $message->forceFill(['attachments' => [
                ...$this->carryOver($message, $restoredFrom, $keep),
                ...$this->store($message, $uploads),
            ]])->save();

            if ($restoredFrom !== null) {
                $this->takeOver($restoredFrom);
            }

            $this->dispatch($message);
        } catch (Throwable $e) {
            $this->purge->discard($message);

            throw $e;
        }

        if ($restoredFrom !== null) {
            $this->releaseFiles($restoredFrom);
        }

        return $message;
    }

    /**
     * Deletes the row first, as the claim: two requests reopening the same
     * message would otherwise both pass validation and both send it.
     */
    protected function takeOver(OutgoingMessage $from): void
    {
        $claimed = OutgoingMessage::query()
            ->whereKey($from->id)
            ->whereIn('status', [OutgoingMessage::CANCELLED, OutgoingMessage::FAILED])
            ->delete();

        if ($claimed !== 1) {
            throw ValidationException::withMessages(['restore_id' => 'That message was already sent again.']);
        }
    }

    protected function releaseFiles(OutgoingMessage $from): void
    {
        try {
            $this->purge->deleteFiles($from);
        } catch (Throwable $e) {
            // The message is already queued; the sweep removes what is left.
            report($e);
        }
    }

    protected function dispatch(OutgoingMessage $message): void
    {
        $job = new SendOutgoingMessageJob($message->id);

        if (OutgoingConfig::holdSeconds() > 0) {
            $job->delay($message->send_at);
        }

        Bus::dispatch($job);
    }

    /**
     * @param  list<int>  $keep
     * @return list<array{path: string, name: string, mime: string, size: int}>
     */
    protected function carryOver(OutgoingMessage $message, ?OutgoingMessage $from, array $keep): array
    {
        if ($from === null) {
            return [];
        }

        $disk = Storage::disk(OutgoingConfig::disk());
        $carried = [];

        foreach ($from->attachments as $position => $attachment) {
            if (! in_array($position, $keep, true)) {
                continue;
            }

            if (! $disk->exists($attachment['path'])) {
                throw ValidationException::withMessages(['keep_attachments' => "{$attachment['name']} is no longer available. Attach it again."]);
            }

            $path = $this->pathFor($message, $attachment['name']);
            $disk->copy($attachment['path'], $path);
            $carried[] = [...$attachment, 'path' => $path];
        }

        return $carried;
    }

    /**
     * @param  list<UploadedFile>  $uploads
     * @return list<array{path: string, name: string, mime: string, size: int}>
     */
    protected function store(OutgoingMessage $message, array $uploads): array
    {
        $stored = [];

        foreach ($uploads as $upload) {
            $name = $this->displayName($upload->getClientOriginalName());
            $path = $this->pathFor($message, $name);

            Storage::disk(OutgoingConfig::disk())->putFileAs($message->attachmentDirectory(), $upload, basename($path));

            $stored[] = [
                'path' => $path,
                'name' => $name,
                'mime' => $upload->getMimeType() ?? 'application/octet-stream',
                'size' => (int) $upload->getSize(),
            ];
        }

        return $stored;
    }

    protected function pathFor(OutgoingMessage $message, string $name): string
    {
        return $message->attachmentDirectory().'/'.StoredAttachmentName::for($name);
    }

    /**
     * The name that ends up in the MIME header. The disk name is generated
     * separately, so this only has to be safe to put in a header: no control
     * characters (a CRLF would be header injection) and no path separators.
     */
    protected function displayName(string $original): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $original) ?? '';
        $name = trim(str_replace(['/', '\\'], '_', $name));

        return $name === '' ? 'attachment' : Str::limit($name, self::MAX_NAME_LENGTH, '');
    }
}
