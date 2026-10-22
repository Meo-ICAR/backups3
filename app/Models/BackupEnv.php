<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** File .env di un'app, salvato su R2 da un dato server. */
class BackupEnv extends Model
{
    protected $table = 'backupenv';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'last_synced_at' => 'datetime',
        ];
    }

    public function files(): HasMany
    {
        return $this->hasMany(BackupEnvFile::class, 'backupenv_id');
    }

    public function latestFile(): HasOne
    {
        return $this->hasOne(BackupEnvFile::class, 'backupenv_id')->latestOfMany('backed_up_at');
    }

    public function restores(): HasManyThrough
    {
        return $this->hasManyThrough(
            BackupEnvRestore::class,
            BackupEnvFile::class,
            'backupenv_id',
            'backupenv_file_id',
        );
    }

    /** Il file si scrive solo sul server su cui gira questa app. */
    public function isOnThisServer(): bool
    {
        return $this->server_name === config('backup_paths.destination_prefix');
    }
}
