<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Builds the on-disk name for an attachment.
 *
 * The sender chooses the filename, and it was previously concatenated
 * straight onto the storage path. Flysystem does refuse a path containing
 * `..`, but it refuses it by throwing, after the body has already been saved
 * and inside a caller that swallows the exception — so one hostile name meant
 * the message silently kept no attachments at all, permanently, because the
 * now non-null body stops fetchBody() ever running again (ZERO-106).
 *
 * Two names in one message could also collide: the second write overwrote the
 * first and both rows then pointed at the same file.
 *
 * The on-disk name is purely internal. `email_attachments.filename` keeps the
 * original for display and for the download's Content-Disposition, so this is
 * free to be as boring as it likes.
 */
final class StoredAttachmentName
{
    /** Leaves room for the ULID, the extension and the directories above it. */
    private const MAX_SLUG_LENGTH = 60;

    private const MAX_EXTENSION_LENGTH = 12;

    public static function for(?string $original): string
    {
        $basename = basename(str_replace('\\', '/', (string) $original));
        $extension = self::extension($basename);
        $slug = Str::slug(pathinfo($basename, PATHINFO_FILENAME)) ?: 'attachment';
        $slug = Str::limit($slug, self::MAX_SLUG_LENGTH, '');

        // The ULID is what guarantees two attachments called image.png in one
        // message stay two files.
        $name = $slug.'-'.Str::lower((string) Str::ulid());

        return $extension === '' ? $name : "{$name}.{$extension}";
    }

    private static function extension(string $basename): string
    {
        // Anything not a plain alphanumeric suffix is not worth carrying onto
        // disk; the real name is kept on the row either way.
        $extension = preg_replace('/[^A-Za-z0-9]/', '', pathinfo($basename, PATHINFO_EXTENSION)) ?? '';

        return Str::lower(substr($extension, 0, self::MAX_EXTENSION_LENGTH));
    }
}
