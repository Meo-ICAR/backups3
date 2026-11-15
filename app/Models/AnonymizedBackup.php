<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Copia anonimizzata di un database, prodotta su richiesta con backup:anonymized. */
class AnonymizedBackup extends Model
{
    use HasFactory;

    public const PENDING = 'pending';

    public const RUNNING = 'running';

    public const DONE = 'done';

    public const FAILED = 'failed';

    protected $table = 'anonymized_backups';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'report' => 'array',
            'size_bytes' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** Numero di colonne in cui si sono sostituiti i dati, dal rapporto. */
    public function replacedColumnsCount(): ?int
    {
        return isset($this->report['columns']) ? count($this->report['columns']) : null;
    }

    /** Esito del controllo finale: true se non è rimasto nulla, false se sono rimasti residui, null se non eseguito. */
    public function scanIsClean(): ?bool
    {
        return $this->report['scan']['clean'] ?? null;
    }
}
