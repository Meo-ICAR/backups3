<?php

namespace App\Jobs;

use App\Models\BackupEnvRestore;
use App\Services\R2Cli;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

/** Scarica da R2 il file .env di un'app e lo scrive nella sua cartella, senza registrarne il contenuto. */
class RestoreEnvFile
{
    use Dispatchable;

    public int $timeout = 120;

    public int $tries = 1;

    private const MAX_BYTES = 1_048_576;

    public function __construct(public BackupEnvRestore $restore) {}

    public function handle(R2Cli $r2): void
    {
        set_time_limit($this->timeout);
        ignore_user_abort(true);

        $restore = $this->restore->refresh();
        $restore->update(['status' => BackupEnvRestore::RUNNING, 'started_at' => now(), 'finished_at' => null, 'error' => null]);

        $folder = rtrim((string) config('backup_paths.restore_directory'), '/')."/env-{$restore->id}";
        $downloaded = $folder.'/.env';
        $temporary = null;

        try {
            if (! $r2->isConfigured()) {
                throw new RuntimeException('Configurazione R2 mancante in config/filesystems.php (disco "r2").');
            }

            $destination = $this->destination($restore);

            if ($restore->file->size_bytes > self::MAX_BYTES) {
                throw new RuntimeException('Il file su R2 è troppo grande per essere un .env.');
            }

            File::makeDirectory($folder, 0755, true, true);
            $this->check($r2->download($restore->file->r2_key, $downloaded), 'Download da R2');

            if (! is_file($downloaded) || filesize($downloaded) === false || filesize($downloaded) > self::MAX_BYTES) {
                throw new RuntimeException('Il file scaricato da R2 non è utilizzabile.');
            }

            $temporary = $destination.'.part-'.$restore->id;

            if (! File::copy($downloaded, $temporary)) {
                throw new RuntimeException('Copia del file .env non riuscita.');
            }

            @chmod($temporary, 0660);

            if (! rename($temporary, $destination)) {
                throw new RuntimeException('Copia del file .env non riuscita.');
            }

            @chmod($destination, 0660);
            $restore->update(['status' => BackupEnvRestore::DONE, 'finished_at' => now()]);
        } catch (Throwable $e) {
            $restore->update(['status' => BackupEnvRestore::FAILED, 'finished_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 2000)]);
        } finally {
            if (is_string($temporary) && is_file($temporary)) {
                File::delete($temporary);
            }

            File::deleteDirectory($folder);
        }
    }

    private function destination(BackupEnvRestore $restore): string
    {
        $app = $restore->file->backupEnv;
        $name = $app->app_name;
        $target = $restore->target_file;
        $overwrite = $restore->overwrites_original;

        if (! preg_match('/^[A-Za-z0-9._-]+$/', $name) || $name === '.' || $name === '..') {
            throw new RuntimeException('Nome dell\'app non valido.');
        }

        if (! in_array($target, [BackupEnvRestore::COPY, BackupEnvRestore::ORIGINAL], true)) {
            throw new RuntimeException('Destinazione del ripristino non valida.');
        }

        if ($overwrite !== ($target === BackupEnvRestore::ORIGINAL) || ($overwrite && ! $app->isOnThisServer())) {
            throw new RuntimeException('Il file .env in uso si sostituisce solo per un\'app di questo server.');
        }

        $root = realpath((string) config('backup_paths.env.root'));
        $directory = $root === false ? false : realpath($root.'/'.$name);

        if ($root === false || $directory === false || ! is_dir($directory) || ! str_starts_with($directory, $root.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Cartella dell\'app assente su questo server.');
        }

        $destination = $directory.DIRECTORY_SEPARATOR.$target;

        if (is_link($destination)) {
            throw new RuntimeException('Il file di destinazione è un collegamento e non si sovrascrive.');
        }

        if (is_file($destination)) {
            $existing = realpath($destination);
            if ($existing === false || dirname($existing) !== $directory) {
                throw new RuntimeException('Il file di destinazione è fuori dalla cartella dell\'app.');
            }
        }

        return $destination;
    }

    private function check($result, string $step): void
    {
        if (! $result->successful()) {
            throw new RuntimeException($step.': '.trim($result->errorOutput() ?: $result->output()));
        }
    }
}
