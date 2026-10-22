<?php

namespace Tests\Feature\Console\Commands;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class BackupEnvFilesCommandTest extends TestCase
{
    private string $root;

    private string $staging;

    private string $state;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/backup-env-apps-'.uniqid();
        $this->staging = sys_get_temp_dir().'/backup-env-staging-'.uniqid();
        $this->state = sys_get_temp_dir().'/backup-env-state-'.uniqid().'.json';
        mkdir($this->root);

        config([
            'backup_paths.env.root' => $this->root,
            'backup_paths.env.staging' => $this->staging,
            'backup_paths.env.state' => $this->state,
            'backup_paths.destination_prefix' => 'vps-test',
            'backup_paths.sync_targets' => ['r2'],
            'filesystems.disks.r2.bucket' => 'test-bucket',
            'filesystems.disks.r2.endpoint' => 'https://example.r2.cloudflarestorage.com',
            'filesystems.disks.r2.key' => 'fake-key',
            'filesystems.disks.r2.secret' => 'fake-secret',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        File::deleteDirectory($this->staging);
        File::delete($this->state);

        parent::tearDown();
    }

    public function test_it_copies_each_app_env_and_syncs_the_folder_without_printing_secrets(): void
    {
        $this->appEnv('unicoagent', "DB_PASSWORD=super-secret-value\n");
        $this->appEnv('unicobpm', "APP_KEY=base64:another-secret\n");
        file_put_contents($this->root.'/unicoagent/.env.example', "DB_PASSWORD=example\n");
        mkdir($this->root.'/solo-example');
        file_put_contents($this->root.'/solo-example/.env.example', "DB_PASSWORD=example\n");
        mkdir($this->root.'/bad name');
        file_put_contents($this->root.'/bad name/.env', "DB_PASSWORD=hidden\n");

        Process::fake();

        $this->artisan('backup:env')
            ->expectsOutputToContain('Copiato [unicoagent]')
            ->expectsOutputToContain('Copiato [unicobpm]')
            ->doesntExpectOutputToContain('super-secret-value')
            ->doesntExpectOutputToContain('another-secret')
            ->doesntExpectOutputToContain('hidden')
            ->assertSuccessful();

        $this->assertSame("DB_PASSWORD=super-secret-value\n", file_get_contents($this->staging.'/unicoagent/.env'));
        $this->assertSame("APP_KEY=base64:another-secret\n", file_get_contents($this->staging.'/unicobpm/.env'));
        $this->assertFileDoesNotExist($this->staging.'/unicoagent/.env.example');
        $this->assertFileDoesNotExist($this->staging.'/solo-example/.env');
        $this->assertFileDoesNotExist($this->staging.'/bad name/.env');
        Process::assertRan(fn ($process): bool => ($process->command[0] ?? '') === 'aws'
            && in_array('s3://test-bucket/vps-test/env', $process->command, true));
    }

    public function test_it_fails_when_the_app_root_is_missing(): void
    {
        config(['backup_paths.env.root' => $this->root.'/manca']);
        Process::fake();

        $this->artisan('backup:env')->assertExitCode(1);

        Process::assertNothingRan();
    }

    public function test_it_fails_when_the_r2_sync_fails_and_keeps_the_local_copies(): void
    {
        $this->appEnv('unicooam', "DB_PASSWORD=segreto\n");
        Process::fake(['aws*' => Process::result(errorOutput: 'boom', exitCode: 1)]);

        $this->artisan('backup:env')
            ->doesntExpectOutputToContain('segreto')
            ->assertExitCode(1);

        $this->assertSame("DB_PASSWORD=segreto\n", file_get_contents($this->staging.'/unicooam/.env'));
        $this->assertFileDoesNotExist($this->state);
    }

    public function test_it_does_not_sync_when_every_env_is_unchanged(): void
    {
        $this->appEnv('unicoagent', "DB_PASSWORD=super-secret-value\n");
        Process::fake();

        $this->artisan('backup:env')->assertSuccessful();
        $this->artisan('backup:env')
            ->expectsOutputToContain('Invariato [unicoagent]')
            ->expectsOutputToContain('Nessun .env variato: non si sincronizza.')
            ->doesntExpectOutputToContain('super-secret-value')
            ->assertSuccessful();

        Process::assertRanTimes(fn ($process): bool => ($process->command[0] ?? '') === 'aws', times: 1);
    }

    public function test_it_copies_only_the_requested_apps(): void
    {
        $this->appEnv('unicoagent', "DB_PASSWORD=super-secret-value\n");
        $this->appEnv('unicobpm', "APP_KEY=base64:another-secret\n");
        Process::fake();

        $this->artisan('backup:env', ['--app' => ['unicoagent']])
            ->expectsOutputToContain('Copiato [unicoagent]')
            ->doesntExpectOutputToContain('unicobpm')
            ->doesntExpectOutputToContain('super-secret-value')
            ->assertSuccessful();

        $this->assertSame("DB_PASSWORD=super-secret-value\n", file_get_contents($this->staging.'/unicoagent/.env'));
        $this->assertFileDoesNotExist($this->staging.'/unicobpm/.env');
    }

    public function test_it_copies_an_unchanged_env_when_forced(): void
    {
        $this->appEnv('unicoagent', "DB_PASSWORD=super-secret-value\n");
        Process::fake();

        $this->artisan('backup:env', ['--app' => ['unicoagent']])->assertSuccessful();

        $this->artisan('backup:env', ['--app' => ['unicoagent'], '--all' => true])
            ->expectsOutputToContain('Copiato [unicoagent]')
            ->doesntExpectOutputToContain('Invariato [unicoagent]')
            ->doesntExpectOutputToContain('super-secret-value')
            ->assertSuccessful();

        Process::assertRanTimes(fn ($process): bool => ($process->command[0] ?? '') === 'aws', times: 2);
    }

    public function test_it_fails_when_a_requested_app_has_no_env_file(): void
    {
        $this->appEnv('unicoagent', "A=1\n");
        Process::fake();

        $this->artisan('backup:env', ['--app' => ['manca', 'unicoagent']])
            ->expectsOutputToContain('File .env assente per [manca]')
            ->expectsOutputToContain('Copiato [unicoagent]')
            ->assertExitCode(1);

        $this->assertSame("A=1\n", file_get_contents($this->staging.'/unicoagent/.env'));
        $this->assertFileDoesNotExist($this->state);
    }

    public function test_it_syncs_only_the_env_that_changed(): void
    {
        $this->appEnv('unicoagent', "A=1\n");
        $this->appEnv('unicobpm', "B=1\n");
        Process::fake();

        $this->artisan('backup:env')->assertSuccessful();

        file_put_contents($this->root.'/unicobpm/.env', "B=2\n");

        $this->artisan('backup:env')
            ->expectsOutputToContain('Invariato [unicoagent]')
            ->expectsOutputToContain('Copiato [unicobpm]')
            ->assertSuccessful();

        $this->assertFileDoesNotExist($this->staging.'/unicoagent/.env');
        $this->assertSame("B=2\n", file_get_contents($this->staging.'/unicobpm/.env'));
        Process::assertRanTimes(fn ($process): bool => ($process->command[0] ?? '') === 'aws', times: 2);
    }

    private function appEnv(string $app, string $contents): void
    {
        mkdir($this->root.'/'.$app);
        file_put_contents($this->root.'/'.$app.'/.env', $contents);
    }
}
