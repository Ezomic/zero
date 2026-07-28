<?php

declare(strict_types=1);

namespace App\Support;

class MimeHeader
{
    /**
     * Decode an RFC 2047 encoded-word header value (subject, sender name, ...).
     *
     * webklex decodes headers with imap_utf8(), which leaves some base64
     * UTF-8 encoded-words (and multi-word runs) untouched, so values reach us
     * still looking like "=?UTF-8?B?...?=". iconv handles charset conversion
     * and, with CONTINUE_ON_ERROR, walks past malformed words; mb_decode_mimeheader
     * is the fallback when iconv cannot fully resolve it.
     */
    public static function decode(?string $value): ?string
    {
        if ($value === null || ! str_contains($value, '=?')) {
            return $value;
        }

        $decoded = iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        if (is_string($decoded) && ! str_contains($decoded, '=?')) {
            return $decoded;
        }

        return mb_decode_mimeheader($value);
    }
}
