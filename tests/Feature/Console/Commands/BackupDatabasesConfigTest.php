<?php

namespace Tests\Feature\Console\Commands;

use Tests\TestCase;

class BackupDatabasesConfigTest extends TestCase
{
    public function test_the_new_databases_of_the_unico_apps_are_in_the_backup_list(): void
    {
        $only = config('backup_paths.databases.only');

        foreach (['unico_core_test', 'unicooam_core', 'unicoloan_core', 'proforma_core', 'unicobpm_dev', 'unicoagent'] as $database) {
            $this->assertContains($database, $only);
        }

        // Gli originali restano protetti finché non vengono dismessi.
        foreach (['proforma', 'unicooam', 'unicobpm', 'unicoloan'] as $original) {
            $this->assertContains($original, $only);
        }
    }
}
