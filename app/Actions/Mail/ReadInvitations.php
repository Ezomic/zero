<?php

namespace App\Actions\Mail;

use App\Models\Email;
use App\Models\EmailAttachment;
use App\Services\Mail\InvitationParser;
use App\Support\CalendarInvitation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class ReadInvitations
{
    public function __construct(
        protected InvitationParser $parser,
    ) {}

    /**
     * The invitation carried by each message in a thread, keyed by email id.
     *
     * Parsed on read rather than stored at sync time: the attachment file is
     * already the source of truth, and an invite that fails to parse should
     * simply not appear rather than leave a half-written row behind.
     *
     * @param  Collection<int, Email>  $messages
     * @return Collection<int, CalendarInvitation>
     */
    public function handle(Collection $messages, string $timezone): Collection
    {
        $invitations = [];

        foreach ($messages as $message) {
            $invitation = $this->forEmail($message, $timezone);

            if ($invitation instanceof CalendarInvitation) {
                $invitations[(int) $message->id] = $invitation;
            }
        }

        return new Collection($invitations);
    }

    public function forEmail(Email $email, string $timezone): ?CalendarInvitation
    {
        foreach ($email->attachments as $attachment) {
            if (! $this->looksLikeCalendar($attachment)) {
                continue;
            }

            $contents = $this->read($attachment);

            if ($contents === null) {
                continue;
            }

            $invitation = $this->parser->parse($contents, $timezone);

            if ($invitation instanceof CalendarInvitation) {
                return $invitation;
            }
        }

        return null;
    }

    /**
     * Senders disagree about both halves: Outlook and Google label the part
     * text/calendar, some clients fall back to application/ics, and plenty
     * attach a bare invite.ics under application/octet-stream. Match either
     * signal rather than trusting one.
     */
    protected function looksLikeCalendar(EmailAttachment $attachment): bool
    {
        $mime = strtolower((string) $attachment->mime_type);

        return str_contains($mime, 'text/calendar')
            || str_contains($mime, 'application/ics')
            || str_ends_with(strtolower((string) $attachment->filename), '.ics');
    }

    protected function read(EmailAttachment $attachment): ?string
    {
        $disk = Storage::disk('local');

        if (! $disk->exists($attachment->storage_path)) {
            return null;
        }

        try {
            return (string) $disk->get($attachment->storage_path);
        } catch (\Throwable) {
            return null;
        }
    }
}
