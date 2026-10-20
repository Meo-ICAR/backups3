<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Database di cui si fa il backup, su un dato server. */
class BackupDb extends Model
{
    protected $table = 'backupdb';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'only_if_changed' => 'boolean',
            'last_checked_at' => 'datetime',
            'last_changed_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function files(): HasMany
    {
        return $this->hasMany(BackupDbFile::class, 'backupdb_id');
    }

    public function latestFile(): HasOne
    {
        return $this->hasOne(BackupDbFile::class, 'backupdb_id')->latestOfMany('backed_up_at');
    }

    /**
     * Database da salvare su questo server, secondo la tabella. Se la tabella non esiste o non ha righe per questo server si usa
     * l'elenco di config/backup_paths.php.
     *
     * @return array<int, string>
     */
    public static function namesToBackUp(): array
    {
        try {
            $names = static::query()
                ->where('server_name', config('backup_paths.destination_prefix'))
                ->where('is_active', true)
                ->orderBy('database_name')
                ->pluck('database_name')
                ->all();
        } catch (\Throwable) {
            return [];
        }

        return $names;
    }

    /** Il ripristino si fa solo sul server su cui gira questa app: la VPS di un altro server non è raggiungibile da qui. */
    public function isOnThisServer(): bool
    {
        return $this->server_name === config('backup_paths.destination_prefix');
    }
}
