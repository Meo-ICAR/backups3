<?php

namespace App\Console\Commands;

use App\Mail\BackupFailed;
use App\Services\BpmReporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

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

        $last = (array) Cache::get('backup:last_databases', []);
        $payload = BpmReporter::payload($exitCodes, (array) ($last['databases'] ?? []), microtime(true) - $started, destinations: (array) ($last['destinations'] ?? []));
        $bpmInformed = BpmReporter::isConfigured() && BpmReporter::send($payload);

        if (BpmReporter::isConfigured() && ! $bpmInformed) {
            $this->warn('Esito non comunicato a UnicoBPM (vedi log).');
        }

        if (! $allOk) {
            $this->notifyAdmins($payload['data'], $bpmInformed);
        }

        $this->info($allOk ? 'Backup notturno completato.' : 'Backup notturno fallito: '.implode(', ', array_keys(array_filter($exitCodes))).'.');

        return $allOk ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Email agli amministratori del pannello: l'avviso arriva anche se UnicoBPM non risponde. Un errore di invio non cambia l'esito.
     *
     * @param  array<string, mixed>  $report
     */
    private function notifyAdmins(array $report, bool $bpmInformed): void
    {
        $recipients = (array) config('backup_paths.admin_emails', []);

        if (! config('backup_paths.notify_failures', true) || $recipients === []) {
            return;
        }

        try {
            Mail::to($recipients)->send(new BackupFailed($report, $bpmInformed));
            $this->info('Avviso di fallimento inviato a '.implode(', ', $recipients).'.');
        } catch (Throwable $e) {
            Log::warning('Avviso di backup fallito non inviato agli amministratori.', ['error' => class_basename($e)]);
            $this->warn('Avviso agli amministratori non inviato (vedi log).');
        }
    }
}
