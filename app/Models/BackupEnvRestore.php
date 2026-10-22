<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Richiesta di ripristino di un file .env da R2 nella cartella dell'app. */
class BackupEnvRestore extends Model
{
    public const PENDING = 'pending';

    public const RUNNING = 'running';

    public const DONE = 'done';

    public const FAILED = 'failed';

    public const COPY = '.env.restore';

    public const ORIGINAL = '.env';

    protected $table = 'backupenv_restores';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'overwrites_original' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(BackupEnvFile::class, 'backupenv_file_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
