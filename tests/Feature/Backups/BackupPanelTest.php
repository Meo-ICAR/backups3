<?php

namespace Tests\Feature\Backups;

use App\Filament\Resources\BackupDbs\Pages\EditBackupDb;
use App\Filament\Resources\BackupDbs\Pages\ListBackupDbs;
use App\Filament\Resources\BackupDbs\RelationManagers\FilesRelationManager;
use App\Jobs\RestoreBackup;
use App\Jobs\RunDatabaseBackup;
use App\Models\BackupDb;
use App\Models\BackupDbFile;
use App\Models\BackupDbRestore;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

class BackupPanelTest extends BackupDbTestCase
{
    private BackupDbFile $file;

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

        $backupDb = BackupDb::query()->where('database_name', 'alfa')->firstOrFail();
        $this->file = BackupDbFile::query()->create([
            'backupdb_id' => $backupDb->id,
            'r2_key' => 'srv-test/databases/alfa/alfa_2026-10-20_02-00-00.sql.gz',
            'backed_up_at' => '2026-10-20 02:00:00',
            'size_bytes' => 5 * 1024 * 1024,
        ]);
    }

    public function test_the_header_shows_the_current_server_name(): void
    {
        $this->get('/admin/database')
            ->assertSee('Backup · srv-test', false);
    }

    public function test_the_list_shows_server_database_and_last_backup(): void
    {
        $this->get('/admin/database')->assertOk();

        Livewire::test(ListBackupDbs::class)
            ->assertCanSeeTableRecords(BackupDb::query()->get())
            ->assertSee('alfa')
            ->assertSee('5.0 MB');
    }

    public function test_the_list_defaults_to_this_server_and_the_filter_can_show_another(): void
    {
        $other = BackupDb::query()->create(['server_name' => 'altro-server', 'database_name' => 'beta']);
        $mine = BackupDb::query()->where('server_name', 'srv-test')->get();

        Livewire::test(ListBackupDbs::class)
            ->assertCanSeeTableRecords($mine)
            ->assertCanNotSeeTableRecords([$other])
            ->filterTable('server_name', 'altro-server')
            ->assertCanSeeTableRecords([$other])
            ->assertCanNotSeeTableRecords($mine);
    }

    public function test_a_user_not_in_the_list_cannot_open_the_panel(): void
    {
        $this->actingAs(User::query()->create(['name' => 'Altro', 'email' => 'altro@example.com', 'password' => 'secret']));

        $this->get('/admin/database')->assertForbidden();
    }

    public function test_restoring_into_a_new_database_queues_the_job(): void
    {
        Bus::fake();

        Livewire::test(FilesRelationManager::class, ['ownerRecord' => $this->file->backupDb, 'pageClass' => EditBackupDb::class])
            ->assertCanSeeTableRecords([$this->file])
            ->callAction(TestAction::make('ripristina')->table($this->file), ['mode' => 'nuovo', 'target_database' => 'alfa_prova'])
            ->assertHasNoFormErrors();

        $restore = BackupDbRestore::query()->firstOrFail();
        $this->assertSame('alfa_prova', $restore->target_database);
        $this->assertFalse($restore->overwrites_original);
        Bus::assertDispatchedAfterResponse(RestoreBackup::class);
    }

    public function test_overwriting_the_original_requires_typing_its_name(): void
    {
        Bus::fake();

        $manager = fn () => Livewire::test(FilesRelationManager::class, ['ownerRecord' => $this->file->backupDb, 'pageClass' => EditBackupDb::class]);

        $manager()->callAction(TestAction::make('ripristina')->table($this->file), ['mode' => 'originale', 'conferma' => 'sbagliato'])
            ->assertHasFormErrors(['conferma']);
        $this->assertSame(0, BackupDbRestore::query()->count());

        $manager()->callAction(TestAction::make('ripristina')->table($this->file), ['mode' => 'originale', 'conferma' => 'alfa'])
            ->assertHasNoFormErrors();
        $this->assertTrue(BackupDbRestore::query()->firstOrFail()->overwrites_original);
        $this->assertSame('alfa', BackupDbRestore::query()->firstOrFail()->target_database);
    }

    public function test_the_new_database_cannot_be_the_original(): void
    {
        Livewire::test(FilesRelationManager::class, ['ownerRecord' => $this->file->backupDb, 'pageClass' => EditBackupDb::class])
            ->callAction(TestAction::make('ripristina')->table($this->file), ['mode' => 'nuovo', 'target_database' => 'alfa'])
            ->assertHasFormErrors(['target_database']);
    }

    public function test_a_backup_of_another_server_can_only_be_restored_into_a_new_database(): void
    {
        Bus::fake();
        $other = BackupDb::query()->create(['server_name' => 'altro-server', 'database_name' => 'beta']);
        $file = BackupDbFile::query()->create(['backupdb_id' => $other->id, 'r2_key' => 'altro-server/databases/beta/beta_2026-10-20_02-00-00.sql.gz', 'backed_up_at' => '2026-10-20 02:00:00', 'size_bytes' => 1]);

        $manager = fn () => Livewire::test(FilesRelationManager::class, ['ownerRecord' => $other, 'pageClass' => EditBackupDb::class]);

        // L'opzione «originale» non è tra quelle offerte: il database locale con lo stesso nome non si sovrascrive.
        $manager()->callAction(TestAction::make('ripristina')->table($file), ['mode' => 'originale', 'conferma' => 'beta'])
            ->assertHasFormErrors(['mode']);
        $this->assertSame(0, BackupDbRestore::query()->count());

        $manager()->callAction(TestAction::make('ripristina')->table($file), ['mode' => 'nuovo', 'target_database' => 'beta_locale'])
            ->assertHasNoFormErrors();

        $restore = BackupDbRestore::query()->firstOrFail();
        $this->assertFalse($restore->overwrites_original);
        Bus::assertDispatchedAfterResponse(RestoreBackup::class);
    }

    public function test_bulk_backup_runs_for_the_selected_databases_of_this_server(): void
    {
        Bus::fake();
        $proforma = BackupDb::query()->create(['server_name' => 'srv-test', 'database_name' => 'proforma']);

        Livewire::test(ListBackupDbs::class)
            ->callTableBulkAction('backup', [$this->file->backupDb, $proforma]);

        Bus::assertDispatchedAfterResponse(RunDatabaseBackup::class, function (RunDatabaseBackup $job): bool {
            return $job->databases === ['alfa', 'proforma'];
        });
    }

    public function test_bulk_backup_does_not_start_for_a_database_on_another_server(): void
    {
        Bus::fake();
        $other = BackupDb::query()->create(['server_name' => 'altro-server', 'database_name' => 'beta']);

        Livewire::test(ListBackupDbs::class)
            ->filterTable('server_name', 'altro-server')
            ->callTableBulkAction('backup', [$other]);

        Bus::assertNotDispatchedAfterResponse(RunDatabaseBackup::class);
    }

    public function test_bulk_restore_uses_the_latest_backup_in_a_new_database(): void
    {
        Bus::fake();
        $latest = BackupDbFile::query()->create([
            'backupdb_id' => $this->file->backupDb->id,
            'r2_key' => 'srv-test/databases/alfa/alfa_2026-10-21_02-00-00.sql.gz',
            'backed_up_at' => '2026-10-21 02:00:00',
            'size_bytes' => 10,
        ]);

        Livewire::test(ListBackupDbs::class)
            ->callTableBulkAction('ripristina', [$this->file->backupDb], ['mode' => 'nuovo'])
            ->assertHasNoFormErrors();

        $restore = BackupDbRestore::query()->firstOrFail();
        $this->assertSame($latest->id, $restore->backupdb_file_id);
        $this->assertSame('alfa_restore', $restore->target_database);
        $this->assertFalse($restore->overwrites_original);
        Bus::assertDispatchedAfterResponse(RestoreBackup::class);
    }

    public function test_bulk_restore_uses_a_suffix_other_than_restore(): void
    {
        Bus::fake();

        Livewire::test(ListBackupDbs::class)
            ->callTableBulkAction('ripristina', [$this->file->backupDb], ['mode' => 'nuovo', 'suffisso' => '_test'])
            ->assertHasNoFormErrors();

        $restore = BackupDbRestore::query()->firstOrFail();
        $this->assertSame('alfa_test', $restore->target_database);
        $this->assertFalse($restore->overwrites_original);
    }

    public function test_bulk_restore_rejects_a_suffix_without_the_leading_underscore(): void
    {
        Bus::fake();

        Livewire::test(ListBackupDbs::class)
            ->callTableBulkAction('ripristina', [$this->file->backupDb], ['mode' => 'nuovo', 'suffisso' => 'test'])
            ->assertHasFormErrors(['suffisso']);

        $this->assertSame(0, BackupDbRestore::query()->count());
        Bus::assertNotDispatchedAfterResponse(RestoreBackup::class);
    }

    public function test_bulk_restore_skips_a_database_that_has_no_backup(): void
    {
        Bus::fake();
        $empty = BackupDb::query()->create(['server_name' => 'srv-test', 'database_name' => 'vuoto']);

        Livewire::test(ListBackupDbs::class)
            ->callTableBulkAction('ripristina', [$this->file->backupDb, $empty], ['mode' => 'nuovo'])
            ->assertHasNoFormErrors();

        $this->assertSame(1, BackupDbRestore::query()->count());
        $this->assertSame('alfa_restore', BackupDbRestore::query()->value('target_database'));
    }

    public function test_bulk_restore_over_the_originals_requires_typing_sovrascrivi(): void
    {
        Bus::fake();
        $page = fn () => Livewire::test(ListBackupDbs::class);

        $page()->callTableBulkAction('ripristina', [$this->file->backupDb], ['mode' => 'originale', 'conferma' => 'sbagliato'])
            ->assertHasFormErrors(['conferma']);
        $this->assertSame(0, BackupDbRestore::query()->count());

        $page()->callTableBulkAction('ripristina', [$this->file->backupDb], ['mode' => 'originale', 'conferma' => 'sovrascrivi'])
            ->assertHasNoFormErrors();

        $restore = BackupDbRestore::query()->firstOrFail();
        $this->assertTrue($restore->overwrites_original);
        $this->assertSame('alfa', $restore->target_database);
        Bus::assertDispatchedAfterResponse(RestoreBackup::class);
    }

    public function test_bulk_restore_cannot_overwrite_a_database_from_another_server(): void
    {
        Bus::fake();
        $other = BackupDb::query()->create(['server_name' => 'altro-server', 'database_name' => 'beta']);
        BackupDbFile::query()->create([
            'backupdb_id' => $other->id,
            'r2_key' => 'altro-server/databases/beta/beta_2026-10-20_02-00-00.sql.gz',
            'backed_up_at' => '2026-10-20 02:00:00',
            'size_bytes' => 1,
        ]);

        Livewire::test(ListBackupDbs::class)
            ->filterTable('server_name', 'altro-server')
            ->callTableBulkAction('ripristina', [$other], ['mode' => 'originale', 'conferma' => 'sovrascrivi'])
            ->assertHasFormErrors(['mode']);

        $this->assertSame(0, BackupDbRestore::query()->count());
        Bus::assertNotDispatchedAfterResponse(RestoreBackup::class);
    }
}
