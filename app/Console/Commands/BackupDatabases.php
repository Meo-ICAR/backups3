<?php

namespace App\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Spatie\DbDumper\Compressors\GzipCompressor;
use Spatie\DbDumper\Databases\MySql;

class BackupDatabases extends Command
{
    protected $signature = 'backup:databases';

    protected $description = 'Esegue il dump locale dei database, ne verifica l\'integrità e li sincronizza sulle destinazioni configurate (R2 e/o VPS remota)';

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
                    ->setUserName(config('backup_paths.mysql.username'))
                    ->setPassword(config('backup_paths.mysql.password'))
                    ->setHost(config('backup_paths.mysql.host', '127.0.0.1'))
                    ->useCompressor(new GzipCompressor)
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

        // 3. Sincronizzazione della cartella locale dei DB verso le destinazioni configurate
        $targets = config('backup_paths.sync_targets', ['r2']);
        $syncFailed = false;

        if (in_array('r2', $targets, true) && ! $this->syncToR2()) {
            $syncFailed = true;
        }

        if (in_array('vps', $targets, true) && ! $this->syncToRemoteVps()) {
            $syncFailed = true;
        }

        if ($syncFailed) {
            return self::FAILURE;
        }

        // 4. Pulizia locali più vecchi di N giorni
        $this->cleanLocalBackups();

        return empty($failedDbs) ? self::SUCCESS : self::FAILURE;
    }

    private function syncToR2(): bool
    {
        $this->info('Sincronizzazione dei file dump verso Cloudflare R2...');

        $bucket = config('filesystems.disks.r2.bucket');
        $endpoint = config('filesystems.disks.r2.endpoint');

        if (! $bucket || ! $endpoint || ! config('filesystems.disks.r2.key') || ! config('filesystems.disks.r2.secret')) {
            $this->error('Configurazione R2 mancante in config/filesystems.php (disco "r2").');

            return false;
        }

        $prefix = trim((string) config('backup_paths.destination_prefix'), '/');
        $remoteKey = $prefix !== '' ? "{$prefix}/databases" : 'databases';

        $syncResult = Process::env([
            'AWS_ACCESS_KEY_ID' => config('filesystems.disks.r2.key'),
            'AWS_SECRET_ACCESS_KEY' => config('filesystems.disks.r2.secret'),
            'AWS_DEFAULT_REGION' => 'auto',
            'AWS_REQUEST_CHECKSUM_CALCULATION' => 'when_required',
            'AWS_RESPONSE_CHECKSUM_VALIDATION' => 'when_required',
        ])->timeout((int) config('backup_paths.sync_timeout', 3600))->run([
            'aws',
            '--endpoint-url='.$endpoint,
            's3', 'sync',
            storage_path('app/backups/databases'),
            "s3://{$bucket}/{$remoteKey}",
            '--only-show-errors',
        ]);

        if (! $syncResult->successful()) {
            $this->error('Errore Sync R2: '.$syncResult->errorOutput());

            return false;
        }

        return true;
    }

    private function syncToRemoteVps(): bool
    {
        $this->info('Sincronizzazione dei file dump verso la VPS remota...');

        $host = config('backup_paths.remote.host');
        $user = config('backup_paths.remote.user');
        $password = config('backup_paths.remote.password');
        $remotePath = config('backup_paths.remote.path');
        $port = config('backup_paths.remote.port', 22);

        if (! $host || ! $user || ! $password || ! $remotePath) {
            $this->error('Configurazione VPS remota mancante in config/backup_paths.php ("remote").');

            return false;
        }

        $prefix = trim((string) config('backup_paths.destination_prefix'), '/');
        $remoteDir = $prefix !== '' ? "{$remotePath}/{$prefix}/databases" : "{$remotePath}/databases";

        $syncResult = Process::env(['SSHPASS' => $password])
            ->timeout((int) config('backup_paths.sync_timeout', 3600))
            ->run([
                'sshpass', '-e',
                'rsync', '-az', '--delete', '--mkpath',
                '-e', "ssh -o StrictHostKeyChecking=accept-new -p {$port}",
                storage_path('app/backups/databases').'/',
                "{$user}@{$host}:{$remoteDir}/",
            ]);

        if (! $syncResult->successful()) {
            $this->error('Errore Sync VPS remota: '.$syncResult->errorOutput());

            return false;
        }

        return true;
    }

    private function cleanLocalBackups(): void
    {
        $days = config('backup_paths.local_retention_days', 7);
        $files = File::allFiles(storage_path('app/backups/databases'));

        foreach ($files as $file) {
            if ($file->getMTime() < now()->subDays($days)->timestamp) {
                File::delete($file->getRealPath());
                $this->line("Pulizia locale: eliminato {$file->getFilename()}");
            }
        }
    }
}
