<?php

namespace App\Services;

use App\Models\BackupDb;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/** Riconosce se il dump di un database è uguale a quello già salvato su R2, per non ricaricarlo. */
class DumpChangeDetector
{
    /**
     * SHA-256 del dump non compresso. La riga «-- Dump completed on <data>» cambia a ogni esecuzione e si ignora, come l'intestazione del gzip
     * (per questo non si confronta il file compresso).
     */
    public function hash(string $gzipFile): string
    {
        $result = Process::run([
            'bash', '-o', 'pipefail', '-c', 'gzip -dc "$1" | grep -v "^-- Dump completed on" | sha256sum | cut -d" " -f1', 'hash', $gzipFile,
        ]);

        $hash = trim($result->output());

        if (! $result->successful() || ! preg_match('/^[0-9a-f]{64}$/', $hash)) {
            throw new RuntimeException('Impossibile calcolare l\'hash del dump: '.trim($result->errorOutput()));
        }

        return $hash;
    }

    /**
     * Il dump si salta solo se il database lo chiede, l'hash coincide e su R2 c'è almeno un suo backup: se l'ultimo è stato tolto dalla retention
     * o non è mai arrivato, si salva comunque.
     */
    public function isUnchanged(?BackupDb $backupDb, string $hash): bool
    {
        return $backupDb !== null
            && $backupDb->only_if_changed
            && $backupDb->last_dump_hash === $hash
            && $backupDb->files()->exists();
    }
}
