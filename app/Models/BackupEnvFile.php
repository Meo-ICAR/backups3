<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Copia attuale del file .env di un'app su Cloudflare R2. */
class BackupEnvFile extends Model
{
    protected $table = 'backupenv_files';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'backed_up_at' => 'datetime',
            'r2_last_modified_at' => 'datetime',
            'size_bytes' => 'integer',
        ];
    }

    public function backupEnv(): BelongsTo
    {
        return $this->belongsTo(BackupEnv::class, 'backupenv_id');
    }

    public function restores(): HasMany
    {
        return $this->hasMany(BackupEnvRestore::class, 'backupenv_file_id');
    }
}
