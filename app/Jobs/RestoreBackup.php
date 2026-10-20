<?php

namespace App\Jobs;

use App\Models\BackupDbRestore;
use App\Services\R2Cli;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

/** Scarica da R2 il dump di un backup e lo carica in un database di questo server. */
class RestoreBackup
{
    use Dispatchable;

    public int $timeout = 7200;

    public int $tries = 1;

    public function __construct(public BackupDbRestore $restore) {}

    public function handle(R2Cli $r2): void
    {
        set_time_limit(0);
        ignore_user_abort(true);

        $restore = $this->restore->refresh();
        $restore->update(['status' => BackupDbRestore::RUNNING, 'started_at' => now(), 'finished_at' => null, 'error' => null]);

        $folder = rtrim((string) config('backup_paths.restore_directory'), '/')."/{$restore->id}";
        $file = $folder.'/'.basename($restore->file->r2_key);

        try {
            if (! $r2->isConfigured()) {
                throw new RuntimeException('Configurazione R2 mancante in config/filesystems.php (disco "r2").');
            }

            if (! preg_match('/^[A-Za-z0-9_]+$/', $restore->target_database)) {
                throw new RuntimeException('Nome del database di destinazione non valido.');
            }

            File::makeDirectory($folder, 0755, true, true);

            $this->check($r2->download($restore->file->r2_key, $file), 'Download da R2');
            $this->check(Process::run(['gzip', '-t', $file]), 'Archivio gzip corrotto');

            $env = ['MYSQL_PWD' => (string) $this->credentials()['password']];
            $connection = ['--host='.$this->credentials()['host'], '--user='.$this->credentials()['username']];

            $this->check(Process::env($env)->run(['mysql', ...$connection, '-e', "CREATE DATABASE IF NOT EXISTS `{$restore->target_database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"]), 'Creazione del database');

            // Il nome del file e del database arrivano come argomenti posizionali: niente interpolazione nella shell.
            $load = Process::env($env)->timeout($this->timeout)->run([
                'bash', '-o', 'pipefail', '-c', 'gunzip -c "$1" | mysql --host="$2" --user="$3" "$4"', 'restore', $file, $this->credentials()['host'], $this->credentials()['username'], $restore->target_database,
            ]);
            $this->check($load, 'Caricamento del dump');

            $restore->update(['status' => BackupDbRestore::DONE, 'finished_at' => now()]);
        } catch (Throwable $e) {
            $restore->update(['status' => BackupDbRestore::FAILED, 'finished_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 2000)]);
        } finally {
            File::deleteDirectory($folder);
        }
    }

    /** @return array{host: string, username: string, password: ?string} */
    private function credentials(): array
    {
        $backup = config('backup_paths.mysql');
        $default = config('database.connections.'.config('database.default'));

        return [
            'host' => $backup['host'] ?: ($default['host'] ?? '127.0.0.1'),
            'username' => $backup['username'] ?: ($default['username'] ?? ''),
            'password' => $backup['password'] ?: ($default['password'] ?? null),
        ];
    }

    private function check($result, string $step): void
    {
        if (! $result->successful()) {
            throw new RuntimeException($step.': '.trim($result->errorOutput() ?: $result->output()));
        }
    }
}
