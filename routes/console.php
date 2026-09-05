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

/*
 * The panel's own backup — PANEL_DOC Section 13.
 *
 * Nightly, at a quiet hour, and BEFORE the shops' own nightly work rather than
 * after: this database is what tells you who the shops belong to, so if only
 * one thing gets done on a struggling night it should be this one.
 *
 * withoutOverlapping, because a dump of a year's licences and payments on a
 * shared host can take longer than a person expects, and two mysqldumps racing
 * each other write two half files.
 */
Schedule::command('panel:backup')
    ->dailyAt('02:30')
    ->withoutOverlapping()
    ->runInBackground();
