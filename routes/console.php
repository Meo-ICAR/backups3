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

// Backup orari dei database in lavorazione (unico_core_test, unicofinance) nel periodo di sviluppo: dump locali con retention breve.
// Allo scheduler che parte ogni 10 minuti il minuto :00 è sempre servito.
Schedule::command('backup:hourly')
    ->name('backup:hourly-run')
    ->hourly()
    ->withoutOverlapping(30)
    ->onOneServer();
