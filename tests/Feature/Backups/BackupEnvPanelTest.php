<?php

namespace Tests\Feature\Backups;

use App\Filament\Resources\BackupEnvs\Pages\ListBackupEnvs;
use App\Filament\Resources\BackupEnvs\Pages\ViewBackupEnv;
use App\Filament\Resources\BackupEnvs\RelationManagers\FilesRelationManager;
use App\Jobs\RestoreEnvFile;
use App\Models\BackupEnv;
use App\Models\BackupEnvFile;
use App\Models\BackupEnvRestore;
use App\Models\User;
use App\Services\R2Cli;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

class BackupEnvPanelTest extends BackupDbTestCase
{
    private BackupEnvFile $file;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
        (require database_path('migrations/0001_01_01_000000_create_users_table.php'))->up();
        config(['backup_paths.admin_emails' => ['admin@example.com']]);

        $this->actingAs(User::query()->create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']));
        Filament::setCurrentPanel('admin');

        $app = BackupEnv::query()->create(['server_name' => 'srv-test', 'app_name' => 'unicoagent']);
        $this->file = BackupEnvFile::query()->create([
            'backupenv_id' => $app->id,
            'r2_key' => 'srv-test/env/unicoagent/.env',
            'backed_up_at' => '2026-10-22 02:00:00',
            'size_bytes' => 5 * 1024 * 1024,
        ]);
    }

    public function test_the_list_shows_the_app_and_its_env_backup(): void
    {
        $this->get('/admin/env')->assertOk();

        Livewire::test(ListBackupEnvs::class)
            ->assertCanSeeTableRecords(BackupEnv::query()->get())
            ->assertSee('unicoagent')
            ->assertSee('5.0 MB');
    }

    public function test_the_list_defaults_to_this_server_and_the_filter_can_show_another(): void
    {
        $other = BackupEnv::query()->create(['server_name' => 'altro-server', 'app_name' => 'unicobpm']);
        $mine = BackupEnv::query()->where('server_name', 'srv-test')->get();

        Livewire::test(ListBackupEnvs::class)
            ->assertCanSeeTableRecords($mine)
            ->assertCanNotSeeTableRecords([$other])
            ->filterTable('server_name', 'altro-server')
            ->assertCanSeeTableRecords([$other])
            ->assertCanNotSeeTableRecords($mine);
    }

    public function test_a_user_not_in_the_list_cannot_open_the_env_pages(): void
    {
        $this->actingAs(User::query()->create(['name' => 'Altro', 'email' => 'altro@example.com', 'password' => 'secret']));

        $this->get('/admin/env')->assertForbidden();
        $this->get('/admin/ripristini-env')->assertForbidden();
    }

    public function test_restoring_writes_a_copy_and_does_not_replace_the_env_in_use(): void
    {
        Bus::fake();

        Livewire::test(FilesRelationManager::class, ['ownerRecord' => $this->file->backupEnv, 'pageClass' => ViewBackupEnv::class])
            ->assertCanSeeTableRecords([$this->file])
            ->callAction(TestAction::make('ripristina')->table($this->file), ['mode' => 'nuovo'])
            ->assertHasNoFormErrors();

        $restore = BackupEnvRestore::query()->firstOrFail();
        $this->assertSame('.env.restore', $restore->target_file);
        $this->assertFalse($restore->overwrites_original);
        Bus::assertDispatchedAfterResponse(RestoreEnvFile::class);
    }

    public function test_replacing_the_env_in_use_requires_typing_the_app_name(): void
    {
        Bus::fake();

        $manager = fn () => Livewire::test(FilesRelationManager::class, ['ownerRecord' => $this->file->backupEnv, 'pageClass' => ViewBackupEnv::class]);

        $manager()->callAction(TestAction::make('ripristina')->table($this->file), ['mode' => 'originale', 'conferma' => 'sbagliato'])
            ->assertHasFormErrors(['conferma']);
        $this->assertSame(0, BackupEnvRestore::query()->count());

        $manager()->callAction(TestAction::make('ripristina')->table($this->file), ['mode' => 'originale', 'conferma' => 'unicoagent'])
            ->assertHasNoFormErrors();
        $restore = BackupEnvRestore::query()->firstOrFail();
        $this->assertTrue($restore->overwrites_original);
        $this->assertSame('.env', $restore->target_file);
    }

