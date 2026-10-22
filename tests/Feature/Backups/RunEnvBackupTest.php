<?php

namespace Tests\Feature\Backups;

use App\Jobs\RunEnvBackup;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RunEnvBackupTest extends TestCase
{
    public function test_it_copies_the_given_env_files_even_if_unchanged_and_refreshes_the_r2_list(): void
    {
        Artisan::command('backup:env {--app=*} {--all}', function (): int {
            Cache::put('env-backup-call', [
                'app' => $this->option('app'),
                'all' => $this->option('all'),
            ]);

            return 0;
        });
        Artisan::command('backup:sync-remote', function (): int {
            Cache::put('sync-called', true);

            return 0;
        });

        (new RunEnvBackup(['unicobpm', 'unicoagent']))->handle();

        $this->assertSame(['unicobpm', 'unicoagent'], Cache::get('env-backup-call')['app']);
        $this->assertTrue(Cache::get('env-backup-call')['all']);
        $this->assertTrue(Cache::get('sync-called'));
    }
}
