<?php

namespace App\Console\Commands;

use App\Jobs\RestoreBackup;
use App\Services\AnonymizedBackupStore;
use App\Services\BackupFailureNotifier;
use App\Services\BpmReporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Throwable;

class RestoreAnonymized extends Command
{
    protected $signature = 'backup:restore-anonymized
        {database?* : Database da ripristinare (default: quelli di ANON_RESTORE_DATABASES o della pianificazione notturna)}
        {--server= : Server di origine, cioè il prefisso nel bucket (default: ANON_R2_PREFIX)}
        {--target= : Database di destinazione (solo con un database; default: ANON_RESTORE_TARGETS, altrimenti <database>_anon)}
        {--yes : Non chiede conferma quando la destinazione è un database esistente non mappato}';

    protected $description = 'Ripristina l\'ultima copia anonimizzata dal bucket dedicato in un database di questo server: la destinazione si elimina e si ricrea, così rispecchia la copia';

    private float $started = 0.0;

    public function handle(AnonymizedBackupStore $store): int
    {
        $this->started = microtime(true);

        if (! $store->isConfigured()) {
            $this->error('Bucket dei file anonimizzati non configurato (ANON_R2_*).');

            return $this->finish(['—' => 'bucket non configurato']);
        }

        $databases = array_values(array_unique(array_map('trim', (array) $this->argument('database')))) ?: (array) config('anonymize.restore.databases');

        if ($this->option('target') && count($databases) !== 1) {
            $this->error('--target vale per un solo database.');

            return self::FAILURE;
        }

        $results = [];

        foreach ($databases as $database) {
            $results[$database] = $this->restoreOne($store, $database);
        }

        return $this->finish($results);
    }

    /** @return string ok oppure il motivo del fallimento */
    private function restoreOne(AnonymizedBackupStore $store, string $database): string
    {
        if (! preg_match('/^[A-Za-z0-9_]+$/', $database)) {
            return 'nome del database non valido';
        }

        $target = $this->targetFor($database);

        if ($refusal = $this->targetRefusal($target)) {
            $this->error("[{$database}] {$refusal}");

            return $refusal;
        }

        $folder = storage_path('app/backups/anonymized-restore/'.bin2hex(random_bytes(6)));

        try {
            $latest = $store->latest($database, $this->option('server') ?: null);

            if ($latest === null) {
                throw new \RuntimeException('nessuna copia anonimizzata nel bucket');
            }

            $age = $latest['at']->diffInHours(now(), true);

            if ($age > (int) config('anonymize.restore.max_age_hours', 26)) {
                throw new \RuntimeException('l\'ultima copia è del '.$latest['at']->format('d/m/Y H:i').': troppo vecchia, la destinazione non viene toccata');
            }

            if ($this->exists($target) && ! $this->isMapped($database) && ! $this->option('yes') && ! $this->confirmName($target)) {
                throw new \RuntimeException('annullato');
            }

            File::ensureDirectoryExists($folder, 0775);
            $file = $folder.'/'.basename($latest['key']);

            $this->line("[{$database}] download di ".basename($latest['key']).'…');
            $store->download($latest['key'], $file);

            if (! file_exists($file) || filesize($file) === 0 || ! Process::run(['gzip', '-t', $file])->successful()) {
                throw new \RuntimeException('file scaricato assente o corrotto');
            }

            $this->line("[{$database}] ripristino in «{$target}»…");
            $this->load($file, $target);

            $tables = (int) DB::selectOne('SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = \'BASE TABLE\'', [$target])->n;
            $this->info("[{$database}] ripristinato in «{$target}» ({$tables} tabelle, copia del {$latest['at']->format('d/m/Y H:i')}).");
            Cache::put("anonymized:last-restore:{$database}", ['at' => now()->toIso8601String(), 'target' => $target, 'copy' => $latest['at']->toIso8601String(), 'tables' => $tables], now()->addDays(3));

            return 'ok';
        } catch (Throwable $e) {
            $reason = $e instanceof \RuntimeException ? $e->getMessage() : class_basename($e);
            $this->error("[{$database}] FALLITO: {$reason}");

            return $reason;
        } finally {
            File::deleteDirectory($folder);
        }
    }

