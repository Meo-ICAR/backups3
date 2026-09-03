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
        $bucket = env('R2_BUCKET');
        $endpoint = env('R2_ENDPOINT');

        foreach ($directories as $key => $localPath) {
            if (! is_dir($localPath)) {
                $this->warn("Directory non trovata, saltata: {$localPath}");

                continue;
            }

            $this->info("Sync incrementale per: [{$key}] ({$localPath})...");

            $result = Process::withEnv([
                'AWS_ACCESS_KEY_ID' => env('R2_ACCESS_KEY_ID'),
                'AWS_SECRET_ACCESS_KEY' => env('R2_SECRET_ACCESS_KEY'),
                'AWS_DEFAULT_REGION' => 'auto',
            ])->timeout(3600)->run([
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
            }
        }

        return self::SUCCESS;
    }
}
