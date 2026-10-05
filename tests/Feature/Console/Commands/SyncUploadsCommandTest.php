<?php

namespace Tests\Feature\Console\Commands;

use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class SyncUploadsCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'filesystems.disks.r2.bucket' => 'test-bucket',
            'filesystems.disks.r2.endpoint' => 'https://example.r2.cloudflarestorage.com',
            'filesystems.disks.r2.key' => 'fake-key',
            'filesystems.disks.r2.secret' => 'fake-secret',
        ]);
    }

    public function test_it_syncs_each_configured_directory_that_exists(): void
    {
        $existingDir = sys_get_temp_dir().'/backup_uploads_test_'.uniqid();
        mkdir($existingDir);

        config([
            'backup_paths.upload_directories' => [
                'exists' => $existingDir,
                'missing' => '/path/does/not/exist',
            ],
        ]);

        Process::fake();

        $this->artisan('backup:uploads')->assertExitCode(0);

        Process::assertRan(fn ($process) => str_contains($process->command[0] ?? '', 'aws')
            && in_array('s3://test-bucket/uploads/exists', $process->command)
        );

        Process::assertRanTimes(fn ($process) => ($process->command[0] ?? '') === 'aws', 1);

        rmdir($existingDir);
    }

    public function test_it_fails_when_r2_config_is_missing(): void
    {
        config(['filesystems.disks.r2.bucket' => null]);
        config(['backup_paths.upload_directories' => ['exists' => sys_get_temp_dir()]]);

        Process::fake();

        $this->artisan('backup:uploads')->assertExitCode(1);

        Process::assertNothingRan();
    }

    public function test_it_fails_when_a_sync_command_fails(): void
    {
        $existingDir = sys_get_temp_dir().'/backup_uploads_test_'.uniqid();
        mkdir($existingDir);

        config(['backup_paths.upload_directories' => ['exists' => $existingDir]]);

        Process::fake(['aws*' => Process::result(errorOutput: 'boom', exitCode: 1)]);

        $this->artisan('backup:uploads')->assertExitCode(1);

        rmdir($existingDir);
    }
}
