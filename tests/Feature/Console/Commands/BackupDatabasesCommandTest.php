<?php

namespace Tests\Feature\Console\Commands;

use App\Console\Commands\BackupDatabases;
use Symfony\Component\Console\Input\ArrayInput;
use Tests\TestCase;

class BackupDatabasesCommandTest extends TestCase
{
    public function test_the_database_option_limits_the_run_to_those_names(): void
    {
        config(['backup_paths.databases.only' => ['alfa']]);

        $this->assertSame(['proforma', 'alfa'], $this->command(['--database' => ['proforma', 'alfa']])->databaseNames());
        $this->assertSame(['alfa'], $this->command([])->databaseNames());
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function command(array $parameters): BackupDatabases
    {
        $command = $this->app->make(BackupDatabases::class);
        $command->setInput(new ArrayInput($parameters, $command->getDefinition()));

        return $command;
    }
}
