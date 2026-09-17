<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class ShowLastBackups extends Command
{
    protected $signature = 'backup:last-databases';

    protected $description = 'Mostra l\'ultimo dump disponibile per ciascun database, in locale, su R2 e sulla VPS remota';

    /** @var array<int, string> */
    private array $databases = [];

    public function handle(): int
    {
        $only = config('backup_paths.databases.only', []);
        $baseFolder = storage_path('app/backups/databases');

        $this->databases = ! empty($only)
            ? $only
            : (File::isDirectory($baseFolder)
                ? array_map('basename', File::directories($baseFolder))
                : []);

        if (empty($this->databases)) {
            $this->warn('Nessun database configurato o nessuna cartella di backup trovata.');

            return self::SUCCESS;
        }

        $this->info('--- Backup locali ---');
        $this->table(
            ['Database', 'Ultimo file', 'Data backup', 'Dimensione', 'Età', 'Stato'],
            $this->localRows($baseFolder)
        );

        $targets = config('backup_paths.sync_targets', ['r2']);

        if (in_array('r2', $targets, true)) {
            $this->info('--- Backup su Cloudflare R2 ---');
            $this->table(
                ['Database', 'Ultimo file', 'Data backup', 'Dimensione', 'Età', 'Stato'],
                $this->r2Rows()
            );
        }

        if (in_array('vps', $targets, true)) {
            $this->info('--- Backup su VPS remota ---');
            $this->table(
                ['Database', 'Ultimo file', 'Data backup', 'Dimensione', 'Età', 'Stato'],
                $this->vpsRows()
            );
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function localRows(string $baseFolder): array
    {
        $rows = [];

        foreach ($this->databases as $dbName) {
            $dbFolder = "{$baseFolder}/{$dbName}";

            if (! File::isDirectory($dbFolder)) {
                $rows[] = [$dbName, '-', '-', '-', '-', 'MAI ESEGUITO'];

                continue;
            }

            $files = collect(File::files($dbFolder))->sortByDesc(fn ($file) => $file->getMTime());

            if ($files->isEmpty()) {
                $rows[] = [$dbName, '-', '-', '-', '-', 'MAI ESEGUITO'];

                continue;
            }

            $latest = $files->first();
            $mtime = now()->createFromTimestamp($latest->getMTime());

            $rows[] = $this->row($dbName, $latest->getFilename(), $mtime, $latest->getSize());
        }

        return $rows;
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function r2Rows(): array
    {
        $bucket = config('filesystems.disks.r2.bucket');
        $endpoint = config('filesystems.disks.r2.endpoint');
        $key = config('filesystems.disks.r2.key');
        $secret = config('filesystems.disks.r2.secret');

        if (! $bucket || ! $endpoint || ! $key || ! $secret) {
            return [['-', '-', '-', '-', '-', 'CONFIGURAZIONE R2 MANCANTE']];
        }

        $prefix = trim((string) config('backup_paths.destination_prefix'), '/');
        $remoteKey = $prefix !== '' ? "{$prefix}/databases" : 'databases';

        $result = Process::env([
            'AWS_ACCESS_KEY_ID' => $key,
            'AWS_SECRET_ACCESS_KEY' => $secret,
            'AWS_DEFAULT_REGION' => 'auto',
            'AWS_REQUEST_CHECKSUM_CALCULATION' => 'when_required',
            'AWS_RESPONSE_CHECKSUM_VALIDATION' => 'when_required',
        ])->timeout(30)->run([
            'aws',
            '--endpoint-url='.$endpoint,
            's3', 'ls',
            "s3://{$bucket}/{$remoteKey}/",
            '--recursive',
        ]);

        // "aws s3 ls" ritorna exit 1 senza output quando il prefisso non ha ancora file:
        // non è un errore, va trattato come "nessun backup trovato".
        $hasRealError = ! $result->successful()
            && (trim($result->output()) !== '' || trim($result->errorOutput()) !== '');

        if ($hasRealError) {
            return [['-', '-', '-', '-', '-', 'ERRORE: '.trim($result->errorOutput())]];
        }

        $latestPerDb = [];
        $remoteKeyPattern = preg_quote($remoteKey, '/');

        foreach (explode("\n", trim($result->output())) as $line) {
            // Formato riga: "2024-01-01 12:00:00        12345 <prefisso>/databases/dbname/file.sql.gz"
            if (! preg_match('/^(\S+)\s+(\S+)\s+(\d+)\s+'.$remoteKeyPattern.'\/([^\/]+)\/(.+)$/', trim($line), $m)) {
                continue;
            }

            [, $date, $time, $size, $dbName, $filename] = $m;
            $mtime = Carbon::parse("{$date} {$time}");

            if (! isset($latestPerDb[$dbName]) || $mtime->gt($latestPerDb[$dbName]['mtime'])) {
                $latestPerDb[$dbName] = ['mtime' => $mtime, 'size' => (int) $size, 'filename' => $filename];
            }
        }

        $rows = [];

        foreach ($this->databases as $dbName) {
            if (! isset($latestPerDb[$dbName])) {
                $rows[] = [$dbName, '-', '-', '-', '-', 'MAI SINCRONIZZATO'];

                continue;
            }

            $entry = $latestPerDb[$dbName];
            $rows[] = $this->row($dbName, $entry['filename'], $entry['mtime'], $entry['size']);
        }

        return $rows;
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function vpsRows(): array
    {
        $host = config('backup_paths.remote.host');
        $user = config('backup_paths.remote.user');
        $password = config('backup_paths.remote.password');
        $remotePath = config('backup_paths.remote.path');
        $port = config('backup_paths.remote.port', 22);

        if (! $host || ! $user || ! $password || ! $remotePath) {
            return [['-', '-', '-', '-', '-', 'CONFIGURAZIONE VPS MANCANTE']];
        }

        $prefix = trim((string) config('backup_paths.destination_prefix'), '/');
        $remoteDir = $prefix !== '' ? "{$remotePath}/{$prefix}/databases" : "{$remotePath}/databases";

        $result = Process::env(['SSHPASS' => $password])
            ->timeout(30)
            ->run([
                'sshpass', '-e',
                'ssh',
                '-o', 'StrictHostKeyChecking=accept-new',
                '-o', 'ConnectTimeout=8',
                '-p', (string) $port,
                "{$user}@{$host}",
                "find '{$remoteDir}' -maxdepth 2 -type f -printf '%T@|%s|%p\\n' 2>/dev/null || true",
            ]);

        if (! $result->successful()) {
            return [['-', '-', '-', '-', '-', 'ERRORE SSH: '.trim($result->errorOutput())]];
        }

        $latestPerDb = [];

        foreach (explode("\n", trim($result->output())) as $line) {
            if (trim($line) === '') {
                continue;
            }

            [$epoch, $size, $path] = array_pad(explode('|', trim($line), 3), 3, null);

            if ($epoch === null || $path === null) {
                continue;
            }

            $dbName = basename(dirname($path));
            $mtime = now()->createFromTimestamp((int) $epoch);

            if (! isset($latestPerDb[$dbName]) || $mtime->gt($latestPerDb[$dbName]['mtime'])) {
                $latestPerDb[$dbName] = ['mtime' => $mtime, 'size' => (int) $size, 'filename' => basename($path)];
            }
        }

        $rows = [];

        foreach ($this->databases as $dbName) {
            if (! isset($latestPerDb[$dbName])) {
                $rows[] = [$dbName, '-', '-', '-', '-', 'MAI SINCRONIZZATO'];

                continue;
            }

            $entry = $latestPerDb[$dbName];
            $rows[] = $this->row($dbName, $entry['filename'], $entry['mtime'], $entry['size']);
        }

        return $rows;
    }

    /**
     * @return array<int, string>
     */
    private function row(string $dbName, string $filename, Carbon $mtime, int $sizeBytes): array
    {
        return [
            $dbName,
            $filename,
            $mtime->format('Y-m-d H:i:s'),
            round($sizeBytes / 1024, 2).' KB',
            $mtime->diffForHumans(),
            $mtime->lt(now()->subHours(26)) ? 'VECCHIO (>26h)' : 'OK',
        ];
    }
}
