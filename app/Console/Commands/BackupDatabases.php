<?php

namespace App\Console\Commands;

use App\Models\BackupDb;
use App\Services\DumpChangeDetector;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Spatie\DbDumper\Compressors\GzipCompressor;
use Spatie\DbDumper\Databases\MySql;
use Spatie\DbDumper\Exceptions\DumpFailed;

class BackupDatabases extends Command
{
    protected $signature = 'backup:databases {--all : Salva anche i database il cui dump è invariato} {--database=* : Solo questi database}';

    protected $description = 'Esegue il dump locale dei database, ne verifica l\'integrità e li sincronizza sulle destinazioni configurate (R2 e/o VPS remota)';

    /**
     * Nomi da salvare: quelli passati con --database, altrimenti l'elenco del pannello (o la configurazione).
     *
     * @return array<int, string>
     */
    public function databaseNames(): array
    {
        $requested = array_values(array_filter(array_map(
            fn (mixed $name): string => trim((string) $name),
            (array) $this->option('database'),
        ), fn (string $name): bool => $name !== ''));

        if ($requested !== []) {
            return $requested;
        }

        $only = BackupDb::namesToBackUp() ?: config('backup_paths.databases.only', []);

        if ($only !== []) {
            return array_values($only);
        }

        $ignored = config('backup_paths.databases.ignored', []);

        return array_values(array_diff(array_column(DB::select('SHOW DATABASES'), 'Database'), $ignored));
    }

    public function handle(DumpChangeDetector $changes): int
    {
        $databases = $this->databaseNames();

        $timestamp = now()->format('Y-m-d_H-i-s');
        $failedDbs = [];
        $results = [];
        // Hash dei dump da registrare solo dopo la sincronizzazione riuscita: se il caricamento fallisce, al giro dopo il dump non risulta «invariato».
        $savedHashes = [];
        $backupDbs = BackupDb::query()->where('server_name', config('backup_paths.destination_prefix'))->get()->keyBy('database_name');

        foreach ($databases as $dbName) {
            $dbName = trim($dbName);

            if (! preg_match('/^[A-Za-z0-9_]+$/', $dbName)) {
                $this->error(' ERRORE durante il dump: nome del database non valido.');
                $failedDbs[] = $dbName;
                $results[] = ['name' => 'invalid', 'ok' => false, 'error' => 'nome del database non valido'];

                continue;
            }

            $this->info("Inizio backup DB: [{$dbName}]");

            $localFolder = storage_path("app/backups/databases/{$dbName}");
            File::makeDirectory($localFolder, 0755, true, true);

            $localFile = "{$localFolder}/{$dbName}_{$timestamp}.sql.gz";

            try {
                // 1. Dump compresso locale
                // Snapshot coerente senza LOCK TABLES: con il lock una vista che rimanda a tabelle inesistenti fa fallire tutto il dump.
                $dumper = MySql::create()
                    ->setDbName($dbName)
                    ->setUserName(config('backup_paths.mysql.username'))
                    ->setPassword(config('backup_paths.mysql.password'))
                    ->setHost(config('backup_paths.mysql.host', '127.0.0.1'))
                    ->useSingleTransaction()
                    ->skipLockTables()
                    ->useCompressor(new GzipCompressor);

                $warning = null;
                $tolerant = in_array($dbName, (array) config('backup_paths.databases.tolerate_errors', []), true);

                if ($tolerant) {
                    // Database vecchi con viste non valide: mysqldump prosegue (--force) e il dump vale se supera i controlli qui sotto.
                    $dumper->addExtraOption('--force');
                }

                try {
                    $dumper->dumpToFile($localFile);
                } catch (DumpFailed $e) {
                    if (! $tolerant || ! file_exists($localFile)) {
                        throw $e;
                    }

                    $warning = 'dump completato con errori tollerati (viste non valide)';
                    $this->warn(" [{$dbName}] {$warning}");
                }

                // 2. Verifiche di integrità
                if (! file_exists($localFile) || filesize($localFile) < 1024) {
                    throw new Exception('File dump assente o troppo piccolo (<1KB).');
                }

                $gzCheck = Process::run('gzip -t '.escapeshellarg($localFile));
                if (! $gzCheck->successful()) {
                    throw new Exception('Archivio Gzip corrotto: '.$gzCheck->errorOutput());
                }

                $this->info(" Integrity Check OK per [{$dbName}] (".round(filesize($localFile) / 1024, 2).' KB)');

                // 3. Dump invariato rispetto all'ultimo su R2: non si ricarica.
                $backupDb = $backupDbs->get($dbName);
                $hash = $backupDb ? $changes->hash($localFile) : null;
                $sizeKb = (int) round(filesize($localFile) / 1024);

                if ($backupDb && ! $this->option('all') && $changes->isUnchanged($backupDb, $hash)) {
                    @unlink($localFile);
                    $backupDb->update(['last_checked_at' => now()]);
                    $this->info(" [{$dbName}] invariato dall'ultimo salvataggio: non viene ricaricato.");
                    $results[] = ['name' => $dbName, 'ok' => true, 'size_kb' => $sizeKb, 'unchanged' => true];

                    continue;
                }

                if ($backupDb) {
                    $savedHashes[$dbName] = $hash;
                }

                $results[] = ['name' => $dbName, 'ok' => true, 'size_kb' => $sizeKb] + ($warning ? ['warning' => $warning] : []);

            } catch (Exception $e) {
                $this->error(" ERRORE durante il dump di [{$dbName}]: ".$e->getMessage());
                $failedDbs[] = $dbName;
                // Nell'esito solo il tipo di errore e l'inizio del messaggio: niente percorsi o credenziali.
                $results[] = ['name' => $dbName, 'ok' => false, 'error' => class_basename($e).': '.mb_substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 120)];
                if (file_exists($localFile)) {
                    @unlink($localFile);
                }
            }
        }

