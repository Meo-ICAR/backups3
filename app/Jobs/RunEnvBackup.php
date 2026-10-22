<?php

namespace App\Jobs;

use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Artisan;

/** Copia dei file .env indicati e aggiornamento dell'elenco su R2. Parte dopo la risposta, senza un worker. */
class RunEnvBackup
{
    use Dispatchable;

    public int $timeout = 7200;

    public int $tries = 1;

    /**
     * @param  array<int, string>  $apps
     */
    public function __construct(public array $apps) {}

    public function handle(): void
    {
        set_time_limit(0);

        Artisan::call('backup:env', [
            '--app' => $this->apps,
            '--all' => true,
        ]);

        Artisan::call('backup:sync-remote');
    }
}
