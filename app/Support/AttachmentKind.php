<?php

namespace App\Support;

/**
 * Coarse groupings for the attachment browser: images, documents, archives.
 *
 * Matching is on mime type *and* extension on purpose. Plenty of senders
 * attach a perfectly ordinary PDF as application/octet-stream, and a filter
 * that trusted the declared type alone would hide exactly the files someone
 * came looking for (ZERO-121).
 */
final class AttachmentKind
{
    public const IMAGES = 'images';

    public const DOCUMENTS = 'documents';

    public const ARCHIVES = 'archives';

    /** @var array<string, array{label: string, mimes: array<int, string>, extensions: array<int, string>}> */
    private const KINDS = [
        self::IMAGES => [
            'label' => 'Images',
            'mimes' => ['image/'],
            'extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'bmp', 'tif', 'tiff', 'svg', 'avif'],
        ],
        self::DOCUMENTS => [
            'label' => 'Documents',
            'mimes' => ['application/pdf', 'application/msword', 'application/vnd.', 'text/', 'application/rtf'],
            'extensions' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf', 'txt', 'csv', 'md'],
        ],
        self::ARCHIVES => [
            'label' => 'Archives',
            'mimes' => ['application/zip', 'application/x-tar', 'application/gzip', 'application/x-7z', 'application/vnd.rar', 'application/x-rar'],
            'extensions' => ['zip', 'tar', 'gz', 'tgz', 'bz2', 'rar', '7z', 'xz'],
        ],
    ];

    /**
     * @return array<string, string> kind => label
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::KINDS as $kind => $definition) {
            $options[$kind] = $definition['label'];
        }

        return $options;
    }

    public static function isKnown(?string $kind): bool
    {
        return $kind !== null && array_key_exists($kind, self::KINDS);
    }

    /**
     * Mime prefixes a kind accepts. Prefixes rather than exact values, since
     * "application/vnd." alone covers every Office format.
     *
     * @return array<int, string>
     */
    public static function mimePrefixes(string $kind): array
    {
        return self::KINDS[$kind]['mimes'] ?? [];
    }

    /**
     * @return array<int, string>
     */
    public static function extensions(string $kind): array
    {
        return self::KINDS[$kind]['extensions'] ?? [];
    }

    /**
     * The kind a single file belongs to, or null when it fits none of them.
     * Used for the label on a row, so it answers the same question the filter
     * asks rather than a second approximation of it.
     */
    public static function of(?string $mime, string $filename): ?string
    {
        $mime = strtolower(trim((string) $mime));
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        foreach (array_keys(self::KINDS) as $kind) {
            foreach (self::mimePrefixes($kind) as $prefix) {
                if ($mime !== '' && str_starts_with($mime, $prefix)) {
                    return $kind;
                }
            }

            if ($extension !== '' && in_array($extension, self::extensions($kind), true)) {
                return $kind;
            }
        }

        return null;
    }
}
