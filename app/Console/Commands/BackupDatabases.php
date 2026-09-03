<?php

namespace App\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Spatie\DbDumper\Databases\MySql;

class BackupDatabases extends Command
{
    protected $signature = 'backup:databases';

    protected $description = 'Esegue il dump locale dei database, ne verifica l\'integrità e li sincronizza su R2';

    public function handle(): int
    {
        $ignored = config('backup_paths.databases.ignored', []);
        $only = config('backup_paths.databases.only', []);

        if (! empty($only)) {
            $databases = $only;
        } else {
            $allDbs = array_column(DB::select('SHOW DATABASES'), 'Database');
            $databases = array_diff($allDbs, $ignored);
        }

        $timestamp = now()->format('Y-m-d_H-i-s');
        $failedDbs = [];

        foreach ($databases as $dbName) {
            $dbName = trim($dbName);
            $this->info("Inizio backup DB: [{$dbName}]");

            $localFolder = storage_path("app/backups/databases/{$dbName}");
            File::makeDirectory($localFolder, 0755, true, true);

            $localFile = "{$localFolder}/{$dbName}_{$timestamp}.sql.gz";

            try {
                // 1. Dump compresso locale
                MySql::create()
                    ->setDbName($dbName)
                    ->setUserName(env('DB_BACKUP_USERNAME'))
                    ->setPassword(env('DB_BACKUP_PASSWORD'))
                    ->setHost(env('DB_BACKUP_HOST', '127.0.0.1'))
                    ->useGzip()
                    ->dumpToFile($localFile);

                // 2. Verifiche di integrità
                if (! file_exists($localFile) || filesize($localFile) < 1024) {
                    throw new Exception('File dump assente o troppo piccolo (<1KB).');
                }

                $gzCheck = Process::run('gzip -t '.escapeshellarg($localFile));
                if (! $gzCheck->successful()) {
                    throw new Exception('Archivio Gzip corrotto: '.$gzCheck->errorOutput());
                }

                $this->info(" Integrity Check OK per [{$dbName}] (".round(filesize($localFile) / 1024, 2).' KB)');

            } catch (Exception $e) {
                $this->error(" ERRORE durante il dump di [{$dbName}]: ".$e->getMessage());
                $failedDbs[] = $dbName;
                if (file_exists($localFile)) {
                    @unlink($localFile);
                }
            }
        }

        // 3. Sincronizzazione dell'intera cartella locale dei DB verso R2 tramite AWS CLI
        $this->info('Sincronizzazione dei file dump verso Cloudflare R2...');
        $syncResult = Process::withEnv([
            'AWS_ACCESS_KEY_ID' => env('R2_ACCESS_KEY_ID'),
            'AWS_SECRET_ACCESS_KEY' => env('R2_SECRET_ACCESS_KEY'),
            'AWS_DEFAULT_REGION' => 'auto',
        ])->run([
            'aws', 's3', 'sync',
            storage_path('app/backups/databases'),
            's3://'.env('R2_BUCKET').'/databases',
            '--endpoint-url', env('R2_ENDPOINT'),
            '--no-progress',
        ]);

        if (! $syncResult->successful()) {
            $this->error('Errore Sync R2: '.$syncResult->errorOutput());

            return self::FAILURE;
        }

        // 4. Pulizia locali più vecchi di N giorni
        $this->cleanLocalBackups();

        return empty($failedDbs) ? self::SUCCESS : self::FAILURE;
    }

    private function cleanLocalBackups(): void
    {
        $days = config('backup_paths.local_retention_days', 7);
        $files = File::allFiles(storage_path('app/backups/databases'));

        foreach ($files as $file) {
            if ($file->getMTime() < now()->subDays($days)->timestamp) {
                File::delete($file->getRealPath());
                $this->line("Pulizia locale: eliminato {$file}->getFilename()");
            }
        }
    }
}
