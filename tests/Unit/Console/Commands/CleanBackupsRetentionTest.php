<?php

namespace Tests\Unit\Console\Commands;

use App\Console\Commands\CleanBackups;
use Carbon\Carbon;
use Tests\TestCase;

class CleanBackupsRetentionTest extends TestCase
{
    private CleanBackups $command;

    protected function setUp(): void
    {
        parent::setUp();

        $this->command = new CleanBackups;
        Carbon::setTestNow('2026-09-11 02:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_backups_within_14_days_are_always_kept(): void
    {
        $this->assertFalse($this->command->shouldDelete(now()->subDays(14)));
        $this->assertFalse($this->command->shouldDelete(now()->subDay()));
    }

    public function test_between_14_days_and_12_months_only_sundays_and_first_of_month_are_kept(): void
    {
        $sunday = Carbon::parse('2026-08-16'); // a Sunday, > 14 days before test-now
        $firstOfMonth = Carbon::parse('2026-08-01');
        $randomWeekday = Carbon::parse('2026-08-18'); // a Tuesday

        $this->assertFalse($this->command->shouldDelete($sunday));
        $this->assertFalse($this->command->shouldDelete($firstOfMonth));
        $this->assertTrue($this->command->shouldDelete($randomWeekday));
    }

    public function test_between_12_and_36_months_only_first_of_month_is_kept(): void
    {
        $firstOfMonth = now()->subMonths(20)->startOfMonth();
        $midMonth = now()->subMonths(20)->startOfMonth()->addDays(10);

        $this->assertFalse($this->command->shouldDelete($firstOfMonth));
        $this->assertTrue($this->command->shouldDelete($midMonth));
    }

    public function test_backups_older_than_36_months_are_always_deleted(): void
    {
        $veryOld = now()->subMonths(37)->startOfMonth();

        $this->assertTrue($this->command->shouldDelete($veryOld));
    }
}
