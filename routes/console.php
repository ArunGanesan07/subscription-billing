<?php

use Illuminate\Support\Facades\Schedule;

// Keep today's rollup fresh for the dashboard (≤15 min stale).
Schedule::command('billing:run --aggregate-only')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Nightly: settle the late-arrival window and invoice every cycle that is due.
Schedule::command('billing:run')
    ->dailyAt('00:30')
    ->withoutOverlapping()
    ->onOneServer();
