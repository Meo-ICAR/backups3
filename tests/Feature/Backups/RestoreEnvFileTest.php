<?php

namespace Tests\Feature\Backups;

use App\Jobs\RestoreEnvFile;
use App\Models\BackupEnv;
use App\Models\BackupEnvFile;
use App\Models\BackupEnvRestore;
use App\Services\R2Cli;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class RestoreEnvFileTest extends BackupDbTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/env-restore-'.uniqid();
        mkdir($this->root);
        config([
            'backup_paths.env.root' => $this->root,
            'backup_paths.restore_directory' => sys_get_temp_dir().'/backups3-env-restore-'.uniqid(),
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        File::deleteDirectory((string) config('backup_paths.restore_directory'));

        parent::tearDown();
    }

    public function test_it_writes_env_restore_and_leaves_the_file_in_use_untouched(): void
    {
        $file = $this->app('unicoagent');
        $this->fakeDownload("DB_PASSWORD=segreto\n");

        $restore = $this->request($file, false);
        RestoreEnvFile::dispatchSync($restore);

        $restore->refresh();
        $this->assertSame(BackupEnvRestore::DONE, $restore->status);
        $this->assertNull($restore->error);
        $this->assertStringNotContainsString('segreto', (string) $restore->error);
        $this->assertSame("APP_NAME=locale\n", file_get_contents($this->root.'/unicoagent/.env'));
        $this->assertSame("DB_PASSWORD=segreto\n", file_get_contents($this->root.'/unicoagent/.env.restore'));
        $this->assertDirectoryDoesNotExist((string) config('backup_paths.restore_directory')."/env-{$restore->id}");
    }

    public function test_replacing_the_file_in_use_overwrites_only_that_env(): void
    {
        $file = $this->app('unicoagent');
        $this->fakeDownload("APP_KEY=base64:altro\n");

        RestoreEnvFile::dispatchSync($this->request($file, true));

        $this->assertSame("APP_KEY=base64:altro\n", file_get_contents($this->root.'/unicoagent/.env'));
        $this->assertFileDoesNotExist($this->root.'/unicoagent/.env.restore');
    }

    public function test_an_env_from_another_server_does_not_replace_the_local_file(): void
    {
        $file = $this->app('unicoagent', 'altro-server');
        $this->fakeDownload("DB_PASSWORD=segreto\n");

        $restore = $this->request($file, true);
        RestoreEnvFile::dispatchSync($restore);

        $restore->refresh();
        $this->assertSame(BackupEnvRestore::FAILED, $restore->status);
        $this->assertStringNotContainsString('segreto', (string) $restore->error);
        $this->assertSame("APP_NAME=locale\n", file_get_contents($this->root.'/unicoagent/.env'));
        $this->assertFileDoesNotExist($this->root.'/unicoagent/.env.restore');
    }

    public function test_a_symlink_is_not_followed(): void
    {
        $file = $this->app('unicoagent');
        $outside = $this->root.'/fuori.txt';
        file_put_contents($outside, 'originale');
        unlink($this->root.'/unicoagent/.env');
        symlink($outside, $this->root.'/unicoagent/.env');
        $this->fakeDownload("DB_PASSWORD=segreto\n");

        RestoreEnvFile::dispatchSync($this->request($file, true));

        $this->assertSame('originale', file_get_contents($outside));
        $this->assertSame(BackupEnvRestore::FAILED, BackupEnvRestore::query()->value('status'));
    }

    public function test_a_name_outside_the_app_root_is_rejected(): void
    {
        $backup = BackupEnv::query()->create(['server_name' => 'srv-test', 'app_name' => '..']);
        $file = BackupEnvFile::query()->create([
            'backupenv_id' => $backup->id,
            'r2_key' => 'srv-test/env/../.env',
            'backed_up_at' => '2026-10-22 02:00:00',
            'size_bytes' => 10,
        ]);
        $this->fakeDownload("DB_PASSWORD=segreto\n");

        RestoreEnvFile::dispatchSync($this->request($file, false));

        $this->assertSame(BackupEnvRestore::FAILED, BackupEnvRestore::query()->value('status'));
        $this->assertFileDoesNotExist(dirname($this->root).'/.env.restore');
    }

    public function test_a_failed_download_is_recorded_without_the_file_contents(): void
    {
        $file = $this->app('unicoagent');
        $this->mock(R2Cli::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('download')->andReturn(Process::result(errorOutput: 'NoSuchKey', exitCode: 1));
        });

        $restore = $this->request($file, false);
        RestoreEnvFile::dispatchSync($restore);

        $restore->refresh();
        $this->assertSame(BackupEnvRestore::FAILED, $restore->status);
        $this->assertStringContainsString('NoSuchKey', $restore->error);
        $this->assertFileDoesNotExist($this->root.'/unicoagent/.env.restore');
    }

    private function app(string $name, string $server = 'srv-test'): BackupEnvFile
    {
        mkdir($this->root.'/'.$name);
        file_put_contents($this->root.'/'.$name.'/.env', "APP_NAME=locale\n");

        $backup = BackupEnv::query()->create(['server_name' => $server, 'app_name' => $name]);

        return BackupEnvFile::query()->create([
            'backupenv_id' => $backup->id,
            'r2_key' => "{$server}/env/{$name}/.env",
            'backed_up_at' => '2026-10-22 02:00:00',
            'size_bytes' => 20,
        ]);
    }

    private function request(BackupEnvFile $file, bool $overwrite): BackupEnvRestore
    {
        return BackupEnvRestore::query()->create([
            'backupenv_file_id' => $file->id,
            'target_file' => $overwrite ? BackupEnvRestore::ORIGINAL : BackupEnvRestore::COPY,
            'overwrites_original' => $overwrite,
            'status' => BackupEnvRestore::PENDING,
        ]);
    }

    private function fakeDownload(string $contents): void
    {
        $this->mock(R2Cli::class, function ($mock) use ($contents): void {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('download')->andReturnUsing(function (string $key, string $destination) use ($contents) {
                file_put_contents($destination, $contents);

                return Process::result();
            });
        });
    }
}
