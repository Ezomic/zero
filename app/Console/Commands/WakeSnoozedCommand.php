<?php

namespace App\Console\Commands;

use App\Models\Email;
use Illuminate\Console\Command;

class WakeSnoozedCommand extends Command
{
    protected $signature = 'mail:wake-snoozed';

    protected $description = 'Return conversations whose snooze has expired to the inbox';

    /** Rows per UPDATE, so a large catch-up cannot exceed SQLite's bind limit. */
    protected const CHUNK = 500;

    /**
     * A tidying sweep rather than the thing that makes snooze work.
     *
     * The queries already treat a snooze whose moment has passed as expired,
     * so a conversation is back in the inbox at its chosen time whether or not
     * this has run. What this does is clear the column, which keeps the
     * Snoozed view honest and stops the comparison being carried forever.
     *
     * That is also what makes it idempotent: it selects on the same condition
     * it clears, so a missed run catches up on everything due rather than
     * skipping whatever it was asleep for, and a second run finds nothing.
     */
    public function handle(): int
    {
        $woken = 0;

        do {
            $ids = Email::query()
                ->whereNotNull('snoozed_until')
                ->where('snoozed_until', '<=', now())
                ->limit(self::CHUNK)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $woken += Email::whereIn('id', $ids)->update(['snoozed_until' => null]);
        } while ($ids->count() === self::CHUNK);

        $this->info($woken === 0 ? 'Nothing due.' : "Returned {$woken} messages to the inbox.");

        return self::SUCCESS;
    }
}
