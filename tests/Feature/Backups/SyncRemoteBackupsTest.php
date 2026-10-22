<?php

namespace Tests\Feature\Backups;

use App\Models\BackupDb;
use App\Models\BackupDbFile;
use App\Models\BackupEnv;
use App\Models\BackupEnvFile;
use Illuminate\Support\Facades\Process;

class SyncRemoteBackupsTest extends BackupDbTestCase
{
    private function listing(array $objects): void
    {
        Process::fake(['*list-objects-v2*' => Process::result(json_encode($objects))]);
    }

    public function test_it_registers_backups_with_date_and_size(): void
    {
        $this->listing([
            ['srv-test/databases/alfa/alfa_2026-10-20_02-00-00.sql.gz', 1048576, '2026-10-20T02:03:00+00:00'],
            ['srv-test/databases/alfa/alfa_2026-10-19_02-00-00.sql.gz', 2048, '2026-10-19T02:03:00+00:00'],
            ['srv-test/uploads/foto.jpg', 10, '2026-10-19T02:03:00+00:00'],
        ]);

        $this->artisan('backup:sync-remote')->assertSuccessful();

        $this->assertSame(2, BackupDbFile::query()->count());
        $file = BackupDbFile::query()->where('r2_key', 'srv-test/databases/alfa/alfa_2026-10-20_02-00-00.sql.gz')->firstOrFail();
        $this->assertSame(1048576, $file->size_bytes);
        $this->assertSame('2026-10-20 02:00:00', $file->backed_up_at->format('Y-m-d H:i:s'));
        $this->assertSame('alfa', $file->backupDb->database_name);
        $this->assertSame('srv-test', $file->backupDb->server_name);
    }

    public function test_a_database_found_only_on_r2_is_not_added_to_the_nightly_backup(): void
    {
        $this->listing([['srv-test/databases/vecchio/vecchio_2026-10-20_02-00-00.sql.gz', 10, '2026-10-20T02:03:00+00:00']]);

        $this->artisan('backup:sync-remote')->assertSuccessful();

        $this->assertFalse(BackupDb::query()->where('database_name', 'vecchio')->firstOrFail()->is_active);
        $this->assertSame(['alfa'], BackupDb::namesToBackUp());
    }

    public function test_the_server_comes_from_the_r2_prefix_and_old_layout_uses_this_server(): void
    {
        $this->listing([
            ['altro-server/databases/beta/beta_2026-10-20_02-00-00.sql.gz', 10, '2026-10-20T02:03:00+00:00'],
            ['databases/gamma/gamma_2026-10-20_02-00-00.sql.gz', 10, '2026-10-20T02:03:00+00:00'],
        ]);

        $this->artisan('backup:sync-remote')->assertSuccessful();

        $this->assertSame('altro-server', BackupDb::query()->where('database_name', 'beta')->value('server_name'));
        $this->assertSame('srv-test', BackupDb::query()->where('database_name', 'gamma')->value('server_name'));
    }

    public function test_backups_removed_from_r2_disappear_and_a_second_run_does_not_duplicate(): void
    {
        $this->listing([
            ['srv-test/databases/alfa/alfa_2026-10-20_02-00-00.sql.gz', 10, '2026-10-20T02:03:00+00:00'],
            ['srv-test/databases/alfa/alfa_2026-10-19_02-00-00.sql.gz', 10, '2026-10-19T02:03:00+00:00'],
        ]);
        $this->artisan('backup:sync-remote')->assertSuccessful();
        $this->artisan('backup:sync-remote')->assertSuccessful();
        $this->assertSame(2, BackupDbFile::query()->count());

        $this->listing([['srv-test/databases/alfa/alfa_2026-10-20_02-00-00.sql.gz', 10, '2026-10-20T02:03:00+00:00']]);
        $this->artisan('backup:sync-remote')->assertSuccessful();

        $this->assertSame(1, BackupDbFile::query()->count());
    }

    public function test_it_registers_env_files_and_drops_one_removed_from_r2(): void
    {
        $this->listing([
            ['srv-test/env/unicoagent/.env', 4136, '2026-10-22T09:36:43+00:00'],
            ['altro-server/env/unicobpm/.env', 100, '2026-10-22T09:36:43+00:00'],
            ['srv-test/env/solo-example/.env.example', 10, '2026-10-22T09:36:43+00:00'],
        ]);

        $this->artisan('backup:sync-remote')->assertSuccessful();
        $this->artisan('backup:sync-remote')->assertSuccessful();

        $this->assertSame(2, BackupEnvFile::query()->count());
        $file = BackupEnvFile::query()->where('r2_key', 'srv-test/env/unicoagent/.env')->firstOrFail();
        $this->assertSame(4136, $file->size_bytes);
        $this->assertSame('unicoagent', $file->backupEnv->app_name);
        $this->assertSame('srv-test', $file->backupEnv->server_name);
        $this->assertSame('altro-server', BackupEnv::query()->where('app_name', 'unicobpm')->value('server_name'));
        $this->assertNull(BackupEnv::query()->where('app_name', 'solo-example')->first());

        $this->listing([['srv-test/env/unicoagent/.env', 4136, '2026-10-22T09:36:43+00:00']]);
        $this->artisan('backup:sync-remote')->assertSuccessful();

        $this->assertSame(1, BackupEnvFile::query()->count());
        $this->assertSame('unicoagent', BackupEnvFile::query()->firstOrFail()->backupEnv->app_name);
    }

    public function test_it_fails_without_r2_configuration_and_when_aws_fails(): void
    {
        config(['filesystems.disks.r2.bucket' => null]);
        $this->artisan('backup:sync-remote')->assertFailed();

        config(['filesystems.disks.r2.bucket' => 'bucket']);
        Process::fake(['*' => Process::result(errorOutput: 'AccessDenied', exitCode: 1)]);
        $this->artisan('backup:sync-remote')->assertFailed();
        $this->assertSame(0, BackupDbFile::query()->count());
    }
}
