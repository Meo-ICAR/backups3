<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('anonymized_backups')) {
            return;
        }

        Schema::create('anonymized_backups', function (Blueprint $table) {
            $table->id();
            $table->string('server_name')->comment('Server da cui si è partiti');
            $table->string('database_name')->comment('Database anonimizzato');
            $table->string('status', 20)->default('pending')->comment('pending, running, done, failed');
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->string('local_path', 500)->nullable()->comment('File anonimizzato su questo server');
            $table->string('remote_key', 500)->nullable()->comment('Oggetto nel bucket dei file anonimizzati, se configurato');
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->json('report')->nullable()->comment('Cosa è stato sostituito e esito del controllo finale: solo nomi di tabelle e colonne, mai valori');
            $table->text('error')->nullable()->comment('Errore ripulito dai valori tra apici');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index('status');
            $table->index(['server_name', 'database_name']);
            $table->comment('Backup anonimizzati richiesti su richiesta (backup:anonymized).');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anonymized_backups');
    }
};
