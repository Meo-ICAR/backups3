<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');



// Esecuzione notturna completa
Schedule::call(function () {
    // 1. Backup DB
    $dbStatus = Artisan::call('backup:databases');

    // 2. Sync File Uploads
    $uploadStatus = Artisan::call('backup:uploads');

    // 3. Rotazione/Pulizia vecchi backup R2
    Artisan::call('backup:clean');

    // 4. Notifica/Heartbeat (se entrambi hanno successo)
    if ($dbStatus === 0 && $uploadStatus === 0) {
        if ($pingUrl = env('HEARTBEAT_URL')) {
            Http::get($pingUrl);
        }
    }
})->dailyAt('02:00');
