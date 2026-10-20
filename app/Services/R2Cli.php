<?php

namespace App\Services;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

/** Accesso a Cloudflare R2 con l'AWS CLI, come gli altri comandi di backup (credenziali dal disco `r2`). */
class R2Cli
{
    public function isConfigured(): bool
    {
        return $this->bucket() && $this->endpoint() && config('filesystems.disks.r2.key') && config('filesystems.disks.r2.secret');
    }

    public function bucket(): ?string
    {
        return config('filesystems.disks.r2.bucket');
    }

    public function endpoint(): ?string
    {
        return config('filesystems.disks.r2.endpoint');
    }

    /** Oggetti del bucket: chiave, dimensione in byte e data di ultima modifica (ISO 8601). */
    public function listObjects(string $prefix = ''): ProcessResult
    {
        return $this->run([
            's3api', 'list-objects-v2',
            '--bucket', $this->bucket(),
            '--prefix', $prefix,
            '--query', 'Contents[].[Key,Size,LastModified]',
            '--output', 'json',
        ]);
    }

    public function download(string $key, string $destination): ProcessResult
    {
        return $this->run(['s3', 'cp', "s3://{$this->bucket()}/{$key}", $destination, '--only-show-errors'], (int) config('backup_paths.sync_timeout', 3600));
    }

    /** @param  array<int, string>  $arguments */
    private function run(array $arguments, int $timeout = 300): ProcessResult
    {
        return Process::env([
            'AWS_ACCESS_KEY_ID' => config('filesystems.disks.r2.key'),
            'AWS_SECRET_ACCESS_KEY' => config('filesystems.disks.r2.secret'),
            'AWS_DEFAULT_REGION' => 'auto',
            'AWS_REQUEST_CHECKSUM_CALCULATION' => 'when_required',
            'AWS_RESPONSE_CHECKSUM_VALIDATION' => 'when_required',
        ])->timeout($timeout)->run(['aws', '--endpoint-url='.$this->endpoint(), ...$arguments]);
    }
}
