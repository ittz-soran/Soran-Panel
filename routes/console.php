<?php

use Illuminate\Support\Facades\Schedule;

/*
 * The hourly health check — PANEL_DOC Section 5.
 *
 * Hourly rather than more often because that is what the snapshots are for:
 * storage growing over weeks, a shop nobody has opened for a fortnight. Nothing
 * here is an alarm that needs to fire in ninety seconds.
 *
 * withoutOverlapping, because a shop whose data check is slow can take longer
 * than an hour over a large database, and two runs walking the same shops at
 * once would be two connections and two sets of subprocesses for one answer.
 */
Schedule::command('shops:check')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();
