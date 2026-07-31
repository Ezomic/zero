<?php

namespace App\Support;

/**
 * Typed readers for decoded JSON payloads from the mail providers.
 *
 * Graph and IMAP hand back `mixed` all the way down, so every read has to be
 * narrowed before it can be trusted. Doing that inline at each use site buries
 * the actual logic, so the narrowing lives here instead.
 */
final class Payload
{
    /**
     * @return array<mixed>
     */
    public static function arr(mixed $value, string ...$keys): array
    {
        foreach ($keys as $key) {
            $value = is_array($value) ? ($value[$key] ?? null) : null;
        }

        return is_array($value) ? $value : [];
    }

    public static function str(mixed $value, string ...$keys): string
    {
        foreach ($keys as $key) {
            $value = is_array($value) ? ($value[$key] ?? null) : null;
        }

        return is_string($value) ? $value : '';
    }

    public static function nullableStr(mixed $value, string ...$keys): ?string
    {
        $found = self::str($value, ...$keys);

        return $found === '' ? null : $found;
    }

    public static function bool(mixed $value, string ...$keys): bool
    {
        foreach ($keys as $key) {
            $value = is_array($value) ? ($value[$key] ?? null) : null;
        }

        return $value === true;
    }

    public static function int(mixed $value, string ...$keys): int
    {
        foreach ($keys as $key) {
            $value = is_array($value) ? ($value[$key] ?? null) : null;
        }

        return is_numeric($value) ? (int) $value : 0;
    }
}
