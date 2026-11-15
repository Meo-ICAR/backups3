<?php

namespace App\Console\Commands;

use App\Models\AnonymizedBackup;
use App\Services\AnonymizedBackupStore;
use App\Services\BackupFailureNotifier;
use App\Services\BpmReporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class BackupAnonymizedNightly extends Command
{
    protected $signature = 'backup:anonymized-nightly';

    protected $description = 'Run notturno delle copie anonimizzate: ogni database configurato va nel bucket dedicato; poi pulizia dei file scaduti, heartbeat ed esito a UnicoBPM (come backup:nightly)';

    public function handle(AnonymizedBackupStore $store, BackupFailureNotifier $notifier): int
    {
        $started = microtime(true);
        $databases = (array) config('anonymize.schedule.databases');
        $exitCodes = [];
        $entries = [];

        if (! config('anonymize.maintenance.email') || ! config('anonymize.maintenance.password_hash')) {
            $this->warn('ANON_MAINTENANCE_EMAIL o ANON_MAINTENANCE_PASSWORD_HASH mancano: nelle copie nessuno potrà accedere con un login noto.');
        }

        if ($databases === []) {
            $this->error('Nessun database in ANON_SCHEDULE_DATABASES.');
            $exitCodes['configurazione'] = 1;
        } elseif (! $store->isConfigured()) {
            $this->error('Bucket dei file anonimizzati non configurato (ANON_R2_*): le copie non partono.');

            foreach ($databases as $database) {
                $exitCodes[$database] = 1;
                $entries[] = ['name' => $database, 'ok' => false, 'error' => 'bucket dei file anonimizzati non configurato'];
            }
        } else {
            foreach ($databases as $database) {
                $before = (int) AnonymizedBackup::query()->max('id');
                $exitCodes[$database] = $this->call('backup:anonymized', ['database' => [$database], '--require-upload' => true]);
                $entries[] = $this->entry($database, $exitCodes[$database], $before);
            }

            $this->prune($store, $databases);
        }

        $allOk = $exitCodes !== [] && collect($exitCodes)->every(fn (int $code): bool => $code === 0);

        if ($url = config('anonymize.schedule.heartbeat_url')) {
            try {
                Http::timeout(10)->get($allOk ? (string) $url : rtrim((string) $url, '/').'/fail');
            } catch (Throwable) {
                $this->warn('Heartbeat non raggiungibile.');
            }
        }

        $payload = BpmReporter::payload($exitCodes, $entries, microtime(true) - $started, destinations: ['r2_anonymized' => $allOk], kind: 'anonymized');

        if (! $notifier->report($payload) && BpmReporter::isConfigured()) {
            $this->warn('Esito non comunicato a UnicoBPM (vedi log).');
        }

        $this->info($allOk ? 'Copie anonimizzate completate.' : 'Copie anonimizzate: almeno una è fallita.');

        return $allOk ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Riga dell'esito per UnicoBPM: solo nome, esito, dimensione e l'errore già ripulito dai valori.
     *
     * @return array{name: string, ok: bool, size_kb?: int, error?: string}
     */
    private function entry(string $database, int $exit, int $before): array
    {
        $record = AnonymizedBackup::query()->where('database_name', $database)->where('id', '>', $before)->latest('id')->first();

        if ($exit === 0) {
            return ['name' => $database, 'ok' => true] + ($record ? ['size_kb' => (int) round($record->size_bytes / 1024)] : []);
        }

        return ['name' => $database, 'ok' => false, 'error' => mb_substr((string) ($record?->error ?: 'nessun esito registrato'), 0, 120)];
    }

    /** @param  list<string>  $databases */
    private function prune(AnonymizedBackupStore $store, array $databases): void
    {
        foreach ($databases as $database) {
            try {
                $store->prune($database, (int) config('anonymize.schedule.remote_retention_days', 7));
            } catch (Throwable $e) {
                Log::warning('Pulizia dei file anonimizzati scaduti non riuscita.', ['database' => $database, 'error' => class_basename($e)]);
                $this->warn("[{$database}] pulizia dei file scaduti non riuscita (vedi log).");
            }
        }
    }
}
