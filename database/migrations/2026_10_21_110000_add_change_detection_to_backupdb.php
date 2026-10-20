<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $columns = [
            'only_if_changed' => fn (Blueprint $table) => $table->boolean('only_if_changed')->default(true)->after('is_active')->comment('Salva su R2 solo se il dump è cambiato dall\'ultimo salvataggio'),
            'last_dump_hash' => fn (Blueprint $table) => $table->string('last_dump_hash', 64)->nullable()->after('only_if_changed')->comment('SHA-256 del dump (senza data) dell\'ultimo salvataggio su R2'),
            'last_checked_at' => fn (Blueprint $table) => $table->timestamp('last_checked_at')->nullable()->after('last_dump_hash')->comment('Ultimo dump confrontato'),
            'last_changed_at' => fn (Blueprint $table) => $table->timestamp('last_changed_at')->nullable()->after('last_checked_at')->comment('Ultimo salvataggio su R2 di un dump cambiato'),
        ];

        foreach ($columns as $name => $add) {
            if (! Schema::hasColumn('backupdb', $name)) {
                Schema::table('backupdb', $add);
            }
        }
    }

    public function down(): void
    {
        Schema::table('backupdb', function (Blueprint $table) {
            $table->dropColumn(['only_if_changed', 'last_dump_hash', 'last_checked_at', 'last_changed_at']);
        });
    }
};
