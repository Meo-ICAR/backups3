<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * File anonimizzati su Cloudflare R2, nella cartella `maintenance/` (config/anonymize.php, «destination»). Di default si usa il bucket e
 * le chiavi del disco «r2», quindi i backup veri e le copie anonimizzate condividono il bucket: le copie stanno solo in `maintenance/`,
 * che la retention e l'elenco dei backup (`databases/`) non toccano e che qui si elimina solo con il nome di una copia anonimizzata.
 * Con ANON_R2_* si può usare un bucket e chiavi propri. Senza bucket, endpoint e chiavi il file resta solo in locale.
 */
class AnonymizedBackupStore
{
    private const NAME = '/_anon_(\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2})\.sql\.gz$/';

    public function isConfigured(): bool
    {
        $destination = $this->destination();

        return ! empty($destination['bucket']) && ! empty($destination['endpoint']) && ! empty($destination['key']) && ! empty($destination['secret']);
    }

    /**
     * Bucket, endpoint e chiavi: quelli di ANON_R2_* se presenti, altrimenti quelli del disco «r2».
     *
     * @return array{bucket: ?string, endpoint: ?string, key: ?string, secret: ?string, folder: string, prefix: ?string}
     */
    public function destination(): array
    {
        $own = (array) config('anonymize.destination');
        $r2 = (array) config('filesystems.disks.r2');

        return [
            'bucket' => $own['bucket'] ?: ($r2['bucket'] ?? null),
            'endpoint' => $own['endpoint'] ?: ($r2['endpoint'] ?? null),
            'key' => $own['key'] ?: ($r2['key'] ?? null),
            'secret' => $own['secret'] ?: ($r2['secret'] ?? null),
            'folder' => trim((string) ($own['folder'] ?: 'maintenance'), '/'),
            'prefix' => $own['prefix'] ?? null,
        ];
    }

    /** Prefisso degli oggetti di un database: «maintenance/[<server>/]<database>/». Il server è facoltativo (ANON_R2_PREFIX o --server). */
    public function keyPrefix(string $database, ?string $server = null): string
    {
        $destination = $this->destination();
        $server = trim((string) ($server ?: $destination['prefix']), '/');

        return $destination['folder'].'/'.($server !== '' ? "{$server}/" : '').$database.'/';
    }

    /** Carica il file e restituisce la chiave dell'oggetto nel bucket. */
    public function upload(string $file, string $database): string
    {
        $key = $this->keyPrefix($database).basename($file);

        $this->check($this->aws(['s3', 'cp', $file, 's3://'.$this->bucket().'/'.$key, '--only-show-errors'], (int) config('backup_paths.sync_timeout', 3600)), 'Invio al bucket dei file anonimizzati non riuscito.');

        return $key;
    }

    /**
     * Il file anonimizzato più recente di un database: chiave e data, ricavata dal nome del file.
     *
     * @return array{key: string, at: CarbonImmutable}|null
     */
    public function latest(string $database, ?string $server = null): ?array
    {
        $latest = null;

        foreach ($this->keys($this->keyPrefix($database, $server)) as $key) {
            if (preg_match(self::NAME, $key, $match) === 1 && ($latest === null || $key > $latest['key'])) {
                $latest = ['key' => $key, 'at' => CarbonImmutable::createFromFormat('Y-m-d_H-i-s', $match[1], (string) config('app.timezone'))];
            }
        }

        return $latest;
    }

    public function download(string $key, string $destination): void
    {
        $this->check($this->aws(['s3', 'cp', 's3://'.$this->bucket().'/'.$key, $destination, '--only-show-errors'], (int) config('backup_paths.sync_timeout', 3600)), 'Download dal bucket dei file anonimizzati non riuscito.');
    }

    /**
     * Elimina i file anonimizzati di un database più vecchi di N giorni. Restituisce quanti ne ha eliminati.
     */
    public function prune(string $database, int $days): int
    {
        $limit = now()->subDays($days);
        $deleted = 0;

        foreach ($this->keys($this->keyPrefix($database)) as $key) {
            if (preg_match(self::NAME, $key, $match) === 1 && CarbonImmutable::createFromFormat('Y-m-d_H-i-s', $match[1], (string) config('app.timezone'))->lt($limit)) {
                $this->check($this->aws(['s3', 'rm', 's3://'.$this->bucket().'/'.$key, '--only-show-errors']), 'Eliminazione di un file anonimizzato scaduto non riuscita.');
                $deleted++;
            }
        }

        return $deleted;
    }

    /** @return list<string> */
    private function keys(string $prefix): array
    {
        $result = $this->aws(['s3api', 'list-objects-v2', '--bucket', $this->bucket(), '--prefix', $prefix, '--query', 'Contents[].Key', '--output', 'json']);

        $this->check($result, 'Elenco dei file anonimizzati non riuscito.');

        return array_values(array_filter((array) json_decode($result->output(), true), 'is_string'));
    }

    private function bucket(): string
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Bucket dei file anonimizzati non configurato.');
        }

        return (string) $this->destination()['bucket'];
    }

    /** @param  list<string>  $arguments */
    private function aws(array $arguments, int $timeout = 300): ProcessResult
    {
        $destination = $this->destination();

        return Process::env([
            'AWS_ACCESS_KEY_ID' => $destination['key'],
            'AWS_SECRET_ACCESS_KEY' => $destination['secret'],
            'AWS_DEFAULT_REGION' => 'auto',
            'AWS_REQUEST_CHECKSUM_CALCULATION' => 'when_required',
            'AWS_RESPONSE_CHECKSUM_VALIDATION' => 'when_required',
        ])->timeout($timeout)->run(['aws', '--endpoint-url='.$destination['endpoint'], ...$arguments]);
    }

    private function check(ProcessResult $result, string $message): void
    {
        if (! $result->successful()) {
            throw new RuntimeException($message);
        }
    }
}
