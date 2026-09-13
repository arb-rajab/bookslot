<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// R-07 (10-risk-register.md) / D-0037 (09-decision-log.md): hourly is
// deliberately coarse for an MVP-stage housekeeping check, not a
// time-critical alert — the reconciliation grace period itself
// (config/booking.php's `reconciliation_grace_minutes`, 30 minutes) is
// what actually bounds detection latency; hourly polling adds at most one
// extra hour on top of that, which is proportionate to what this check is
// for (surfacing a stale evidentiary gap for a human to investigate, not
// paging anyone in real time). Requires a real `* * * * * php artisan
// schedule:run` cron entry on whatever host this deploys to — not yet
// documented in 08-deployment-and-operations.md (no scheduler runs
// anywhere yet), named as a real, open deployment-story gap rather than
// assumed solved by registering the command here.
Schedule::command('mandates:reconcile-backfill')->hourly();

// D-0050 (09-decision-log.md), FR-06: every 15 minutes to match
// config('booking.reminder_dispatch_window_minutes')'s own default — see
// that config entry's docblock for why the two must stay equal. Same
// "requires a real `php artisan schedule:run` cron entry" deployment gap
// mandates:reconcile-backfill above already carries (08-deployment-and-
// operations.md; no scheduler runs anywhere yet).
Schedule::command('reminders:dispatch')->everyFifteenMinutes();
