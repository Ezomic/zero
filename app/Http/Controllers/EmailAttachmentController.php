<?php

namespace App\Http\Controllers;

use App\Models\EmailAttachment;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmailAttachmentController extends Controller
{
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
