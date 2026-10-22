<?php

namespace App\Console\Commands;

use App\Models\BackupDb;
use App\Models\BackupDbFile;
use App\Models\BackupEnv;
use App\Models\BackupEnvFile;
use App\Services\R2Cli;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncRemoteBackups extends Command
{
    protected $signature = 'backup:sync-remote';

    protected $description = 'Legge da Cloudflare R2 i backup dei database e dei file .env (data e dimensione) e li registra nel database per il pannello';

    public function handle(R2Cli $r2): int
    {
        if (! $r2->isConfigured()) {
            $this->error('Configurazione R2 mancante in config/filesystems.php (disco "r2").');

            return self::FAILURE;
        }

        $result = $r2->listObjects();

        if (! $result->successful()) {
            $this->error('Errore durante il recupero della lista da R2: '.$result->errorOutput());

            return self::FAILURE;
        }

        // Un database trovato solo su R2 si registra non attivo: non va aggiunto al backup notturno finché non lo si decide.
        // Es. {server}/databases/unicogdpr/unicogdpr_2026-09-03_13-32-39.sql.gz (senza server: quello di questa macchina)
        $pattern = '#^(?:(?<server>[^/]+)/)?databases/(?<db>[^/]+)/[^/]+_(?<ts>\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2})\.sql\.gz$#';
        $envPattern = '#^(?:(?<server>[^/]+)/)?env/(?<app>[A-Za-z0-9._-]+)/\.env$#';
        $thisServer = (string) config('backup_paths.destination_prefix');
        $seen = [];
        $envSeen = [];
        $envKeys = [];

        DB::transaction(function () use ($result, $pattern, $envPattern, $thisServer, &$seen, &$envSeen, &$envKeys): void {
            foreach (json_decode($result->output(), true) ?? [] as [$key, $size, $modified]) {
                if (preg_match($pattern, $key, $m)) {
                    $backupDb = BackupDb::query()->firstOrCreate(
                        ['server_name' => $m['server'] ?: $thisServer, 'database_name' => $m['db']],
                        ['is_active' => false],
                    );
                    $seen[$backupDb->id] = true;

                    BackupDbFile::query()->updateOrCreate(['r2_key' => $key], [
                        'backupdb_id' => $backupDb->id,
                        'backed_up_at' => Carbon::createFromFormat('Y-m-d_H-i-s', $m['ts']),
                        'size_bytes' => (int) $size,
                        'r2_last_modified_at' => $modified ? Carbon::parse($modified) : null,
                    ]);

                    continue;
                }

                if (! preg_match($envPattern, $key, $m) || in_array($m['app'], ['.', '..'], true)) {
                    continue;
                }

                $backupEnv = BackupEnv::query()->firstOrCreate(
                    ['server_name' => $m['server'] ?: $thisServer, 'app_name' => $m['app']],
                );
                $envSeen[$backupEnv->id] = true;
                $envKeys[] = $key;
                $modifiedAt = $modified ? Carbon::parse($modified) : now();

                BackupEnvFile::query()->updateOrCreate(['r2_key' => $key], [
                    'backupenv_id' => $backupEnv->id,
                    'backed_up_at' => $modifiedAt,
                    'size_bytes' => (int) $size,
                    'r2_last_modified_at' => $modified ? $modifiedAt : null,
                ]);
            }
        });

        $present = collect(json_decode($result->output(), true) ?? [])->pluck(0)->all();
        $removed = BackupDbFile::query()->whereNotIn('r2_key', $present)->delete();
        $removedEnv = $envKeys === [] ? 0 : BackupEnvFile::query()->whereNotIn('r2_key', $envKeys)->delete();

        BackupDb::query()->whereIn('id', array_keys($seen))->update(['last_synced_at' => now()]);
        BackupEnv::query()->whereIn('id', array_keys($envSeen))->update(['last_synced_at' => now()]);

        $this->info(count($present).' oggetti letti da R2, '.BackupDbFile::query()->count().' backup di database registrati, '.$removed.' non più presenti, '.BackupEnvFile::query()->count().' file .env registrati, '.$removedEnv.' non più presenti.');

        return self::SUCCESS;
    }
}