    /** Destinazione: --target, poi la mappa ANON_RESTORE_TARGETS, altrimenti <database>_anon. */
    private function targetFor(string $database): string
    {
        return (string) ($this->option('target') ?: ($this->mapping()[$database] ?? "{$database}_anon"));
    }

    private function isMapped(string $database): bool
    {
        return ! $this->option('target') && isset($this->mapping()[$database]);
    }

    /** @return array<string, string> */
    private function mapping(): array
    {
        $map = [];

        foreach (array_filter(array_map('trim', explode(',', (string) config('anonymize.restore.targets')))) as $pair) {
            [$source, $target] = array_pad(explode(':', $pair, 2), 2, '');
            if ($source !== '' && $target !== '') {
                $map[trim($source)] = trim($target);
            }
        }

        return $map;
    }

    private function targetRefusal(string $target): ?string
    {
        $own = (string) config('database.connections.'.config('database.default').'.database');

        return match (true) {
            ! preg_match('/^[A-Za-z0-9_]+$/', $target) || strlen($target) > 64 => 'nome della destinazione non valido',
            in_array(strtolower($target), ['mysql', 'sys', 'information_schema', 'performance_schema'], true) => 'la destinazione è un database di sistema',
            strcasecmp($target, $own) === 0 => "«{$target}» è il database di questa stessa app: non si sostituisce",
            default => null,
        };
    }

    private function exists(string $target): bool
    {
        return DB::selectOne('SELECT 1 AS x FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$target]) !== null;
    }

    private function confirmName(string $target): bool
    {
        return $this->input->isInteractive() && $this->ask("«{$target}» esiste e verrà eliminato e ricreato. Per confermare scrivi il suo nome") === $target;
    }

    /** Elimina e ricrea la destinazione, poi carica il dump senza file intermedi non compressi. */
    private function load(string $file, string $target): void
    {
        $credentials = RestoreBackup::credentials();
        $env = ['MYSQL_PWD' => (string) $credentials['password']];
        $connection = ['--host='.$credentials['host'], '--user='.$credentials['username']];

        DB::statement("DROP DATABASE IF EXISTS `{$target}`");
        DB::statement("CREATE DATABASE `{$target}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        $load = Process::env($env)->timeout(7200)->run([
            'bash', '-o', 'pipefail', '-c', 'gunzip -c "$1" | mysql --host="$2" --user="$3" "$4"', 'restore', $file, $credentials['host'], $credentials['username'], $target,
        ]);

        if (! $load->successful()) {
            throw new \RuntimeException('caricamento del dump non riuscito');
        }
    }

    /** @param  array<string, string>  $results  database => «ok» oppure il motivo del fallimento */
    private function finish(array $results): int
    {
        $failed = array_filter($results, fn (string $result): bool => $result !== 'ok');

        if ($url = config('anonymize.restore.heartbeat_url')) {
            try {
                Http::timeout(10)->get($failed === [] ? (string) $url : rtrim((string) $url, '/').'/fail');
            } catch (Throwable) {
                $this->warn('Heartbeat non raggiungibile.');
            }
        }

        // Stesso evento `backup.esito` del backup notturno, con data.kind = anonymized_restore.
        $payload = BpmReporter::payload(
            array_map(fn (string $result): int => $result === 'ok' ? 0 : 1, $results),
            array_map(fn (string $result, string $database): array => ['name' => $database, 'ok' => $result === 'ok'] + ($result === 'ok' ? [] : ['error' => mb_substr($result, 0, 120)]), $results, array_keys($results)),
            microtime(true) - $this->started,
            destinations: ['restore' => $failed === []],
            kind: 'anonymized_restore',
        );

        $informed = app(BackupFailureNotifier::class)->report($payload);

        if (BpmReporter::isConfigured() && ! $informed) {
            $this->warn('Esito non comunicato a UnicoBPM (vedi log).');
        }

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }
}
