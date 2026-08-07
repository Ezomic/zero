<?php

namespace App\Support;

/**
 * Reduces an HTML body to the plain text the search index can hold.
 *
 * emails_fts indexes subject, from_address and body_text, and the triggers
 * that maintain it read those columns directly, so anything searchable has to
 * exist as text on the row. A message whose body only ever arrived as HTML
 * therefore contributed nothing (ZERO-102).
 *
 * This is deliberately not a renderer. The reading pane prefers body_html and
 * only falls back to body_text, so the output here is never displayed for a
 * message that has HTML; it exists to be indexed.
 */
final class SearchableBody
{
    /** Long enough for any real message, short enough to bound a runaway one. */
    private const MAX_LENGTH = 100_000;

    public static function fromHtml(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        // script and style contents are not prose and would otherwise be
        // indexed as a wall of selectors and minified javascript.
        $stripped = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;

        // Keep block boundaries as whitespace so words either side of a tag
        // do not fuse into one token.
        $stripped = preg_replace('#<[^>]+>#', ' ', $stripped) ?? $stripped;
        $stripped = html_entity_decode($stripped, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $stripped = preg_replace('/\s+/u', ' ', $stripped) ?? $stripped;
        $stripped = trim($stripped);

        if ($stripped === '') {
            return null;
        }

        return mb_substr($stripped, 0, self::MAX_LENGTH);
    }

    /**
     * The text to store for indexing, given whatever the provider returned.
     * A real text part always wins; HTML is only fallen back on.
     */
    public static function forStorage(?string $text, ?string $html): ?string
    {
        if ($text !== null && trim($text) !== '') {
            return $text;
        }

        return self::fromHtml($html);
    }
}
