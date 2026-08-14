<?php

namespace App\Support;

/**
 * The configured timezone, narrowed to a string.
 *
 * config() returns mixed, and every caller that hands the value to Carbon has
 * to deal with that. Doing it once here beats each site inventing its own
 * fallback and drifting apart.
 */
final class AppTimezone
{
    public static function name(): string
    {
        $timezone = config('app.timezone');

        return is_string($timezone) && $timezone !== '' ? $timezone : 'UTC';
    }
}
