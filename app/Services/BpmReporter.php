<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Comunica a UnicoBPM l'esito del backup notturno (`POST /api/events`, evento `backup.esito`). UnicoBPM lo conserva (Eventi ricevuti) e,
 * con un trigger su questo evento (es. condizione status = failed), può avviare un processo di intervento. L'invio non cambia mai l'esito
 * del backup: se UnicoBPM non risponde si scrive solo un avviso nel log. Nessuna credenziale e nessun contenuto dei dump nel messaggio.
 */
class BpmReporter
{
    public static function isConfigured(): bool
    {
        return filled(config('backup_paths.bpm.url')) && filled(config('backup_paths.bpm.key'));
    }

    /**
     * @param  array<string, int>  $exitCodes
     * @param  list<array{name: string, ok: bool, size_kb?: int, error?: string, warning?: string}>  $databases
     * @return array<string,mixed>
     */
    public static function payload(array $exitCodes, array $databases, float $seconds, ?\DateTimeInterface $now = null): array
    {
        $now ??= now();
        $prefix = trim((string) config('backup_paths.destination_prefix'), '/') ?: 'server';
        $ok = collect($exitCodes)->every(fn (int $code): bool => $code === 0);

        return [
            'event_id' => "backup.esito:{$prefix}:".$now->format('Y-m-d_H-i'),
            'event' => 'backup.esito',
            'source' => 'backups3',
            'subject_ref' => "{$prefix}:".$now->format('Y-m-d'),
            'occurred_at' => $now->format(DATE_ATOM),
            'data' => [
                'status' => $ok ? 'ok' : 'failed',
                'server' => $prefix,
                'exit_codes' => $exitCodes,
                'databases' => $databases,
                'databases_failed' => array_values(array_column(array_filter($databases, fn (array $d): bool => ! $d['ok']), 'name')),
                'databases_warnings' => array_values(array_column(array_filter($databases, fn (array $d): bool => isset($d['warning'])), 'name')),
                'seconds' => (int) round($seconds),
            ],
        ];
    }

    /** @param array<string,mixed> $payload */
    public static function send(array $payload): bool
    {
        if (! self::isConfigured()) {
            return false;
        }

        try {
            $response = Http::asJson()
                ->timeout((int) config('backup_paths.bpm.timeout', 10))->connectTimeout(4)
                ->retry(3, 2000, throw: false)
                ->withHeaders(['X-Api-Key' => (string) config('backup_paths.bpm.key'), 'X-Correlation-Id' => (string) $payload['event_id']])
                ->post(rtrim((string) config('backup_paths.bpm.url'), '/').'/api/events', $payload);
        } catch (Throwable $e) {
            Log::warning('Esito del backup non comunicato a UnicoBPM.', ['error' => class_basename($e)]);

            return false;
        }

        if (! $response->successful()) {
            Log::warning('UnicoBPM non ha accettato l\'esito del backup.', ['status' => $response->status()]);

            return false;
        }

        return true;
    }
}
