<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class CheckBackupConnections extends Command
{
    protected $signature = 'backup:check-connections {--target=* : Destinazioni da testare (r2, vps). Default: quelle configurate in BACKUP_SYNC_TARGET}';

    protected $description = 'Verifica la raggiungibilità e le credenziali delle destinazioni di backup configurate (R2 e/o VPS remota)';

    public function handle(): int
    {
        $targets = $this->option('target') ?: config('backup_paths.sync_targets', ['r2']);
        $hasError = false;

        if (in_array('r2', $targets, true) && ! $this->checkR2()) {
            $hasError = true;
        }

        if (in_array('vps', $targets, true) && ! $this->checkRemoteVps()) {
            $hasError = true;
        }

        return $hasError ? self::FAILURE : self::SUCCESS;
    }

    private function checkR2(): bool
    {
        $this->info('Verifica connessione a Cloudflare R2...');

        $bucket = config('filesystems.disks.r2.bucket');
        $endpoint = config('filesystems.disks.r2.endpoint');
        $key = config('filesystems.disks.r2.key');
        $secret = config('filesystems.disks.r2.secret');

        if (! $bucket || ! $endpoint || ! $key || ! $secret) {
            $this->error('Configurazione R2 mancante in config/filesystems.php (disco "r2").');

            return false;
        }

        $awsEnv = [
            'AWS_ACCESS_KEY_ID' => $key,
            'AWS_SECRET_ACCESS_KEY' => $secret,
            'AWS_DEFAULT_REGION' => 'auto',
            'AWS_REQUEST_CHECKSUM_CALCULATION' => 'when_required',
            'AWS_RESPONSE_CHECKSUM_VALIDATION' => 'when_required',
        ];

        $listResult = Process::env($awsEnv)->timeout(15)->run([
            'aws',
            '--endpoint-url='.$endpoint,
            's3', 'ls',
        ]);

        if ($listResult->successful()) {
            $buckets = trim($listResult->output()) !== '' ? trim($listResult->output()) : '(nessun bucket elencato)';
            $this->info("Bucket accessibili con queste credenziali:\n{$buckets}");
        } else {
            $this->warn('Impossibile elencare i bucket (probabile token scoped su bucket specifici): '.trim($listResult->errorOutput()));
        }

        $result = Process::env($awsEnv)->timeout(15)->run([
            'aws',
            '--endpoint-url='.$endpoint,
            's3api', 'head-bucket',
            '--bucket', $bucket,
        ]);

        if (! $result->successful()) {
            $this->error("Errore connessione R2 (bucket [{$bucket}]): ".$result->errorOutput());

            return false;
        }

        $this->info("Connessione R2 OK (bucket [{$bucket}] raggiungibile).");

        return true;
    }

    private function checkRemoteVps(): bool
    {
        $this->info('Verifica connessione SSH alla VPS remota...');

        $host = config('backup_paths.remote.host');
        $user = config('backup_paths.remote.user');
        $password = config('backup_paths.remote.password');
        $remotePath = config('backup_paths.remote.path');
        $port = config('backup_paths.remote.port', 22);

        if (! $host || ! $user || ! $password || ! $remotePath) {
            $this->error('Configurazione VPS remota mancante in config/backup_paths.php ("remote").');

            return false;
        }

        $result = Process::env(['SSHPASS' => $password])
            ->timeout(15)
            ->run([
                'sshpass', '-e',
                'ssh',
                '-o', 'StrictHostKeyChecking=accept-new',
                '-o', 'ConnectTimeout=8',
                '-p', (string) $port,
                "{$user}@{$host}",
                "mkdir -p '{$remotePath}' && echo BACKUP_CHECK_OK",
            ]);

        if (! $result->successful() || ! str_contains($result->output(), 'BACKUP_CHECK_OK')) {
            $this->error("Errore connessione VPS remota ({$user}@{$host}:{$port}): ".trim($result->errorOutput()));

            return false;
        }

        $this->info("Connessione VPS OK ({$user}@{$host}:{$port}, path [{$remotePath}] pronto).");

        return true;
    }
}
