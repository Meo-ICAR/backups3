<?php

namespace Tests\Feature\Console\Commands;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class BackupHourlyCommandTest extends TestCase
{
    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('app/backups/hourly/zz_prova');
        File::deleteDirectory($this->folder);
        File::ensureDirectoryExists($this->folder);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->folder);

        parent::tearDown();
    }

    public function test_dumps_older_than_the_retention_are_removed_and_recent_ones_kept(): void
    {
        config(['backup_paths.hourly.retention_hours' => 72]);

        $old = "{$this->folder}/zz_prova_old.sql.gz";
        $recent = "{$this->folder}/zz_prova_recent.sql.gz";
        $other = "{$this->folder}/note.txt";
        File::put($old, 'x');
        File::put($recent, 'x');
        File::put($other, 'x');
        touch($old, now()->subHours(73)->getTimestamp());
        touch($recent, now()->subHours(71)->getTimestamp());
        touch($other, now()->subDays(30)->getTimestamp());

        $this->artisan('backup:hourly')->assertSuccessful();

        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($recent);
        $this->assertFileExists($other);
    }

    public function test_it_does_nothing_when_disabled(): void
    {
        config(['backup_paths.hourly.enabled' => false]);

        $this->artisan('backup:hourly')->expectsOutputToContain('disattivati')->assertSuccessful();
    }
}
