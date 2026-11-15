<?php

namespace App\Services;

use App\Mail\BackupFailed;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Esito di un run di backup verso UnicoBPM (evento `backup.esito`, come il backup notturno) e, se fallito, email agli amministratori
 * del pannello anche quando UnicoBPM non risponde. Un errore di invio non cambia mai l'esito del run.
 */
class BackupFailureNotifier
{
    /**
     * @param  array<string, mixed>  $payload  Da BpmReporter::payload().
     * @return bool true se UnicoBPM ha ricevuto l'esito
     */
    public function report(array $payload): bool
    {
        $informed = BpmReporter::isConfigured() && BpmReporter::send($payload);

        if (($payload['data']['status'] ?? 'failed') !== 'ok') {
            $this->mailAdmins($payload['data'], $informed);
        }

        return $informed;
    }

    /** @param  array<string, mixed>  $report */
    private function mailAdmins(array $report, bool $bpmInformed): void
    {
        $recipients = (array) config('backup_paths.admin_emails', []);

        if (! config('backup_paths.notify_failures', true) || $recipients === []) {
            return;
        }

        try {
            Mail::to($recipients)->send(new BackupFailed($report, $bpmInformed));
        } catch (Throwable $e) {
            Log::warning('Avviso di backup fallito non inviato agli amministratori.', ['error' => class_basename($e)]);
        }
    }
}
