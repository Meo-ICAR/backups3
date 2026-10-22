<?php

namespace Tests\Feature\Backups;

use App\Jobs\RunDatabaseBackup;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RunDatabaseBackupTest extends TestCase
{
    public function test_it_dumps_the_given_databases_even_if_unchanged_and_refreshes_the_r2_list(): void
    {
        Artisan::command('backup:databases {--database=*} {--all}', function (): int {
            Cache::put('backup-call', [
                'database' => $this->option('database'),
                'all' => $this->option('all'),
            ]);

            return 0;
        });
        Artisan::command('backup:sync-remote', function (): int {
            Cache::put('sync-called', true);

            return 0;
        });

        (new RunDatabaseBackup(['proforma', 'alfa']))->handle();

        $this->assertSame(['proforma', 'alfa'], Cache::get('backup-call')['database']);
        $this->assertTrue(Cache::get('backup-call')['all']);
        $this->assertTrue(Cache::get('sync-called'));
    }
}
