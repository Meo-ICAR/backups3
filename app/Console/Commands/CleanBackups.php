<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class CleanBackups extends Command
{
    protected $signature = 'backup:clean';

    protected $description = 'Ruota ed elimina i backup MySQL obsoleti presenti su Cloudflare R2';

    public function handle(): int
    {
        $bucket = env('R2_BUCKET');
        $endpoint = env('R2_ENDPOINT');

        // Elenca i file dal bucket
        $result = Process::withEnv([
            'AWS_ACCESS_KEY_ID' => env('R2_ACCESS_KEY_ID'),
            'AWS_SECRET_ACCESS_KEY' => env('R2_SECRET_ACCESS_KEY'),
            'AWS_DEFAULT_REGION' => 'auto',
        ])->run([
            'aws', 's3api', 'list-objects-v2',
            '--bucket', $bucket,
            '--prefix', 'databases/',
            '--endpoint-url', $endpoint,
            '--query', 'Contents[].Key',
            '--output', 'text',
        ]);

        if (! $result->successful()) {
            $this->error('Impossibile recuperare lista file da R2');

            return self::FAILURE;
        }

        $files = array_filter(explode("\t", trim($result->output())));

        foreach ($files as $file) {
            // Estrai data dal nome file (es: databases/app1/app1_2026-09-03_02-00-00.sql.gz)
            if (! preg_match('/_(\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2})\.sql\.gz$/', $file, $matches)) {
                continue;
            }

            $date = Carbon::createFromFormat('Y-m-d_H-i-s', $matches[1]);

            if ($this->shouldDelete($date)) {
                $this->info("Eliminazione backup obsoleto su R2: {$file}");

                Process::withEnv([
                    'AWS_ACCESS_KEY_ID' => env('R2_ACCESS_KEY_ID'),
                    'AWS_SECRET_ACCESS_KEY' => env('R2_SECRET_ACCESS_KEY'),
                    'AWS_DEFAULT_REGION' => 'auto',
                ])->run([
                    'aws', 's3', 'rm',
                    "s3://{$bucket}/{$file}",
                    '--endpoint-url', $endpoint,
                ]);
            }
        }

        return self::SUCCESS;
    }

    private function shouldDelete(Carbon $date): bool
    {
        $now = now();
        $diffInDays = $date->diffInDays($now);
        $diffInMonths = $date->diffInMonths($now);

        // 1. Conserva tutti i backup giornalieri fino a 14 giorni
        if ($diffInDays <= 14) {
            return false;
        }

        // 2. Oltre i 36 mesi (3 anni) -> Elimina sempre
        if ($diffInMonths > 36) {
            return true;
        }

        // 3. Tra 12 mesi e 36 mesi -> Conserva solo il 1° del mese (Mensile)
        if ($diffInMonths > 12) {
            return $date->day !== 1;
        }

        // 4. Tra 14 giorni e 12 mesi -> Conserva la Domenica (Settimanale) oppure il 1° del mese
        return ! ($date->isSunday() || $date->day === 1);
    }
}
