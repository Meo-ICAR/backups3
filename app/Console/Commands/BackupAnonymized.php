<?php

namespace App\Console\Commands;

use App\Jobs\RestoreBackup;
use App\Models\AnonymizedBackup;
use App\Services\AnonymizedBackupStore;
use App\Services\Anonymizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\DbDumper\Compressors\GzipCompressor;
use Spatie\DbDumper\Databases\MySql;
use Throwable;

class BackupAnonymized extends Command
{
    protected $signature = 'backup:anonymized
        {database* : Database da anonimizzare}
        {--audit : Solo l\'elenco di cosa si sostituirebbe e delle colonne di testo non coperte: non legge nessun dato}
        {--admin-email= : Indirizzo con cui chi fa manutenzione accede alla copia (default: ANON_MAINTENANCE_EMAIL)}
        {--password-hash= : Hash bcrypt della password degli utenti della copia (default: ANON_MAINTENANCE_PASSWORD_HASH); senza, se ne genera una e si mostra a fine comando}
        {--require-upload : Fallisce se il bucket dei file anonimizzati non è configurato}
        {--record= : Id del record già creato dal pannello}';

    protected $description = 'Backup estemporaneo anonimizzato: copia il database in uno temporaneo, sostituisce i dati personali, controlla che non ne restino ed esporta';

    public function handle(Anonymizer $anonymizer, AnonymizedBackupStore $store): int
    {
        $databases = array_values(array_unique(array_map('trim', (array) $this->argument('database'))));

        foreach ($databases as $database) {
            if ($error = $this->refusal($database)) {
                $this->error("«{$database}»: {$error}");

                return self::FAILURE;
            }
        }

        if ($this->option('audit')) {
            foreach ($databases as $database) {
                $this->audit($anonymizer, $database);
            }

            return self::SUCCESS;
        }

        $adminEmail = (string) ($this->option('admin-email') ?: config('anonymize.maintenance.email'));

        if ($adminEmail !== '' && ! filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            $this->error('Indirizzo di accesso non valido.');

            return self::FAILURE;
        }

        $generated = null;
        $hash = (string) ($this->option('password-hash') ?: config('anonymize.maintenance.password_hash'));

        if ($hash === '') {
            $generated = Str::password(16, symbols: false);
            $hash = Hash::make($generated);
        }

        $failed = false;

        foreach ($databases as $database) {
            $failed = ! $this->runOne($anonymizer, $store, $database, $adminEmail, $hash) || $failed;
        }

        if ($generated !== null && ! $failed) {
            $this->newLine();
            $this->line("Password degli utenti della copia anonimizzata: {$generated}");
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function refusal(string $database): ?string
    {
        if (! preg_match('/^[A-Za-z0-9_]+$/', $database)) {
            return 'nome del database non valido.';
        }

        if (in_array(strtolower($database), array_map('strtolower', (array) config('anonymize.refused_databases')), true) || str_starts_with($database, (string) config('anonymize.scratch_prefix'))) {
            return 'questo database non si anonimizza.';
        }

        if (DB::selectOne('SELECT 1 AS x FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$database]) === null) {
            return 'database inesistente su questo server.';
        }

        return null;
    }

    private function audit(Anonymizer $anonymizer, string $database): void
    {
        $report = $anonymizer->summarize($anonymizer->plan($database));

        $this->info("Database «{$database}»: {$report['tables_total']} tabelle (solo metadati, nessun dato letto)");
        $this->line('  Svuotate: '.count($report['tables_truncated']).' · lasciate: '.count($report['tables_kept']).' · colonne sostituite: '.count($report['columns']));

        $byStrategy = collect($report['columns'])->countBy('strategy')->sortDesc();
        $this->table(['Strategia', 'Colonne'], $byStrategy->map(fn (int $count, string $strategy): array => [$strategy, $count])->values()->all());

        if ($report['untouched_strings'] !== []) {
            $this->warn('Colonne char/varchar che nessuna regola copre: controllane i nomi e, se contengono dati personali, aggiungi una regola in config/anonymize.php.');
            $this->line('  '.implode("\n  ", $report['untouched_strings']));
        }

        if ($report['skipped'] !== []) {
            $this->warn('Strategie non applicabili al tipo di colonna:');
            $this->line('  '.implode("\n  ", $report['skipped']));
        }
    }

    private function runOne(Anonymizer $anonymizer, AnonymizedBackupStore $store, string $database, string $adminEmail, string $passwordHash): bool
    {
        $record = $this->record($database);
        $lock = Cache::lock("backup:anonymized:{$database}", 7200);

        if (! $lock->get()) {
            $record->update(['status' => AnonymizedBackup::FAILED, 'finished_at' => now(), 'error' => 'Un backup anonimizzato di questo database è già in corso.']);
            $this->error("«{$database}»: già in corso.");

            return false;
        }

        $scratch = config('anonymize.scratch_prefix').bin2hex(random_bytes(6));
        $localFile = null;
        $report = [];

        try {
            set_time_limit(0);
            ignore_user_abort(true);

            $record->update(['status' => AnonymizedBackup::RUNNING, 'started_at' => now(), 'finished_at' => null, 'error' => null, 'report' => null]);

            $this->line("[{$database}] copia in un database temporaneo…");
            DB::statement("CREATE DATABASE `{$scratch}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $this->copy($database, $scratch);

            $this->line("[{$database}] sostituzione dei dati personali…");
            $report = $anonymizer->anonymize($scratch, $passwordHash);

            $findings = config('anonymize.scan.enabled') ? $anonymizer->scan($scratch) : [];
            $report['scan'] = ['enabled' => (bool) config('anonymize.scan.enabled'), 'clean' => $findings === [], 'findings' => $findings];

            if ($findings !== [] && config('anonymize.scan.fail_on_findings')) {
                $record->update(['report' => $report]);

                throw new RuntimeException('Controllo finale: restano valori che somigliano a dati personali in '.count($findings).' colonne (vedi il rapporto). Il file non è stato prodotto.');
            }

            $report['maintenance_login'] = $adminEmail !== '' && $anonymizer->grantMaintenanceLogin($scratch, $adminEmail, $passwordHash);

            $this->line("[{$database}] esportazione…");
            $localFile = $this->dump($database, $scratch);

            $remoteKey = null;

            if ($store->isConfigured()) {
                $remoteKey = $store->upload($localFile, $database);
                $this->line("[{$database}] inviato al bucket dei file anonimizzati.");
            } elseif ($this->option('require-upload')) {
                throw new RuntimeException('Bucket dei file anonimizzati non configurato: il file resta solo in locale.');
            } else {
                $this->warn("[{$database}] bucket dei file anonimizzati non configurato: il file resta solo in locale.");
            }

            $record->update([
                'status' => AnonymizedBackup::DONE,
                'local_path' => $localFile,
                'remote_key' => $remoteKey,
                'size_bytes' => filesize($localFile),
                'report' => $report,
                'finished_at' => now(),
            ]);

            $this->info("[{$database}] completato: {$localFile}");
            $this->cleanLocal();

            return true;
        } catch (Throwable $e) {
            $message = $this->sanitize($e);
            $this->error("[{$database}] FALLITO: {$message}");

            // Un file già prodotto ha superato il controllo e si tiene solo se è il passaggio finale (invio) a essere fallito.
            if ($localFile !== null && ! $e instanceof RuntimeException) {
                File::delete($localFile);
                $localFile = null;
            }

            $record->update(['status' => AnonymizedBackup::FAILED, 'finished_at' => now(), 'error' => $message, 'local_path' => $localFile, 'report' => $report ?: $record->report]);

            return false;
        } finally {
            $this->dropScratch($scratch);
            $lock->release();
        }
    }

    private function record(string $database): AnonymizedBackup
    {
        $id = (int) $this->option('record');

        if ($id > 0 && ($record = AnonymizedBackup::query()->find($id))) {
            return $record;
        }

        return AnonymizedBackup::query()->create([
            'server_name' => (string) config('backup_paths.destination_prefix'),
            'database_name' => $database,
            'status' => AnonymizedBackup::PENDING,
        ]);
    }

    /** Copia diretta da un database all'altro, senza file intermedi con i dati reali. */
    private function copy(string $source, string $scratch): void
    {
        $credentials = RestoreBackup::credentials();

        $result = Process::env(['MYSQL_PWD' => (string) $credentials['password']])->timeout(7200)->run([
            'bash', '-o', 'pipefail', '-c',
            'mysqldump --host="$1" --user="$2" --single-transaction --skip-lock-tables --skip-triggers --no-tablespaces "$3" | mysql --host="$1" --user="$2" "$4"',
            'copy', $credentials['host'], $credentials['username'], $source, $scratch,
        ]);

        if (! $result->successful()) {
            throw new RuntimeException('Copia nel database temporaneo non riuscita.');
        }
    }

    private function dump(string $database, string $scratch): string
    {
        $directory = rtrim((string) config('anonymize.local_directory'), '/')."/{$database}";
        File::ensureDirectoryExists($directory, 0775);

        $file = "{$directory}/{$database}_anon_".now()->format('Y-m-d_H-i-s').'.sql.gz';
        $credentials = RestoreBackup::credentials();

        MySql::create()
            ->setDbName($scratch)
            ->setUserName((string) $credentials['username'])
            ->setPassword((string) $credentials['password'])
            ->setHost((string) $credentials['host'])
            ->useSingleTransaction()
            ->skipLockTables()
            ->useCompressor(new GzipCompressor)
            ->dumpToFile($file);

        if (! file_exists($file) || filesize($file) === 0) {
            File::delete($file);

            throw new \LogicException('Esportazione del database anonimizzato non riuscita.');
        }

        if (! Process::run(['gzip', '-t', $file])->successful()) {
            File::delete($file);

            throw new \LogicException('Archivio gzip anonimizzato corrotto.');
        }

        @chmod($file, 0660);

        return $file;
    }

    /** Elimina il database temporaneo, e solo uno con il prefisso previsto. */
    private function dropScratch(string $scratch): void
    {
        if (str_starts_with($scratch, (string) config('anonymize.scratch_prefix')) && preg_match('/^[A-Za-z0-9_]+$/', $scratch)) {
            try {
                DB::statement("DROP DATABASE IF EXISTS `{$scratch}`");
            } catch (Throwable) {
                $this->error("Database temporaneo «{$scratch}» non eliminato: eliminalo a mano.");
            }
        }
    }

    private function cleanLocal(): void
    {
        $directory = (string) config('anonymize.local_directory');

        if (! is_dir($directory)) {
            return;
        }

        $limit = now()->subDays((int) config('anonymize.local_retention_days', 7))->timestamp;

        foreach (File::allFiles($directory) as $file) {
            if (str_ends_with($file->getFilename(), '.sql.gz') && $file->getMTime() < $limit) {
                File::delete($file->getRealPath());
            }
        }
    }

    /** Messaggio d'errore senza i valori tra apici: un errore di MySQL può citare un dato reale (es. «Duplicate entry '…'»). */
    private function sanitize(Throwable $e): string
    {
        $message = preg_replace(['/\'[^\']*\'/', '/"[^"]*"/', '/`[^`]*`/', '/\s+/'], ["'…'", '"…"', '`…`', ' '], $e->getMessage());

        return class_basename($e).': '.mb_substr(trim((string) $message), 0, 300);
    }
}
