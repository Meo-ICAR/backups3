<?php

namespace App\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Spatie\DbDumper\Compressors\GzipCompressor;
use Spatie\DbDumper\Databases\MySql;

class BackupHourly extends Command
{
    protected $signature = 'backup:hourly {--database=* : Solo questi database (default: quelli di backup_paths.hourly.databases)}';

    protected $description = 'Dump orario locale dei database in lavorazione durante lo sviluppo, con retention breve (nessuna sincronizzazione remota)';

    public function handle(): int
    {
        if (! config('backup_paths.hourly.enabled', true)) {
            $this->info('Backup orari disattivati (backup_paths.hourly.enabled).');

            return self::SUCCESS;
        }

        $databases = $this->option('database') ?: (array) config('backup_paths.hourly.databases', []);
        $base = storage_path('app/backups/hourly');
        $timestamp = now()->format('Y-m-d_H-i-s');
        $results = [];

        foreach ($databases as $database) {
            $database = trim((string) $database);
            $folder = "{$base}/{$database}";
            File::ensureDirectoryExists($folder, 0775);
            // Scrivibili anche dall'utente dello scheduler (cron) quando le cartelle le crea un altro utente del gruppo.
            @chmod($base, 02775);
            @chmod($folder, 02775);
            $file = "{$folder}/{$database}_{$timestamp}.sql.gz";

            try {
                // Snapshot coerente senza LOCK TABLES, come il backup notturno.
                MySql::create()
                    ->setDbName($database)
                    ->setUserName(config('backup_paths.mysql.username'))
                    ->setPassword(config('backup_paths.mysql.password'))
                    ->setHost(config('backup_paths.mysql.host', '127.0.0.1'))
                    ->useSingleTransaction()
                    ->skipLockTables()
                    ->useCompressor(new GzipCompressor)
                    ->dumpToFile($file);

                if (! file_exists($file) || filesize($file) < 1024) {
                    throw new Exception('File dump assente o troppo piccolo (<1KB).');
                }

                $check = Process::run('gzip -t '.escapeshellarg($file));
                if (! $check->successful()) {
                    throw new Exception('Archivio Gzip corrotto.');
                }

                // Leggibile dal gruppo dello sviluppo (i dump sono più sensibili del codice: niente accesso agli altri).
                @chmod($file, 0660);
                $results[] = ['name' => $database, 'ok' => true, 'size_kb' => (int) round(filesize($file) / 1024)];
                $this->info("[{$database}] ok (".round(filesize($file) / 1024, 1).' KB)');
            } catch (Exception $e) {
                @unlink($file);
                $results[] = ['name' => $database, 'ok' => false, 'error' => class_basename($e).': '.mb_substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 120)];
                $this->error("[{$database}] ERRORE: ".$e->getMessage());
            }
        }

        $removed = $this->prune($base, (int) config('backup_paths.hourly.retention_hours', 72));
        $removed > 0 && $this->line("Eliminati {$removed} dump orari più vecchi della retention.");

        Cache::put('backup:last_hourly', ['at' => now()->toIso8601String(), 'databases' => $results], now()->addDay());

        return collect($results)->contains(fn (array $result): bool => ! $result['ok']) ? self::FAILURE : self::SUCCESS;
    }

    /** Elimina i dump orari più vecchi di N ore, in tutte le cartelle dei database. */
    private function prune(string $base, int $hours): int
    {
        if (! File::isDirectory($base)) {
            return 0;
        }

        $limit = now()->subHours($hours)->getTimestamp();
        $removed = 0;

        foreach (File::allFiles($base) as $file) {
            if (str_ends_with($file->getFilename(), '.sql.gz') && $file->getMTime() < $limit) {
                File::delete($file->getPathname());
                $removed++;
            }
        }

        return $removed;
    }
}
