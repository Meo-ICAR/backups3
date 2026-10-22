<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class BackupEnvFiles extends Command
{
    protected $signature = 'backup:env';

    protected $description = 'Copia i file .env delle app del server e sincronizza solo quelli il cui contenuto è cambiato, senza stamparlo';

    public function handle(): int
    {
        $root = rtrim((string) config('backup_paths.env.root'), '/');

        if ($root === '' || ! is_dir($root)) {
            $this->error("Cartella delle app assente: {$root}");

            return self::FAILURE;
        }

        $files = $this->envFiles($root);

        if ($files === []) {
            $this->warn("Nessun file .env trovato in {$root}");

            return self::SUCCESS;
        }

        $previous = $this->storedHashes();
        $changed = [];
        $failed = false;

        foreach ($files as $app => $path) {
            $hash = hash_file('sha256', $path);

            if ($hash === false) {
                $this->error("Copia non riuscita per [{$app}]");
                $failed = true;

                continue;
            }

            if (($previous[$app] ?? null) === $hash) {
                $this->info("Invariato [{$app}]");

                continue;
            }

            $changed[$app] = $hash;
        }

        if ($changed === []) {
            $this->info('Nessun .env variato: non si sincronizza.');

            return $failed ? self::FAILURE : self::SUCCESS;
        }

        $staging = (string) config('backup_paths.env.staging');
        File::deleteDirectory($staging);
        File::ensureDirectoryExists($staging, 0770, true);
        @chmod($staging, 0770);

        foreach ($changed as $app => $hash) {
            $destination = $staging.'/'.$app;
            File::ensureDirectoryExists($destination, 0770, true);

            if (! File::copy($files[$app], $destination.'/.env')) {
                $this->error("Copia non riuscita per [{$app}]");
                unset($changed[$app]);
                $failed = true;

                continue;
            }

            // Come i dump: leggibile dal gruppo dello sviluppo, non dagli altri utenti della macchina.
            @chmod($destination, 0770);
            @chmod($destination.'/.env', 0660);
            $this->info("Copiato [{$app}]");
        }

        if ($changed === []) {
            return self::FAILURE;
        }

        $targets = config('backup_paths.sync_targets', ['r2']);
        $synced = false;

        if (in_array('r2', $targets, true) && ! $this->syncToR2($staging)) {
            $failed = true;
        } elseif (in_array('r2', $targets, true)) {
            $synced = true;
        }

        if (in_array('vps', $targets, true) && ! $this->syncToRemoteVps($staging)) {
            $failed = true;
        } elseif (in_array('vps', $targets, true)) {
            $synced = true;
        }

        // L'impronta si registra solo dopo l'invio: se fallisce, al giro dopo il file risulta ancora variato.
        if (! $failed && $synced) {
            $this->storeHashes(array_merge($previous, $changed));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Solo il file `.env` di ogni app subito sotto la cartella radice. `.env.example` resta nel repository e non si salva.
     *
     * @return array<string, string>
     */
    public function envFiles(string $root): array
    {
        $files = [];

        foreach (File::directories($root) as $directory) {
            $app = basename($directory);

            if (! preg_match('/^[A-Za-z0-9._-]+$/', $app) || ! is_file($directory.'/.env')) {
                continue;
            }

            $files[$app] = $directory.'/.env';
        }

        ksort($files);

        return $files;
    }

    private function syncToR2(string $staging): bool
    {
        $bucket = config('filesystems.disks.r2.bucket');
        $endpoint = config('filesystems.disks.r2.endpoint');

        if (! $bucket || ! $endpoint || ! config('filesystems.disks.r2.key') || ! config('filesystems.disks.r2.secret')) {
            $this->error('Configurazione R2 mancante in config/filesystems.php (disco "r2").');

            return false;
        }

        $prefix = trim((string) config('backup_paths.destination_prefix'), '/');
        $remoteKey = $prefix !== '' ? "{$prefix}/env" : 'env';

        $this->info("Sincronizzazione dei file .env verso s3://{$bucket}/{$remoteKey}");

        $result = Process::env([
            'AWS_ACCESS_KEY_ID' => config('filesystems.disks.r2.key'),
            'AWS_SECRET_ACCESS_KEY' => config('filesystems.disks.r2.secret'),
            'AWS_DEFAULT_REGION' => 'auto',
            'AWS_REQUEST_CHECKSUM_CALCULATION' => 'when_required',
            'AWS_RESPONSE_CHECKSUM_VALIDATION' => 'when_required',
        ])->timeout((int) config('backup_paths.sync_timeout', 3600))->run([
            'aws',
            '--endpoint-url='.$endpoint,
            's3', 'sync',
            $staging,
            "s3://{$bucket}/{$remoteKey}",
            '--only-show-errors',
        ]);

        if (! $result->successful()) {
            $this->error('Errore Sync R2: '.$result->errorOutput());

            return false;
        }

        return true;
    }

    private function syncToRemoteVps(string $staging): bool
    {
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
        $remoteDir = $prefix !== '' ? "{$remotePath}/{$prefix}/env" : "{$remotePath}/env";

        $this->info("Sincronizzazione dei file .env verso {$user}@{$host}:{$remoteDir}");

        $result = Process::env(['SSHPASS' => $password])
            ->timeout((int) config('backup_paths.sync_timeout', 3600))
            ->run([
                'sshpass', '-e',
                'rsync', '-az', '--mkpath',
                '-e', "ssh -o StrictHostKeyChecking=accept-new -p {$port}",
                rtrim($staging, '/').'/',
                "{$user}@{$host}:{$remoteDir}/",
            ]);

        if (! $result->successful()) {
            $this->error('Errore Sync VPS remota: '.$result->errorOutput());

            return false;
        }

        return true;
    }

    /**
     * Impronte SHA-256 dell'ultimo invio riuscito. Il file non contiene il testo dei .env.
     *
     * @return array<string, string>
     */
    private function storedHashes(): array
    {
        $path = (string) config('backup_paths.env.state');

        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) File::get($path), true);

        if (! is_array($decoded)) {
            return [];
        }

        $hashes = [];

        foreach ($decoded as $app => $hash) {
            if (is_string($app) && is_string($hash) && preg_match('/^[0-9a-f]{64}$/', $hash)) {
                $hashes[$app] = $hash;
            }
        }

        return $hashes;
    }

    /**
     * @param  array<string, string>  $hashes
     */
    private function storeHashes(array $hashes): void
    {
        $path = (string) config('backup_paths.env.state');
        File::ensureDirectoryExists(dirname($path), 0770, true);
        File::put($path, json_encode($hashes, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        @chmod($path, 0660);
    }
}
