<?php

namespace App\Http\Controllers;

use App\Concerns\InteractsWithCurrentUser;
use App\Models\EmailAttachment;
use App\Support\AttachmentKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmailAttachmentController extends Controller
{
    use InteractsWithCurrentUser;

    /**
     * Every attachment the user has, newest first.
     *
     * The rows already carried filename, mime_type and size_bytes; the only
     * way to reach one was through the message that brought it, so finding a
     * file meant first remembering which email it came with (ZERO-121).
     */
    public function index(Request $request): View
    {
        $user = $this->currentUser();
        $kind = $request->string('type')->toString();
        $q = $request->string('q')->toString();

        $attachments = EmailAttachment::query()
            // Scoped by joining through the message rather than by collecting
            // ids first: the id list for a large mailbox is exactly the
            // unbounded IN(...) ZERO-91 removed from search.
            ->whereHas('email', function (Builder $email) use ($user, $request): void {
                $email->where('is_deleted', false)
                    ->whereIn('mail_account_id', $user->mailAccounts()->select('id'));

                if ($request->filled('account')) {
                    $email->where('mail_account_id', $request->integer('account'));
                }
            })
            ->when($q !== '', fn ($query) => $query->where('filename', 'like', '%'.$q.'%'))
            ->when(AttachmentKind::isKnown($kind), fn ($query) => $this->applyKind($query, $kind))
            ->with(['email.mailAccount'])
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        $disk = Storage::disk('local');

        return view('inbox.attachments', [
            'attachments' => $attachments,
            // Resolved here rather than in the view so a row can be rendered
            // as unavailable instead of offering a link that 404s.
            'present' => $attachments->mapWithKeys(
                fn (EmailAttachment $attachment) => [$attachment->id => $disk->exists($attachment->storage_path)]
            ),
            'accounts' => $user->mailAccounts()->get(),
            'kinds' => AttachmentKind::options(),
            'selectedKind' => AttachmentKind::isKnown($kind) ? $kind : null,
            'selectedAccountId' => $request->filled('account') ? $request->integer('account') : null,
            'q' => $q,
        ]);
    }

    /**
     * @param  Builder<EmailAttachment>  $query
     */
    protected function applyKind(Builder $query, string $kind): void
    {
        $query->where(function (Builder $group) use ($kind): void {
            foreach (AttachmentKind::mimePrefixes($kind) as $prefix) {
                $group->orWhere('mime_type', 'like', $prefix.'%');
            }

            foreach (AttachmentKind::extensions($kind) as $extension) {
                $group->orWhere('filename', 'like', '%.'.$extension);
            }
        });
    }

    /**
     * Attachments live on the private `local` disk, so this is the only way
     * to reach one — the storage path is never exposed and the file is never
     * publicly served.
     *
     * Always sent as a download, never inline: an attachment is arbitrary
     * content from an arbitrary sender, and rendering one (an HTML page, an
     * SVG) on this app's own origin would hand it the session.
     */
    public function show(EmailAttachment $attachment): StreamedResponse
    {
        abort_unless($attachment->email?->mailAccount?->user_id === auth()->id(), 403);

        $disk = Storage::disk('local');

        // The row outlives the file if storage was cleared or the write failed
        // partway. A 404 is the honest answer; a stream of nothing is not.
        abort_unless($disk->exists($attachment->storage_path), 404);

        return $disk->download(
            $attachment->storage_path,
            $attachment->filename,
            ['Content-Type' => $attachment->mime_type ?: 'application/octet-stream'],
        );
    }
}
