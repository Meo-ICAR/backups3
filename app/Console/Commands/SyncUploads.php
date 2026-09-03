<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class SyncUploads extends Command
{
    protected $signature = 'backup:uploads';

    protected $description = 'Esegue la sincronizzazione incrementale delle cartelle di upload utenti verso Cloudflare R2';

    public function handle(): int
    {
        $this->info('Inizio sincronizzazione incrementale degli upload su Cloudflare R2...');

        $directories = config('backup_paths.upload_directories', []);
        $bucket = config('filesystems.disks.r2.bucket');
        $endpoint = config('filesystems.disks.r2.endpoint');
        $key = config('filesystems.disks.r2.key');
        $secret = config('filesystems.disks.r2.secret');

        if (empty($directories)) {
            $this->warn('Nessuna cartella di upload configurata in config/backup_paths.php');

            return self::SUCCESS;
        }

        if (! $bucket || ! $endpoint || ! $key || ! $secret) {
            $this->error('Configurazione R2 mancante in config/filesystems.php');

            return self::FAILURE;
        }

        $env = [
            'AWS_ACCESS_KEY_ID' => $key,
            'AWS_SECRET_ACCESS_KEY' => $secret,
            'AWS_DEFAULT_REGION' => 'auto',
            'AWS_REQUEST_CHECKSUM_CALCULATION' => 'when_required',
            'AWS_RESPONSE_CHECKSUM_VALIDATION' => 'when_required',
        ];

        $hasError = false;

        foreach ($directories as $keyName => $localPath) {
            if (! File::isDirectory($localPath)) {
                $this->warn("Cartella non trovata, saltata: {$localPath}");

                continue;
            }

            $this->info("Sincronizzazione cartella [{$keyName}]: {$localPath} -> s3://{$bucket}/uploads/{$keyName}");

            // Esecuzione di aws s3 sync con timeout esteso a 1 ora per file di grandi dimensioni
            $result = Process::env($env)->timeout(3600)->run([
                'aws',
                '--endpoint-url='.$endpoint,
                's3', 'sync',
                $localPath,
                "s3://{$bucket}/uploads/{$keyName}",
                '--only-show-errors',
            ]);

            if ($result->successful()) {
                $this->info("Sincronizzazione completata con successo per [{$keyName}]");
            } else {
                $this->error("Errore durante la sincronizzazione di [{$keyName}]: ".$result->errorOutput());
                $hasError = true;
            }
        }

        return $hasError ? self::FAILURE : self::SUCCESS;
    }
}
