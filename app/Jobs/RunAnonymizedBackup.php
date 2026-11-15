<?php

namespace App\Jobs;

use App\Models\AnonymizedBackup;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Artisan;

/**
 * Backup anonimizzato di un database richiesto dal pannello. Parte dopo la risposta, senza un worker.
 * L'hash della password resta in memoria: il job non va in coda, quindi non viene serializzato.
 */
class RunAnonymizedBackup
{
    use Dispatchable;

    public int $timeout = 7200;

    public int $tries = 1;

    public function __construct(public AnonymizedBackup $backup, public string $adminEmail, public string $passwordHash) {}

    public function handle(): void
    {
        set_time_limit(0);

        Artisan::call('backup:anonymized', [
            'database' => [$this->backup->database_name],
            '--record' => $this->backup->id,
            '--admin-email' => $this->adminEmail,
            '--password-hash' => $this->passwordHash,
        ]);
    }
}
