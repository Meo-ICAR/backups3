<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class SyncUploads extends Command
{
    protected $signature = 'backup:uploads';

    protected $description = 'Esegue la sincronizzazione incrementale delle cartelle di upload utenti verso le destinazioni configurate';

    public function handle(): int
    {
        $directories = config('backup_paths.upload_directories', []);

        if (empty($directories)) {
            $this->warn('Nessuna cartella di upload configurata in config/backup_paths.php');

            return self::SUCCESS;
        }

        $targets = config('backup_paths.sync_targets', ['r2']);
        $hasError = false;

        if (in_array('r2', $targets, true) && ! $this->syncToR2($directories)) {
            $hasError = true;
        }

        if (in_array('vps', $targets, true) && ! $this->syncToRemoteVps($directories)) {
            $hasError = true;
        }

        return $hasError ? self::FAILURE : self::SUCCESS;
    }

    private function syncToR2(array $directories): bool
    {
        $this->info('Inizio sincronizzazione incrementale degli upload su Cloudflare R2...');

        $bucket = config('filesystems.disks.r2.bucket');
        $endpoint = config('filesystems.disks.r2.endpoint');
        $key = config('filesystems.disks.r2.key');
        $secret = config('filesystems.disks.r2.secret');

        if (! $bucket || ! $endpoint || ! $key || ! $secret) {
            $this->error('Configurazione R2 mancante in config/filesystems.php');

            return false;
        }

        $env = [
            'AWS_ACCESS_KEY_ID' => $key,
            'AWS_SECRET_ACCESS_KEY' => $secret,
            'AWS_DEFAULT_REGION' => 'auto',
            'AWS_REQUEST_CHECKSUM_CALCULATION' => 'when_required',
            'AWS_RESPONSE_CHECKSUM_VALIDATION' => 'when_required',
        ];

        $timeout = (int) config('backup_paths.sync_timeout', 3600);
        $prefix = trim((string) config('backup_paths.destination_prefix'), '/');
        $hasError = false;

        foreach ($directories as $keyName => $localPath) {
            if (! File::isDirectory($localPath)) {
                $this->warn("Cartella non trovata, saltata: {$localPath}");

                continue;
            }

            $remoteKey = $prefix !== '' ? "{$prefix}/uploads/{$keyName}" : "uploads/{$keyName}";

            $this->info("Sincronizzazione cartella [{$keyName}]: {$localPath} -> s3://{$bucket}/{$remoteKey}");

            $result = Process::env($env)->timeout($timeout)->run([
                'aws',
                '--endpoint-url='.$endpoint,
                's3', 'sync',
                $localPath,
                "s3://{$bucket}/{$remoteKey}",
                '--only-show-errors',
            ]);

            if ($result->successful()) {
                $this->info("Sincronizzazione R2 completata con successo per [{$keyName}]");
            } else {
                $this->error("Errore durante la sincronizzazione R2 di [{$keyName}]: ".$result->errorOutput());
                $hasError = true;
            }
        }

        return ! $hasError;
    }

    private function syncToRemoteVps(array $directories): bool
    {
        $this->info('Inizio sincronizzazione incrementale degli upload verso la VPS remota...');

        $host = config('backup_paths.remote.host');
        $user = config('backup_paths.remote.user');
        $password = config('backup_paths.remote.password');
        $remotePath = config('backup_paths.remote.path');
        $port = config('backup_paths.remote.port', 22);

        if (! $host || ! $user || ! $password || ! $remotePath) {
            $this->error('Configurazione VPS remota mancante in config/backup_paths.php ("remote").');

            return false;
        }

        $env = ['SSHPASS' => $password];
        $timeout = (int) config('backup_paths.sync_timeout', 3600);
        $prefix = trim((string) config('backup_paths.destination_prefix'), '/');
        $hasError = false;

        foreach ($directories as $keyName => $localPath) {
            if (! File::isDirectory($localPath)) {
                $this->warn("Cartella non trovata, saltata: {$localPath}");

                continue;
            }

            $remoteDir = $prefix !== ''
                ? "{$remotePath}/{$prefix}/uploads/{$keyName}"
                : "{$remotePath}/uploads/{$keyName}";

            $this->info("Sincronizzazione cartella [{$keyName}]: {$localPath} -> {$user}@{$host}:{$remoteDir}");

            $result = Process::env($env)->timeout($timeout)->run([
                'sshpass', '-e',
                'rsync', '-az', '--delete', '--mkpath',
                '-e', "ssh -o StrictHostKeyChecking=accept-new -p {$port}",
                rtrim($localPath, '/').'/',
                "{$user}@{$host}:{$remoteDir}/",
            ]);

            if ($result->successful()) {
                $this->info("Sincronizzazione VPS completata con successo per [{$keyName}]");
            } else {
                $this->error("Errore durante la sincronizzazione VPS di [{$keyName}]: ".$result->errorOutput());
                $hasError = true;
            }
        }

        return ! $hasError;
    }
}
