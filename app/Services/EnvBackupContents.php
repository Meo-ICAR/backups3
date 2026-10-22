<?php

namespace App\Services;

use App\Models\BackupEnv;
use Illuminate\Support\Facades\File;
use RuntimeException;

/** Legge la copia del .env su R2, senza registrarne il contenuto. */
class EnvBackupContents
{
    private const MAX_BYTES = 1_048_576;

    public function __construct(private R2Cli $r2) {}

    public function for(BackupEnv $app): string
    {
        $file = $app->latestFile;

        if ($file === null) {
            throw new RuntimeException('Nessuna copia su R2.');
        }

        if ($file->size_bytes > self::MAX_BYTES) {
            throw new RuntimeException('Il file su R2 è troppo grande per essere mostrato.');
        }

        $key = $file->r2_key;

        if (! preg_match('#^(?:(?<server>[^/]+)/)?env/(?<app>[A-Za-z0-9._-]+)/\.env$#', $key, $matches)
            || in_array($matches['app'], ['.', '..'], true)
            || str_contains($matches['server'] ?? '', '..')) {
            throw new RuntimeException('Percorso del backup non valido.');
        }

        if (! $this->r2->isConfigured()) {
            throw new RuntimeException('Configurazione R2 mancante in config/filesystems.php (disco "r2").');
        }

        $folder = storage_path('app/backup-env-read-'.uniqid());
        $downloaded = $folder.'/.env';

        try {
            File::makeDirectory($folder, 0700, true);
            $result = $this->r2->download($key, $downloaded);

            if (! $result->successful() || ! is_file($downloaded)) {
                throw new RuntimeException('Copia su R2 non leggibile.');
            }

            $size = filesize($downloaded);

            if ($size === false || $size > self::MAX_BYTES) {
                throw new RuntimeException('Il file su R2 è troppo grande per essere mostrato.');
            }

            $contents = file_get_contents($downloaded);

            if ($contents === false) {
                throw new RuntimeException('Copia su R2 non leggibile.');
            }

            return $contents;
        } finally {
            File::deleteDirectory($folder);
        }
    }
}
