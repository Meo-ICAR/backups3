<?php

namespace Tests\Feature\Console\Commands;

use Carbon\Carbon;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class CleanBackupsCommandTest extends TestCase
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

        Carbon::setTestNow('2026-09-11 02:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_fails_fast_when_r2_config_is_missing(): void
    {
        config(['filesystems.disks.r2.bucket' => null]);

        Process::fake();

        $this->artisan('backup:clean')->assertExitCode(1);

        Process::assertNothingRan();
    }

    public function test_it_applies_generational_retention_per_database(): void
    {
        $recent = 'databases/testdb/testdb_2026-09-09_02-00-00.sql.gz'; // 2 giorni fa -> kept (daily)
        $monthSurvivor = 'databases/testdb/testdb_2025-01-20_02-00-00.sql.gz'; // stesso mese del duplicato, ma piu recente -> kept (monthly)
        $monthDuplicate = 'databases/testdb/testdb_2025-01-05_02-00-00.sql.gz'; // stesso mese del survivor -> deleted
        $veryOld = 'databases/testdb/testdb_2020-01-01_02-00-00.sql.gz'; // oltre 36 mesi -> always deleted

        Process::fake([
            '*list-objects-v2*' => Process::result(
                output: json_encode([$recent, $monthSurvivor, $monthDuplicate, $veryOld])
            ),
            '*s3*rm*' => Process::result(),
        ]);

        $this->artisan('backup:clean')->assertExitCode(0);

        Process::assertRan(fn ($process) => str_contains(implode(' ', $process->command), "s3://test-bucket/{$monthDuplicate}"));
        Process::assertRan(fn ($process) => str_contains(implode(' ', $process->command), "s3://test-bucket/{$veryOld}"));
        Process::assertNotRan(fn ($process) => str_contains(implode(' ', $process->command), "s3://test-bucket/{$recent}"));
        Process::assertNotRan(fn ($process) => str_contains(implode(' ', $process->command), "s3://test-bucket/{$monthSurvivor}"));
    }

    public function test_it_fails_when_a_delete_fails(): void
    {
        $veryOld = 'databases/testdb/testdb_2020-01-01_02-00-00.sql.gz';

        Process::fake([
            '*list-objects-v2*' => Process::result(output: json_encode([$veryOld])),
            '*s3*rm*' => Process::result(errorOutput: 'boom', exitCode: 1),
        ]);

        $this->artisan('backup:clean')->assertExitCode(1);
    }

    public function test_it_succeeds_when_bucket_is_empty(): void
    {
        Process::fake([
            '*list-objects-v2*' => Process::result(output: json_encode(null)),
        ]);

        $this->artisan('backup:clean')->assertExitCode(0);
    }
}
