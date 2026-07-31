<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * What an account still owes the mail server. Answers the question the
 * accounts page could not before: are the actions I took actually landing?
 */
final readonly class MirrorBacklog
{
    /**
     * Actions are drained as soon as they are queued, with a sweeper every 5
     * minutes behind that. Anything still waiting well past both is not
     * moving, whatever the sync status claims.
     */
    public const STALLED_AFTER_MINUTES = 15;

    public function __construct(
        public int $pending = 0,
        public ?CarbonInterface $oldestQueuedAt = null,
        public int $abandoned = 0,
    ) {}

    public function isStalled(): bool
    {
        return $this->oldestQueuedAt !== null
            && $this->oldestQueuedAt->diffInMinutes(now()) >= self::STALLED_AFTER_MINUTES;
    }

    public function isEmpty(): bool
    {
        return $this->pending === 0 && $this->abandoned === 0;
    }
}