        // 4. Sincronizzazione della cartella locale dei DB verso le destinazioni configurate
        $destinations = [];

        foreach ((array) config('backup_paths.sync_targets', ['r2']) as $target) {
            $destinations[$target] = match ($target) {
                'r2' => $this->syncToR2(),
                'vps' => $this->syncToRemoteVps(),
                default => false,
            };

            $destinations[$target]
                ? $this->info(' Copia su '.strtoupper($target).' completata.')
                : $this->error(' Copia su '.strtoupper($target).' FALLITA: i dump restano solo in locale.');
        }

        // L'esito per database e destinazione serve al report notturno (NightlyBackup -> UnicoBPM ed email agli amministratori).
        Cache::put('backup:last_databases', ['at' => now()->toIso8601String(), 'databases' => $results, 'destinations' => $destinations], now()->addDays(2));

        $this->summary($results, $destinations);

        if (in_array(false, $destinations, true)) {
            return self::FAILURE;
        }

        foreach ($savedHashes as $dbName => $hash) {
            $backupDbs->get($dbName)->update(['last_dump_hash' => $hash, 'last_checked_at' => now(), 'last_changed_at' => now()]);
        }

        // 5. Pulizia locali più vecchi di N giorni
        $this->cleanLocalBackups();

        return empty($failedDbs) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Riepilogo a fine comando: un database per riga con l'esito, poi le destinazioni.
     *
     * @param  list<array{name: string, ok: bool, size_kb?: int, error?: string, warning?: string, unchanged?: bool}>  $results
     * @param  array<string, bool>  $destinations
     */
    private function summary(array $results, array $destinations): void
    {
        $this->newLine();
        $this->table(['Database', 'Esito', 'KB', 'Nota'], array_map(fn (array $result): array => [
            $result['name'],
            match (true) {
                ! $result['ok'] => 'FALLITO',
                ! empty($result['unchanged']) => 'invariato',
                default => 'salvato',
            },
            $result['size_kb'] ?? '-',
            $result['error'] ?? $result['warning'] ?? '',
        ], $results));

        foreach ($destinations as $target => $ok) {
            $this->line(' '.strtoupper($target).': '.($ok ? 'copia riuscita' : 'copia FALLITA'));
        }
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
