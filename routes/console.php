<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Esecuzione notturna completa
Schedule::call(function () {
    $dbStatus = Artisan::call('backup:databases');
    $uploadStatus = Artisan::call('backup:uploads');
    $cleanStatus = Artisan::call('backup:clean');

    $allOk = $dbStatus === 0 && $uploadStatus === 0 && $cleanStatus === 0;

    if ($allOk) {
        if ($pingUrl = config('backup_paths.heartbeat_url')) {
            Http::timeout(10)->get($pingUrl);
        }
    } else {
        Log::channel('single')->error('Backup notturno fallito', [
            'backup:databases' => $dbStatus,
            'backup:uploads' => $uploadStatus,
            'backup:clean' => $cleanStatus,
        ]);

        if ($pingUrl = config('backup_paths.heartbeat_url')) {
            // Healthchecks.io e servizi compatibili: /fail segnala l'esito negativo del run
            Http::timeout(10)->get(rtrim($pingUrl, '/').'/fail');
        }
    }
})
    ->name('backup:nightly-run')
    ->dailyAt('02:00')
    ->withoutOverlapping(120)
    ->onOneServer();
