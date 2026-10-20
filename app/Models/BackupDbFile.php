<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Un backup presente su Cloudflare R2. */
class BackupDbFile extends Model
{
    protected $table = 'backupdb_files';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'backed_up_at' => 'datetime',
            'r2_last_modified_at' => 'datetime',
            'size_bytes' => 'integer',
        ];
    }

    public function backupDb(): BelongsTo
    {
        return $this->belongsTo(BackupDb::class, 'backupdb_id');
    }

    public function restores(): HasMany
    {
        return $this->hasMany(BackupDbRestore::class, 'backupdb_file_id');
    }
}
