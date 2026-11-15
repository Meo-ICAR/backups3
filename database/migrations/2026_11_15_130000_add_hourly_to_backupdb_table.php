<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('backupdb', 'hourly')) {
            Schema::table('backupdb', function (Blueprint $table): void {
                $table->boolean('hourly')->default(false)->after('is_active')->comment('Dump orario locale (backup:hourly), senza sincronizzazione remota');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('backupdb', 'hourly')) {
            Schema::table('backupdb', function (Blueprint $table): void {
                $table->dropColumn('hourly');
            });
        }
    }
};
