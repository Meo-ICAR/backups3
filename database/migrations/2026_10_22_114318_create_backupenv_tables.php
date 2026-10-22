<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('backupenv')) {
            Schema::create('backupenv', function (Blueprint $table) {
                $table->id();
                $table->string('server_name')->comment('Server da cui proviene il file (prefisso del backup su R2)');
                $table->string('app_name')->comment('Cartella dell\'app sotto la radice dei .env');
                $table->timestamp('last_synced_at')->nullable()->comment('Ultimo aggiornamento della lista da R2');
                $table->timestamps();
                $table->unique(['server_name', 'app_name']);
                $table->comment('File .env presenti su Cloudflare R2, per app e server.');
            });
        }

        if (! Schema::hasTable('backupenv_files')) {
            Schema::create('backupenv_files', function (Blueprint $table) {
                $table->id();
                $table->foreignId('backupenv_id')->constrained('backupenv')->cascadeOnDelete();
                $table->string('r2_key', 500)->unique()->comment('Percorso dell\'oggetto nel bucket R2');
                $table->timestamp('backed_up_at')->comment('Data dell\'ultima copia su R2');
                $table->unsignedBigInteger('size_bytes')->default(0);
                $table->timestamp('r2_last_modified_at')->nullable();
                $table->timestamps();
                $table->index(['backupenv_id', 'backed_up_at']);
                $table->comment('Copie dei file .env su Cloudflare R2, aggiornate da backup:sync-remote.');
            });
        }

        if (! Schema::hasTable('backupenv_restores')) {
            Schema::create('backupenv_restores', function (Blueprint $table) {
                $table->id();
                $table->foreignId('backupenv_file_id')->constrained('backupenv_files')->cascadeOnDelete();
                $table->string('target_file', 20)->comment('.env.restore oppure .env');
                $table->boolean('overwrites_original')->default(false);
                $table->string('status', 20)->default('pending')->comment('pending, running, done, failed');
                $table->unsignedBigInteger('requested_by')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->text('error')->nullable();
                $table->timestamps();
                $table->index('status');
                $table->comment('Richieste di ripristino di un file .env da R2.');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('backupenv_restores');
        Schema::dropIfExists('backupenv_files');
        Schema::dropIfExists('backupenv');
    }
};
