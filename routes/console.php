<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Monitoring Mode for every source with an ACTIVE profile (plan §5.2). The
// command itself skips sources behind the circuit breaker, so a failing
// source is never retried in a tight loop (plan §13).
Schedule::command('acquisition:monitor --all --queue')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();
