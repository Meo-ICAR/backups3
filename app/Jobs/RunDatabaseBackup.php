<?php

namespace App\Jobs;

use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Artisan;

/** Dump dei database indicati e aggiornamento dell'elenco su R2. Parte dopo la risposta, senza un worker. */
class RunDatabaseBackup
{
    use Dispatchable;

    public int $timeout = 7200;

    public int $tries = 1;

    /**
     * @param  array<int, string>  $databases
     */
    public function __construct(public array $databases) {}

    public function handle(): void
    {
        set_time_limit(0);

        Artisan::call('backup:databases', [
            '--database' => $this->databases,
            '--all' => true,
        ]);

        Artisan::call('backup:sync-remote');
    }
}
