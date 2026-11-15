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

// Elenco dei backup presenti su R2 (data e dimensione) per il pannello /admin; dopo il run notturno e le pulizie di retention.
Schedule::command('backup:sync-remote')
    ->name('backup:sync-remote-run')
    ->dailyAt('04:00')
    ->withoutOverlapping(30)
    ->onOneServer();

// Copie anonimizzate: sul server di produzione, ogni notte, solo se si può scrivere su R2 (disco «r2» o ANON_R2_*).
if (app(App\Services\AnonymizedBackupStore::class)->isConfigured()) {
    Schedule::command('backup:anonymized-nightly')
        ->name('backup:anonymized-nightly-run')
        ->dailyAt((string) config('anonymize.schedule.at'))
        ->withoutOverlapping(120)
        ->onOneServer()
        ->runInBackground();
}

// Ripristino delle copie anonimizzate: SOLO sul server di sviluppo (ANON_RESTORE_ENABLED=true), dopo la copia notturna.
if (config('anonymize.restore.enabled')) {
    Schedule::command('backup:restore-anonymized')
        ->name('backup:restore-anonymized-run')
        ->dailyAt((string) config('anonymize.restore.at'))
        ->withoutOverlapping(120)
        ->onOneServer()
        ->runInBackground();
}
