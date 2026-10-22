<?php

namespace Tests\Feature\Backups;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

abstract class BackupDbTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('backupdb_restores');
        Schema::dropIfExists('backupdb_files');
        Schema::dropIfExists('backupdb');
        Schema::enableForeignKeyConstraints();

        config([
            'backup_paths.destination_prefix' => 'srv-test',
            'backup_paths.databases.only' => ['alfa'],
            'backup_paths.restore_directory' => sys_get_temp_dir().'/backups3-restore-test',
            'filesystems.disks.r2.key' => 'k',
            'filesystems.disks.r2.secret' => 's',
            'filesystems.disks.r2.bucket' => 'bucket',
            'filesystems.disks.r2.endpoint' => 'https://example.r2.cloudflarestorage.com',
        ]);

        // Solo le tabelle del pannello: le altre migration dell'app sono scritte per MySQL.
        foreach (['2026_10_21_100000_create_backupdb_tables', '2026_10_21_110000_add_change_detection_to_backupdb'] as $migration) {
            (require database_path("migrations/{$migration}.php"))->up();
        }
    }
}
