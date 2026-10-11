<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Esecuzione notturna completa (backup:nightly: database, upload, retention, heartbeat ed esito a UnicoBPM)
Schedule::command('backup:nightly')
    ->name('backup:nightly-run')
    ->dailyAt('02:00')
    ->withoutOverlapping(120)
    ->onOneServer();