    public function test_an_env_from_another_server_cannot_replace_the_local_file(): void
    {
        Bus::fake();
        $other = BackupEnv::query()->create(['server_name' => 'altro-server', 'app_name' => 'unicobpm']);
        $file = BackupEnvFile::query()->create([
            'backupenv_id' => $other->id,
            'r2_key' => 'altro-server/env/unicobpm/.env',
            'backed_up_at' => '2026-10-22 02:00:00',
            'size_bytes' => 1,
        ]);

        Livewire::test(FilesRelationManager::class, ['ownerRecord' => $other, 'pageClass' => ViewBackupEnv::class])
            ->callAction(TestAction::make('ripristina')->table($file), ['mode' => 'originale', 'conferma' => 'unicobpm'])
            ->assertHasFormErrors(['mode']);

        $this->assertSame(0, BackupEnvRestore::query()->count());
    }

    public function test_bulk_restore_uses_the_current_copy_as_env_restore(): void
    {
        Bus::fake();

        Livewire::test(ListBackupEnvs::class)
            ->callTableBulkAction('ripristina', [$this->file->backupEnv], ['mode' => 'nuovo'])
            ->assertHasNoFormErrors();

        $restore = BackupEnvRestore::query()->firstOrFail();
        $this->assertSame($this->file->id, $restore->backupenv_file_id);
        $this->assertSame('.env.restore', $restore->target_file);
        $this->assertFalse($restore->overwrites_original);
        Bus::assertDispatchedAfterResponse(RestoreEnvFile::class);
    }

    public function test_bulk_restore_skips_an_app_without_a_copy_on_r2(): void
    {
        Bus::fake();
        $empty = BackupEnv::query()->create(['server_name' => 'srv-test', 'app_name' => 'vuoto']);

        Livewire::test(ListBackupEnvs::class)
            ->callTableBulkAction('ripristina', [$this->file->backupEnv, $empty], ['mode' => 'nuovo'])
            ->assertHasNoFormErrors();

        $this->assertSame(1, BackupEnvRestore::query()->count());
        $this->assertSame('.env.restore', BackupEnvRestore::query()->value('target_file'));
    }

    public function test_bulk_restore_over_the_files_in_use_requires_typing_sovrascrivi(): void
    {
        Bus::fake();
        $page = fn () => Livewire::test(ListBackupEnvs::class);

        $page()->callTableBulkAction('ripristina', [$this->file->backupEnv], ['mode' => 'originale', 'conferma' => 'sbagliato'])
            ->assertHasFormErrors(['conferma']);
        $this->assertSame(0, BackupEnvRestore::query()->count());

        $page()->callTableBulkAction('ripristina', [$this->file->backupEnv], ['mode' => 'originale', 'conferma' => 'sovrascrivi'])
            ->assertHasNoFormErrors();
        $this->assertTrue(BackupEnvRestore::query()->firstOrFail()->overwrites_original);
        $this->assertSame('.env', BackupEnvRestore::query()->value('target_file'));
    }

    public function test_the_view_shows_the_env_contents_in_a_readonly_field(): void
    {
        $this->file->update(['size_bytes' => 28]);
        $this->mock(R2Cli::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('download')->once()->andReturnUsing(function (string $key, string $destination): mixed {
                file_put_contents($destination, "DB_PASSWORD=segreto-di-test\n");

                return Process::result();
            });
        });

        Livewire::test(ViewBackupEnv::class, ['record' => $this->file->backupEnv->getRouteKey()])
            ->assertSee('DB_PASSWORD=segreto-di-test')
            ->assertSeeHtml('readonly');
    }

    public function test_the_view_does_not_download_a_key_outside_the_env_prefix(): void
    {
        $this->file->update(['size_bytes' => 28, 'r2_key' => 'srv-test/databases/alfa/alfa.sql.gz']);
        $this->mock(R2Cli::class, function ($mock): void {
            $mock->shouldReceive('download')->never();
        });

        Livewire::test(ViewBackupEnv::class, ['record' => $this->file->backupEnv->getRouteKey()])
            ->assertSee('Percorso del backup non valido')
            ->assertDontSee('segreto-di-test');
    }
}
