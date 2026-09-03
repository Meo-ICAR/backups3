<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class CleanBackups extends Command
{
    protected $signature = 'backup:clean';

    protected $description = 'Applica la politica di retention remota su Cloudflare R2 (14 giorni, 52 settimane, 36 mesi)';

    public function handle(): int
    {
        $this->info('Inizio pulizia retention remota su Cloudflare R2...');

        $bucket = config('filesystems.disks.r2.bucket');
        $endpoint = config('filesystems.disks.r2.endpoint');
        $key = config('filesystems.disks.r2.key');
        $secret = config('filesystems.disks.r2.secret');

        if (! $bucket || ! $endpoint || ! $key || ! $secret) {
            $this->error('Configurazione R2 mancante in config/filesystems.php');

            return self::FAILURE;
        }

        $env = [
            'AWS_ACCESS_KEY_ID' => $key,
            'AWS_SECRET_ACCESS_KEY' => $secret,
            'AWS_DEFAULT_REGION' => 'auto',
            'AWS_REQUEST_CHECKSUM_CALCULATION' => 'when_required',
            'AWS_RESPONSE_CHECKSUM_VALIDATION' => 'when_required',
        ];

        // 1. Recupero della lista file da R2 in formato JSON
        $listResult = Process::env($env)->run([
            'aws',
            '--endpoint-url='.$endpoint,
            's3api', 'list-objects-v2',
            '--bucket', $bucket,
            '--prefix', 'mysql/',
            '--query', 'Contents[].Key',
            '--output', 'json',
        ]);

        if (! $listResult->successful()) {
            $this->error('Errore durante il recupero della lista file da R2: '.$listResult->errorOutput());

            return self::FAILURE;
        }

        $files = json_decode($listResult->output(), true) ?? [];

        if (empty($files)) {
            $this->info('Nessun file trovato su R2 nella cartella mysql/.');

            return self::SUCCESS;
        }

        // 2. Raggruppamento file per database e parsing delle date
        $groupedFiles = [];
        foreach ($files as $fileKey) {
            // Parsing timestamp dal nome file: es. mysql/unicogdpr/unicogdpr_2026-09-03_13-32-39.sql.gz
            if (! preg_match('/_(\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2})\.sql\.gz$/', $fileKey, $matches)) {
                continue;
            }

            try {
                $date = Carbon::createFromFormat('Y-m-d_H-i-s', $matches[1]);
            } catch (Exception $e) {
                continue;
            }

            $dir = dirname($fileKey);
            $groupedFiles[$dir][] = [
                'key' => $fileKey,
                'date' => $date,
            ];
        }

        $deletedCount = 0;

        // 3. Applicazione delle regole di retention per ogni cartella/database
        foreach ($groupedFiles as $dir => $items) {
            // Ordina i file dal più recente al più vecchio
            usort($items, fn ($a, $b) => $b['date']->timestamp <=> $a['date']->timestamp);

            $keptDaily = [];
            $keptWeekly = [];
            $keptMonthly = [];

            foreach ($items as $item) {
                /** @var Carbon $date */
                $date = $item['date'];
                $fileKey = $item['key'];

                $diffDays = (int) $date->diffInDays(now());
                $diffWeeks = (int) $date->diffInWeeks(now());
                $diffMonths = (int) $date->diffInMonths(now());

                $keep = false;

                // Regola 1: Daily - Mantieni 1 backup al giorno per gli ultimi 14 giorni
                if ($diffDays < 14) {
                    $dayKey = $date->format('Y-m-d');
                    if (! isset($keptDaily[$dayKey])) {
                        $keptDaily[$dayKey] = true;
                        $keep = true;
                    }
                }

                // Regola 2: Weekly - Mantieni 1 backup alla settimana per le ultime 52 settimane
                if ($diffWeeks < 52) {
                    $weekKey = $date->format('Y-W');
                    if (! isset($keptWeekly[$weekKey])) {
                        $keptWeekly[$weekKey] = true;
                        $keep = true;
                    }
                }

                // Regola 3: Monthly - Mantieni 1 backup al mese per gli ultimi 36 mesi
                if ($diffMonths < 36) {
                    $monthKey = $date->format('Y-m');
                    if (! isset($keptMonthly[$monthKey])) {
                        $keptMonthly[$monthKey] = true;
                        $keep = true;
                    }
                }

                // Se il file non soddisfa nessuna delle tre regole, viene eliminato da R2
                if (! $keep) {
                    $this->line("Eliminazione file obsoleto da R2: {$fileKey}");

                    $deleteResult = Process::env($env)->run([
                        'aws',
                        '--endpoint-url='.$endpoint,
                        's3', 'rm',
                        "s3://{$bucket}/{$fileKey}",
                    ]);

                    if ($deleteResult->successful()) {
                        $deletedCount++;
                    } else {
                        $this->error("Errore durante la cancellazione di {$fileKey}: ".$deleteResult->errorOutput());
                    }
                }
            }
        }

        $this->info("Pulizia completata. Eliminati {$deletedCount} file obsoleti da Cloudflare R2.");

        return self::SUCCESS;
    }
}
