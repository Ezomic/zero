<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Guaranteed polling fallback — runs every 5 min even when IMAP IDLE is active.
// withoutOverlapping() prevents a stacked sync if the previous job is still running
// (e.g. during the initial bulk fetch of a large mailbox on first connect).
// See ImapSyncService for the full sync strategy description.
Schedule::command('mail:sync')->everyFiveMinutes()->withoutOverlapping();

// Safety net for mirror-backs. They are normally drained by the job queued
// alongside the action; this picks up anything left behind by a drain that was
// dropped as a duplicate or killed mid-run, so a pending action can never sit
// indefinitely waiting for the next user click to nudge it.
Schedule::command('mail:drain-mirrors')->everyFiveMinutes()->withoutOverlapping();

// Fills in bodies for messages nobody has opened, so search can match them
// (ZERO-102). Deliberately off the sync path and on its own budget: a body
// costs about 0.58s against a real Gmail account, so fetching them during a
// sync would add half a minute to a folder with 50 new messages. Runs on the
// quarter hour rather than every five minutes to stay out of the way of the
// two above, and stops itself after 60s whether or not it finished.
Schedule::command('mail:backfill-bodies')->everyFifteenMinutes()->withoutOverlapping();
