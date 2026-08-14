<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * The handful of times worth offering as one click (ZERO-114).
 *
 * All of them are computed in the app timezone, not UTC: "tomorrow morning"
 * has to mean the user's morning or the preset is worse than useless.
 */
final class SnoozePresets
{
    public const EVENING = 'evening';

    public const TOMORROW = 'tomorrow';

    public const NEXT_WEEK = 'next_week';

    private const EVENING_HOUR = 18;

    private const MORNING_HOUR = 8;

    /**
     * @return array<string, string> key => label
     */
    public static function options(): array
    {
        return [
            self::EVENING => 'This evening',
            self::TOMORROW => 'Tomorrow morning',
            self::NEXT_WEEK => 'Next week',
        ];
    }

    public static function isKnown(?string $key): bool
    {
        return $key !== null && array_key_exists($key, self::options());
    }

    public static function resolve(string $key): ?CarbonImmutable
    {
        $now = self::now();

        return match ($key) {
            // Already past six means this evening has gone, so it rolls to the
            // next one rather than resolving to a time in the past, which the
            // query would treat as due immediately.
            self::EVENING => $now->hour < self::EVENING_HOUR
                ? $now->setTime(self::EVENING_HOUR, 0)
                : $now->addDay()->setTime(self::EVENING_HOUR, 0),
            self::TOMORROW => $now->addDay()->setTime(self::MORNING_HOUR, 0),
            self::NEXT_WEEK => $now->next('monday')->setTime(self::MORNING_HOUR, 0),
            default => null,
        };
    }

    private static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(AppTimezone::name());
    }
}
