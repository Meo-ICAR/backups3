<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('backupdb')) {
            Schema::create('backupdb', function (Blueprint $table) {
                $table->id();
                $table->string('server_name')->comment('Server su cui gira il database (prefisso del backup su R2)');
                $table->string('database_name')->comment('Database di cui si fa il backup');
                $table->boolean('is_active')->default(true);
                $table->timestamp('last_synced_at')->nullable()->comment('Ultimo aggiornamento della lista da R2');
                $table->timestamps();
                $table->unique(['server_name', 'database_name']);
                $table->comment('Database di cui si fa il backup, per server.');
            });
        }

        // Parte dall'elenco già configurato: da qui in poi i database da salvare si gestiscono dal pannello.
        $server = config('backup_paths.destination_prefix');
        foreach (config('backup_paths.databases.only', []) as $database) {
            DB::table('backupdb')->insertOrIgnore([
                'server_name' => $server,
                'database_name' => $database,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (! Schema::hasTable('backupdb_files')) {
            Schema::create('backupdb_files', function (Blueprint $table) {
                $table->id();
                $table->foreignId('backupdb_id')->constrained('backupdb')->cascadeOnDelete();
                $table->string('r2_key', 500)->unique()->comment('Percorso dell\'oggetto nel bucket R2');
                $table->timestamp('backed_up_at')->comment('Data del backup, dal nome del file');
                $table->unsignedBigInteger('size_bytes')->default(0)->comment('Dimensione del file su R2');
                $table->timestamp('r2_last_modified_at')->nullable();
                $table->timestamps();
                $table->index(['backupdb_id', 'backed_up_at']);
                $table->comment('Backup presenti su Cloudflare R2, aggiornati da backup:sync-remote.');
            });
        }

        if (! Schema::hasTable('backupdb_restores')) {
            Schema::create('backupdb_restores', function (Blueprint $table) {
                $table->id();
                $table->foreignId('backupdb_file_id')->constrained('backupdb_files')->cascadeOnDelete();
                $table->string('target_database')->comment('Database in cui si ripristina');
                $table->boolean('overwrites_original')->default(false);
                $table->string('status', 20)->default('pending')->comment('pending, running, done, failed');
                $table->unsignedBigInteger('requested_by')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->text('error')->nullable();
                $table->timestamps();
                $table->index('status');
                $table->comment('Richieste di ripristino di un backup da R2.');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('backupdb_restores');
        Schema::dropIfExists('backupdb_files');
        Schema::dropIfExists('backupdb');
    }
};
