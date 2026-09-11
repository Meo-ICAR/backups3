<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class SyncUploads extends Command
{
    protected $signature = 'backup:uploads';

    protected $description = 'Sincronizzazione incrementale delle cartelle upload utente su Cloudflare R2';

    public function handle(): int
    {
        $directories = config('backup_paths.upload_directories', []);
        $bucket = config('backup_paths.r2.bucket');
        $endpoint = config('backup_paths.r2.endpoint');
        $timeout = (int) config('backup_paths.sync_timeout', 3600);
        $failed = false;

        foreach ($directories as $key => $localPath) {
            if (! is_dir($localPath)) {
                $this->warn("Directory non trovata, saltata: {$localPath}");

                continue;
            }

            $this->info("Sync incrementale per: [{$key}] ({$localPath})...");

            $result = Process::env([
                'AWS_ACCESS_KEY_ID' => config('backup_paths.r2.access_key_id'),
                'AWS_SECRET_ACCESS_KEY' => config('backup_paths.r2.secret_access_key'),
                'AWS_DEFAULT_REGION' => 'auto',
            ])->timeout($timeout)->run([
                'aws', 's3', 'sync',
                $localPath,
                "s3://{$bucket}/uploads/{$key}",
                '--endpoint-url', $endpoint,
                '--no-progress',
            ]);

            if ($result->successful()) {
                $this->info(" Sync completato per [{$key}].");
            } else {
                $this->error(" Errore sync [{$key}]: ".$result->errorOutput());
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
