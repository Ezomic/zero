<?php

namespace App\Support\MailRules;

use Carbon\CarbonInterface;

/**
 * One bounded stretch of existing mail checked against a rule. `nextBefore`
 * is the cursor for the stretch after it, or null once the mailbox is done.
 */
final readonly class RetroWindow
{
    public function __construct(
        public int $scanned,
        public int $matched,
        public ?int $nextBefore,
        public ?CarbonInterface $oldest,
    ) {}
}
