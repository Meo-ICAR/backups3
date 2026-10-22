<?php

namespace App\Console\Commands;

use App\Services\BpmReporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NightlyBackup extends Command
{
    protected $signature = 'backup:nightly';

    protected $description = 'Run notturno completo: backup dei database, sync degli upload e dei file .env, pulizia retention, poi heartbeat e esito a UnicoBPM';

    public function handle(): int
    {
        $started = microtime(true);

        $exitCodes = [
            'databases' => $this->call('backup:databases'),
            'uploads' => $this->call('backup:uploads'),
            'env' => $this->call('backup:env'),
            'clean' => $this->call('backup:clean'),
        ];

        $allOk = collect($exitCodes)->every(fn (int $code): bool => $code === 0);

        if (! $allOk) {
            Log::channel('single')->error('Backup notturno fallito', $exitCodes);
        }

        if ($pingUrl = config('backup_paths.heartbeat_url')) {
            // Healthchecks.io e servizi compatibili: /fail segnala l'esito negativo del run
            Http::timeout(10)->get($allOk ? $pingUrl : rtrim($pingUrl, '/').'/fail');
        }

        $databases = (array) (Cache::get('backup:last_databases')['databases'] ?? []);

        if (BpmReporter::isConfigured() && ! BpmReporter::send(BpmReporter::payload($exitCodes, $databases, microtime(true) - $started))) {
            $this->warn('Esito non comunicato a UnicoBPM (vedi log).');
        }

        return $allOk ? self::SUCCESS : self::FAILURE;
    }
}
