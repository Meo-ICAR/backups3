<?php

namespace Tests\Feature\Anonymization;

use App\Filament\Resources\AnonymizedBackups\Pages\ListAnonymizedBackups;
use App\Filament\Resources\BackupDbs\Pages\ListBackupDbs;
use App\Jobs\RunAnonymizedBackup;
use App\Models\AnonymizedBackup;
use App\Models\BackupDb;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

class AnonymizedPanelTest extends AnonymizationTestCase
{
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
    }

    public function test_the_history_page_shows_outcome_and_check_result(): void
    {
        $done = AnonymizedBackup::factory()->done()->create();
        $failed = AnonymizedBackup::factory()->failed()->create();

        $this->get('/admin/anonimizzati')->assertOk();

        Livewire::test(ListAnonymizedBackups::class)
            ->assertCanSeeTableRecords([$done, $failed])
            ->assertSee('Completato')
            ->assertSee('Fallito')
            ->assertSee('Controllo finale');
    }

    public function test_the_bulk_action_starts_one_job_per_database_and_only_keeps_the_password_hash(): void
    {
        Bus::fake();
        $alfa = BackupDb::query()->where('database_name', 'alfa')->firstOrFail();
        $whistle = BackupDb::query()->create(['server_name' => 'srv-test', 'database_name' => 'whistle']);

        Livewire::test(ListBackupDbs::class)
            ->callTableBulkAction('anonimizza', [$alfa, $whistle], ['admin_email' => 'manut@example.com', 'password' => 'Password-lunga-1']);

        Bus::assertDispatchedAfterResponse(RunAnonymizedBackup::class, function (RunAnonymizedBackup $job): bool {
            return $job->backup->database_name === 'alfa'
                && $job->adminEmail === 'manut@example.com'
                && Hash::check('Password-lunga-1', $job->passwordHash)
                && $job->passwordHash !== 'Password-lunga-1';
        });
        Bus::assertDispatchedAfterResponse(RunAnonymizedBackup::class, 1);

        $backup = AnonymizedBackup::query()->firstOrFail();
        $this->assertSame('alfa', $backup->database_name);
        $this->assertSame(AnonymizedBackup::PENDING, $backup->status);
        $this->assertStringNotContainsString('Password-lunga-1', (string) json_encode($backup->toArray()));
    }

    public function test_the_action_needs_a_long_enough_password_and_an_email(): void
    {
        Bus::fake();
        $alfa = BackupDb::query()->where('database_name', 'alfa')->firstOrFail();

        Livewire::test(ListBackupDbs::class)
            ->callTableBulkAction('anonimizza', [$alfa], ['admin_email' => 'non-una-email', 'password' => 'corta'])
            ->assertHasTableBulkActionErrors(['admin_email', 'password']);

        Bus::assertNotDispatchedAfterResponse(RunAnonymizedBackup::class);
        $this->assertSame(0, AnonymizedBackup::query()->count());
    }

    public function test_databases_of_another_server_are_skipped(): void
    {
        Bus::fake();
        $other = BackupDb::query()->create(['server_name' => 'altro-server', 'database_name' => 'beta']);

        Livewire::test(ListBackupDbs::class)
            ->filterTable('server_name', 'altro-server')
            ->callTableBulkAction('anonimizza', [$other], ['admin_email' => 'manut@example.com', 'password' => 'Password-lunga-1']);

        Bus::assertNotDispatchedAfterResponse(RunAnonymizedBackup::class);
    }
}
