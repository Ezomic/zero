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
