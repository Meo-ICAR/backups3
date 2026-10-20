<?php

namespace Tests\Feature\Backups;

use App\Jobs\RestoreBackup;
use App\Models\BackupDb;
use App\Models\BackupDbFile;
use App\Models\BackupDbRestore;
use Illuminate\Support\Facades\Process;

class RestoreBackupTest extends BackupDbTestCase
{
    private function restore(string $target = 'alfa_restore', bool $overwrites = false): BackupDbRestore
    {
        $backupDb = BackupDb::query()->where('database_name', 'alfa')->firstOrFail();
        $file = BackupDbFile::query()->create([
            'backupdb_id' => $backupDb->id,
            'r2_key' => 'srv-test/databases/alfa/alfa_2026-10-20_02-00-00.sql.gz',
            'backed_up_at' => '2026-10-20 02:00:00',
            'size_bytes' => 100,
        ]);

        return BackupDbRestore::query()->create(['backupdb_file_id' => $file->id, 'target_database' => $target, 'overwrites_original' => $overwrites]);
    }

    public function test_it_downloads_checks_creates_and_loads_the_dump(): void
    {
        Process::fake();
        $restore = $this->restore();

        RestoreBackup::dispatchSync($restore);

        $this->assertSame(BackupDbRestore::DONE, $restore->fresh()->status);
        $this->assertNotNull($restore->fresh()->finished_at);
        Process::assertRan(fn ($process) => str_contains(implode(' ', $process->command), 's3 cp s3://bucket/srv-test/databases/alfa/alfa_2026-10-20_02-00-00.sql.gz'));
        Process::assertRan(fn ($process) => in_array('gzip', $process->command, true) && in_array('-t', $process->command, true));
        Process::assertRan(fn ($process) => str_contains(implode(' ', $process->command), 'CREATE DATABASE IF NOT EXISTS `alfa_restore`'));
        Process::assertRan(fn ($process) => ($process->command[0] ?? null) === 'bash' && end($process->command) === 'alfa_restore');
    }

    public function test_a_failing_step_marks_the_restore_as_failed_with_the_reason(): void
    {
        Process::fake(['*cp*' => Process::result(errorOutput: 'NoSuchKey', exitCode: 1), '*' => Process::result()]);
        $restore = $this->restore();

        RestoreBackup::dispatchSync($restore);

        $restore->refresh();
        $this->assertSame(BackupDbRestore::FAILED, $restore->status);
        $this->assertStringContainsString('NoSuchKey', $restore->error);
        Process::assertNotRan(fn ($process) => ($process->command[0] ?? null) === 'bash');
    }

    public function test_an_invalid_target_name_is_rejected_before_touching_mysql(): void
    {
        Process::fake();
        $restore = $this->restore('x`; DROP DATABASE y; --');

        RestoreBackup::dispatchSync($restore);

        $this->assertSame(BackupDbRestore::FAILED, $restore->fresh()->status);
        Process::assertNothingRan();
    }

    public function test_only_databases_of_this_server_can_be_restored_here(): void
    {
        $here = BackupDb::query()->create(['server_name' => 'srv-test', 'database_name' => 'x']);
        $elsewhere = BackupDb::query()->create(['server_name' => 'altro', 'database_name' => 'x']);

        $this->assertTrue($here->isOnThisServer());
        $this->assertFalse($elsewhere->isOnThisServer());
    }
}
