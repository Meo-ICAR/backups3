<?php

namespace Tests\Feature\Anonymization;

use App\Jobs\RunAnonymizedBackup;
use App\Models\AnonymizedBackup;
use App\Services\AnonymizedBackupStore;
use App\Services\Anonymizer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class BackupAnonymizedCommandTest extends AnonymizationTestCase
{
    public function test_it_exports_an_anonymized_copy_and_leaves_the_source_untouched(): void
    {
        $source = $this->makeSource();

        $exit = Artisan::call('backup:anonymized', ['database' => [$source], '--admin-email' => 'manutenzione@example.com', '--password-hash' => Hash::make('Password-di-prova-1')]);

        $this->assertSame(0, $exit, Artisan::output());

        $backup = AnonymizedBackup::query()->firstOrFail();
        $this->assertSame(AnonymizedBackup::DONE, $backup->status);
        $this->assertTrue($backup->scanIsClean());
        $this->assertTrue($backup->report['maintenance_login']);
        $this->assertNull($backup->remote_key, 'Senza bucket dedicato il file resta in locale.');
        $this->assertFileExists($backup->local_path);
        $this->assertStringNotContainsString('backups/databases', $backup->local_path);

        $dump = (string) gzdecode((string) file_get_contents($backup->local_path));

        foreach ($this->originalValues() as $original) {
            $this->assertStringNotContainsString($original, $dump, "Nel file anonimizzato resta «{$original}».");
        }

        $this->assertStringContainsString('@example.invalid', $dump);
        $this->assertStringContainsString('manutenzione@example.com', $dump);
        $this->assertStringNotContainsString('clients_note_trigger', $dump, 'Il file non contiene i trigger.');

        $this->assertSame('mario.rossi@gmail.com', DB::selectOne("SELECT email FROM `{$source}`.`clients` ORDER BY id LIMIT 1")->email, 'Il database di origine non cambia.');
        $this->assertSame([], DB::select("SHOW DATABASES LIKE 'anontmp\\_%'"), 'Il database temporaneo viene eliminato.');
    }

    public function test_it_sends_the_file_to_the_dedicated_bucket_when_configured(): void
    {
        $source = $this->makeSource();

        $this->app->instance(AnonymizedBackupStore::class, new class extends AnonymizedBackupStore
        {
            public ?string $uploaded = null;

            public function isConfigured(): bool
            {
                return true;
            }

            public function upload(string $file, string $database): string
            {
                $this->uploaded = $file;

                return "srv/anonymized/{$database}/".basename($file);
            }
        });

        $this->assertSame(0, Artisan::call('backup:anonymized', ['database' => [$source], '--password-hash' => Hash::make('x')]));

        $this->assertStringStartsWith("srv/anonymized/{$source}/", (string) AnonymizedBackup::query()->firstOrFail()->remote_key);
    }

    public function test_a_residue_of_personal_data_blocks_the_export_and_is_never_written_in_the_record(): void
    {
        $source = $this->makeSource(withUncoveredColumn: true);

        $exit = Artisan::call('backup:anonymized', ['database' => [$source], '--password-hash' => Hash::make('x')]);

        $this->assertSame(1, $exit);

        $backup = AnonymizedBackup::query()->firstOrFail();
        $this->assertSame(AnonymizedBackup::FAILED, $backup->status);
        $this->assertNull($backup->local_path);
        $this->assertFalse($backup->scanIsClean());
        $this->assertSame('riservato', $backup->report['scan']['findings'][0]['column']);
        $this->assertStringNotContainsString('mario', (string) json_encode($backup->toArray()));
        $this->assertStringNotContainsString('mario', Artisan::output());
        $this->assertFalse(File::exists((string) config('anonymize.local_directory')) && File::allFiles((string) config('anonymize.local_directory')) !== [], 'Nessun file prodotto.');
        $this->assertSame([], DB::select("SHOW DATABASES LIKE 'anontmp\\_%'"));
    }

    public function test_an_error_message_does_not_keep_quoted_values(): void
    {
        $source = $this->makeSource();

        $this->app->instance(Anonymizer::class, new class extends Anonymizer
        {
            public function anonymize(string $schema, ?string $passwordHash = null): array
            {
                throw new RuntimeException("Duplicate entry 'mario.rossi@gmail.com' for key 'clients.email'");
            }
        });

        $this->assertSame(1, Artisan::call('backup:anonymized', ['database' => [$source], '--password-hash' => Hash::make('x')]));

        $error = (string) AnonymizedBackup::query()->firstOrFail()->error;
        $this->assertStringNotContainsString('mario', $error);
        $this->assertStringContainsString("Duplicate entry '…'", $error);
        $this->assertStringNotContainsString('mario', Artisan::output());
        $this->assertSame([], DB::select("SHOW DATABASES LIKE 'anontmp\\_%'"));
    }

    public function test_it_refuses_whistleblowing_system_unknown_and_invalid_databases(): void
    {
        foreach (['whistle', 'mysql', 'anontmp_abc123', 'non_esiste_proprio', 'db; drop'] as $name) {
            $this->assertSame(1, Artisan::call('backup:anonymized', ['database' => [$name]]), $name);
        }

        $this->assertSame(0, AnonymizedBackup::query()->count());
    }

    public function test_a_second_run_on_the_same_database_is_refused_while_one_is_in_progress(): void
    {
        $source = $this->makeSource();
        $lock = Cache::lock("backup:anonymized:{$source}", 60);
        $this->assertTrue($lock->get());

        $this->assertSame(1, Artisan::call('backup:anonymized', ['database' => [$source], '--password-hash' => Hash::make('x')]));
        $this->assertStringContainsString('già in corso', (string) AnonymizedBackup::query()->firstOrFail()->error);

        $lock->release();
    }

    public function test_audit_lists_the_coverage_without_reading_data_or_creating_anything(): void
    {
        $source = $this->makeSource(withUncoveredColumn: true);

        $this->assertSame(0, Artisan::call('backup:anonymized', ['database' => [$source], '--audit' => true]));

        $output = Artisan::output();
        $this->assertStringContainsString('nessun dato letto', $output);
        $this->assertStringContainsString('clients.riservato', $output);
        $this->assertStringNotContainsString('mario', $output);
        $this->assertSame(0, AnonymizedBackup::query()->count());
        $this->assertSame([], DB::select("SHOW DATABASES LIKE 'anontmp\\_%'"));
    }

    public function test_the_panel_job_passes_the_record_and_the_login_to_the_command(): void
    {
        $backup = AnonymizedBackup::factory()->create();

        Artisan::command('backup:anonymized {database*} {--record=} {--admin-email=} {--password-hash=}', function (): int {
            Cache::put('anon-call', ['database' => $this->argument('database'), 'record' => $this->option('record'), 'email' => $this->option('admin-email'), 'hash' => $this->option('password-hash')]);

            return 0;
        });

        (new RunAnonymizedBackup($backup, 'm@example.com', 'hash-bcrypt'))->handle();

        $this->assertSame(['database' => ['alfa'], 'record' => $backup->id, 'email' => 'm@example.com', 'hash' => 'hash-bcrypt'], Cache::get('anon-call'));
    }
}
