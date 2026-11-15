<?php

namespace Tests\Feature\Backups;

use App\Models\BackupDb;

class HourlyBackupListTest extends BackupDbTestCase
{
    public function test_the_hourly_list_comes_from_the_table_for_this_server_only(): void
    {
        BackupDb::query()->create(['server_name' => 'srv-test', 'database_name' => 'beta', 'hourly' => true]);
        BackupDb::query()->where('database_name', 'alfa')->update(['hourly' => true]);
        BackupDb::query()->create(['server_name' => 'srv-test', 'database_name' => 'gamma', 'hourly' => false]);
        BackupDb::query()->create(['server_name' => 'altro', 'database_name' => 'delta', 'hourly' => true]);

        $this->assertSame(['alfa', 'beta'], BackupDb::namesForHourlyBackup());
    }

    public function test_hourly_is_off_by_default(): void
    {
        $this->assertSame([], BackupDb::namesForHourlyBackup());
    }
}
