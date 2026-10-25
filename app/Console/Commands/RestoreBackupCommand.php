<?php

namespace App\Console\Commands;

use App\Jobs\RestoreBackup;
use App\Models\BackupDb;
use App\Models\BackupDbFile;
use App\Models\BackupDbRestore;
use Illuminate\Console\Command;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class RestoreBackupCommand extends Command
{
    protected $signature = 'backup:restore
        {database? : Database del backup (senza, elenca i backup disponibili)}
        {--server= : Server del backup, cioè il prefisso su R2 (default: questo server)}
        {--backup= : Data o nome del file del backup (es. 2026-10-25 o 2026-10-25_13-18-37); default l\'ultimo}
        {--target= : Database di destinazione su questo server (default: <database>_restore)}
        {--overwrite : Ripristina anche se il database di destinazione ha già delle tabelle: prima ne salva un dump}
        {--sync : Aggiorna prima da R2 l\'elenco dei backup}
        {--yes : Non chiede conferma}';

    protected $description = 'Ripristina da R2 un backup (server e database a scelta) in un database di questo server, come la voce Ripristina del pannello';

    public function handle(): int
    {
        if ($this->option('sync')) {
            Artisan::call('backup:sync-remote');
        }

        $server = (string) ($this->option('server') ?: config('backup_paths.destination_prefix'));
        $nome = $this->argument('database');

        if ($nome === null) {
            return $this->elenco();
        }

        $backupDb = BackupDb::query()->where('server_name', $server)->where('database_name', $nome)->first();

        if ($backupDb === null) {
            $this->error("Nessun backup di «{$nome}» per il server «{$server}». Usa il comando senza database per vedere l'elenco (o --sync per aggiornarlo da R2).");

            return self::FAILURE;
        }

        $file = $this->file($backupDb);

        if ($file === null) {
            $this->error('Nessun backup corrisponde a «'.$this->option('backup').'».');

            return self::FAILURE;
        }

        $target = (string) ($this->option('target') ?: "{$nome}_restore");

        if (! preg_match('/^[A-Za-z0-9_]+$/', $target) || strlen($target) > 64 || in_array($target, ['mysql', 'sys', 'information_schema', 'performance_schema'], true)) {
            $this->error('Nome del database di destinazione non valido.');

            return self::FAILURE;
        }

        $tabelle = $this->tabelle($target);

        if ($tabelle > 0 && ! $this->option('overwrite')) {
            $this->error("«{$target}» ha già {$tabelle} tabelle: usa un altro --target oppure --overwrite per sostituirle (se ne salva prima un dump).");

            return self::FAILURE;
        }

        $this->line("Backup: {$server} / {$nome} del {$file->backed_up_at->format('d/m/Y H:i')} (".number_format($file->size_bytes / 1024, 0, ',', '.').' KB)');
        $this->line("Destinazione: «{$target}» su questo server".($tabelle > 0 ? " — {$tabelle} tabelle da sostituire" : ' — nuovo database'));

        if ($tabelle > 0) {
            if (! $this->option('yes') && ! $this->confirmaNome($target)) {
                $this->warn('Annullato.');

                return self::FAILURE;
            }

            $dump = $this->dumpPrima($target);

            if ($dump === null) {
                return self::FAILURE;
            }

            $this->info("Dump di sicurezza di «{$target}» salvato in {$dump}");
        } elseif (! $this->option('yes') && ! $this->confirm('Avviare il ripristino?', true)) {
            $this->warn('Annullato.');

            return self::FAILURE;
        }

        // Da riga di comando si può girare con un utente diverso da quello del pannello: se la cartella dei ripristini non è scrivibile se ne usa un'altra.
        if (! is_writable((string) config('backup_paths.restore_directory'))) {
            config(['backup_paths.restore_directory' => storage_path('app/backups/restores-cli')]);
        }

        $restore = BackupDbRestore::query()->create([
            'backupdb_file_id' => $file->id,
            'target_database' => $target,
            'overwrites_original' => $backupDb->isOnThisServer() && $target === $nome,
            'status' => BackupDbRestore::PENDING,
        ]);

        $this->info('Ripristino in corso (download da R2 e caricamento del dump)…');
        RestoreBackup::dispatchSync($restore);
        $restore->refresh();

        if ($restore->status !== BackupDbRestore::DONE) {
            $this->error('Ripristino fallito: '.($restore->error ?: 'errore sconosciuto'));

            return self::FAILURE;
        }

        $this->info("Ripristino completato in «{$target}» ({$this->tabelle($target)} tabelle).");

        return self::SUCCESS;
    }

    private function elenco(): int
    {
        $righe = BackupDb::query()->with('latestFile')->orderBy('server_name')->orderBy('database_name')->get()
            ->filter(fn (BackupDb $backupDb): bool => $backupDb->latestFile !== null)
            ->map(fn (BackupDb $backupDb): array => [$backupDb->server_name, $backupDb->database_name, $backupDb->latestFile->backed_up_at->format('d/m/Y H:i'), $backupDb->files()->count()]);

        $this->table(['Server', 'Database', 'Ultimo backup', 'Backup'], $righe->all());
        $this->line('Esempio: php artisan backup:restore unicosuite --server=<server> --target=<database> [--backup=2026-10-25] [--overwrite]');

        return self::SUCCESS;
    }

    private function file(BackupDb $backupDb): ?BackupDbFile
    {
        $scelta = (string) $this->option('backup');

        if ($scelta === '') {
            return $backupDb->latestFile()->first();
        }

        return $backupDb->files()->orderByDesc('backed_up_at')->get()->first(fn (BackupDbFile $file): bool => str_contains($file->r2_key, $scelta) || str_contains($file->backed_up_at->format('Y-m-d_H-i-s'), $scelta));
    }

    private function confirmaNome(string $target): bool
    {
        return $this->ask("Il database «{$target}» verrà sovrascritto. Per confermare scrivi il suo nome") === $target;
    }

    private function mysql(array $comando): ProcessResult
    {
        $credenziali = RestoreBackup::credentials();

        return Process::env(['MYSQL_PWD' => (string) $credenziali['password']])->run([$comando[0], '--host='.$credenziali['host'], '--user='.$credenziali['username'], ...array_slice($comando, 1)]);
    }

    private function tabelle(string $database): int
    {
        $risultato = $this->mysql(['mysql', '-N', '-e', "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '{$database}' AND table_type = 'BASE TABLE'"]);

        return $risultato->successful() ? (int) trim($risultato->output()) : 0;
    }

    /** Il dump del database che si sta per sostituire, in storage/app/backups/pre-restore. */
    private function dumpPrima(string $database): ?string
    {
        $cartella = storage_path('app/backups/pre-restore');
        File::ensureDirectoryExists($cartella);
        $percorso = $cartella.'/'.$database.'_'.now()->format('Y-m-d_H-i-s').'.sql.gz';
        $credenziali = RestoreBackup::credentials();

        $risultato = Process::env(['MYSQL_PWD' => (string) $credenziali['password']])->timeout(3600)->run([
            'bash', '-o', 'pipefail', '-c', 'mysqldump --host="$1" --user="$2" --single-transaction --routines --triggers "$3" | gzip > "$4"', 'dump', $credenziali['host'], $credenziali['username'], $database, $percorso,
        ]);

        if (! $risultato->successful() || ! File::exists($percorso) || filesize($percorso) < 100) {
            File::delete($percorso);
            $this->error('Dump di sicurezza non riuscito, ripristino annullato: '.trim($risultato->errorOutput()));

            return null;
        }

        return $percorso;
    }
}
