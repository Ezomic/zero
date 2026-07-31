<?php

namespace App\Support;

/**
 * A stable colour per email address. Thread avatars used to take the account's
 * colour, which made every participant in a conversation look identical
 * (ZERO-79); keying off the sender means the same person is the same colour
 * everywhere, and two people are almost never the same.
 */
final class AvatarColor
{
    /** Deliberately all dark enough for white initials to stay legible. */
    private const PALETTE = [
        '#3B82F6', '#10B981', '#F59E0B', '#EF4444',
        '#8B5CF6', '#EC4899', '#14B8A6', '#F97316',
        '#6366F1', '#0EA5E9', '#84CC16', '#D946EF',
    ];

    public static function forAddress(?string $address): string
    {
        $key = strtolower(trim((string) $address));

        return self::PALETTE[abs(crc32($key)) % count(self::PALETTE)];
    }
}
