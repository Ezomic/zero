<?php

namespace App\Support;

final class OutgoingConfig
{
    public static function holdSeconds(): int
    {
        return max(0, min(config()->integer('outgoing.hold_seconds'), config()->integer('outgoing.max_hold_seconds')));
    }

    public static function disk(): string
    {
        return config()->string('outgoing.disk');
    }
}
