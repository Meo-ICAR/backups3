<?php

namespace Tests\Feature\Anonymization;

use App\Jobs\RestoreBackup;
use App\Models\AnonymizedBackup;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;

class AnonymizedScheduleTest extends AnonymizationTestCase
{
    private FakeAnonymizedStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new FakeAnonymizedStore;
        $this->app->instance(\App\Services\AnonymizedBackupStore::class, $this->store);
        config(['mail.default' => 'array', 'backup_paths.admin_emails' => ['admin@example.com'], 'backup_paths.notify_failures' => true]);
    }

    /** Sostituisce backup:anonymized con un finto che registra le chiamate, crea il record come il vero e risponde con i codici indicati. */
    private function fakeBackupCommand(array $exitCodes = []): void
    {
        Artisan::command('backup:anonymized {database*} {--require-upload}', function () use ($exitCodes): int {
            $database = $this->argument('database')[0];
            $calls = (array) cache()->get('anon-calls', []);
            $calls[] = ['database' => $database, 'require' => (bool) $this->option('require-upload')];
            cache()->put('anon-calls', $calls);

            $exit = $exitCodes[$database] ?? 0;
            AnonymizedBackup::factory()->{$exit === 0 ? 'done' : 'failed'}()->create(['database_name' => $database]);

            return $exit;
        });
    }

    /** @return list<array<string, mixed>> richieste inviate a UnicoBPM */
    private function bpmRequests(): array
    {
        $sent = [];

        Http::assertSent(function ($request) use (&$sent): bool {
            if (str_starts_with($request->url(), 'https://bpm.test/')) {
                $sent[] = $request->data();
            }

            return true;
        });

        return $sent;
    }

    private function useBpm(int $status = 200): void
    {
        config(['backup_paths.bpm.url' => 'https://bpm.test', 'backup_paths.bpm.key' => 'chiave-prova', 'backup_paths.destination_prefix' => 'srv-test']);
        Http::fake(['bpm.test/*' => Http::response([], $status), '*' => Http::response('', 200)]);
    }

    /** @return list<string> oggetti delle e-mail inviate */
    private function sentSubjects(): array
    {
        return array_map(fn ($message): string => $message->getOriginalMessage()->getSubject(), Mail::mailer('array')->getSymfonyTransport()->messages()->all());
    }

    public function test_by_default_copies_go_in_the_maintenance_folder_of_the_existing_r2_bucket(): void
    {
        config(['filesystems.disks.r2.bucket' => 'backup', 'filesystems.disks.r2.endpoint' => 'https://x.r2.cloudflarestorage.com', 'filesystems.disks.r2.key' => 'k', 'filesystems.disks.r2.secret' => 's']);
        $store = new \App\Services\AnonymizedBackupStore;

        $this->assertTrue($store->isConfigured());
        $this->assertSame('backup', $store->destination()['bucket']);
        $this->assertSame('maintenance/unicosuite/', $store->keyPrefix('unicosuite'));
        $this->assertNotSame('databases/', substr($store->keyPrefix('unicosuite'), 0, 10), 'Mai nella cartella dei backup veri.');
        $this->assertSame('maintenance/suite/unicosuite/', $store->keyPrefix('unicosuite', 'suite'));
    }

    public function test_dedicated_anon_values_win_over_the_shared_r2_disk(): void
    {
        config(['filesystems.disks.r2.bucket' => 'backup', 'filesystems.disks.r2.endpoint' => 'https://x.r2.cloudflarestorage.com', 'filesystems.disks.r2.key' => 'k', 'filesystems.disks.r2.secret' => 's',
            'anonymize.destination.bucket' => 'solo-anonimizzati', 'anonymize.destination.key' => 'k2', 'anonymize.destination.secret' => 's2', 'anonymize.destination.folder' => 'manut']);
        $destination = (new \App\Services\AnonymizedBackupStore)->destination();

        $this->assertSame(['solo-anonimizzati', 'https://x.r2.cloudflarestorage.com', 'k2', 's2', 'manut'], [$destination['bucket'], $destination['endpoint'], $destination['key'], $destination['secret'], $destination['folder']]);
    }

    public function test_the_nightly_run_copies_each_database_requires_the_upload_prunes_and_pings(): void
    {
        config(['anonymize.schedule.databases' => ['alfa', 'beta'], 'anonymize.schedule.heartbeat_url' => 'https://hc.test/abc', 'anonymize.schedule.remote_retention_days' => 7]);
        $this->fakeBackupCommand();
        Http::fake();

        $this->assertSame(0, Artisan::call('backup:anonymized-nightly'));

        $this->assertSame([['database' => 'alfa', 'require' => true], ['database' => 'beta', 'require' => true]], cache()->get('anon-calls'));
        $this->assertSame(['alfa' => 7, 'beta' => 7], $this->store->pruned);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://hc.test/abc');
        $this->assertSame([], $this->sentSubjects());
    }

    public function test_a_failed_database_makes_the_run_fail_pings_fail_and_warns_the_admins_with_names_only(): void
    {
        config(['anonymize.schedule.databases' => ['alfa', 'beta'], 'anonymize.schedule.heartbeat_url' => 'https://hc.test/abc']);
        $this->fakeBackupCommand(['beta' => 1]);
        Http::fake();

        $this->assertSame(1, Artisan::call('backup:anonymized-nightly'));

        Http::assertSent(fn ($request): bool => $request->url() === 'https://hc.test/abc/fail');
        $this->assertCount(1, $this->sentSubjects());
        $this->assertStringContainsString('Backup anonimizzato fallito', $this->sentSubjects()[0]);
    }

    public function test_without_the_dedicated_bucket_the_run_fails_loudly_and_copies_nothing(): void
    {
        config(['anonymize.schedule.databases' => ['alfa']]);
        $this->store->configured = false;
        $this->fakeBackupCommand();

        $this->assertSame(1, Artisan::call('backup:anonymized-nightly'));

        $this->assertNull(cache()->get('anon-calls'));
        $this->assertCount(1, $this->sentSubjects());
    }

    public function test_the_nightly_run_reports_the_outcome_to_unicobpm_with_the_same_event_as_the_nightly_backup(): void
    {
        config(['anonymize.schedule.databases' => ['alfa', 'beta']]);
        $this->fakeBackupCommand(['beta' => 1]);
        $this->useBpm();

        $this->assertSame(1, Artisan::call('backup:anonymized-nightly'));

        $bpm = $this->bpmRequests();
        $this->assertCount(1, $bpm);
        $this->assertSame('backup.esito', $bpm[0]['event']);
        $this->assertSame('backups3', $bpm[0]['source']);
        $this->assertStringStartsWith('backup.esito:srv-test:anonymized:', $bpm[0]['event_id']);
        $this->assertSame('anonymized', $bpm[0]['data']['kind']);
        $this->assertSame('failed', $bpm[0]['data']['status']);
        $this->assertSame(['beta'], $bpm[0]['data']['databases_failed']);
        $this->assertSame(['alfa' => 0, 'beta' => 1], $bpm[0]['data']['exit_codes']);
        $this->assertSame(2, $bpm[0]['data']['databases'][0]['size_kb']);
        $this->assertStringContainsString('Controllo finale', $bpm[0]['data']['databases'][1]['error']);
        $this->assertCount(1, $this->sentSubjects(), 'Un fallimento manda anche l\'email agli amministratori.');
    }

    public function test_a_successful_nightly_run_tells_unicobpm_ok_and_sends_no_email(): void
    {
        config(['anonymize.schedule.databases' => ['alfa', 'beta']]);
        $this->fakeBackupCommand();
        $this->useBpm();

        $this->assertSame(0, Artisan::call('backup:anonymized-nightly'));

        $bpm = $this->bpmRequests();
        $this->assertSame('ok', $bpm[0]['data']['status']);
        $this->assertSame(2, $bpm[0]['data']['counts']['saved']);
        $this->assertSame([], $this->sentSubjects());
    }

    public function test_if_unicobpm_does_not_answer_the_email_says_it_is_the_only_warning(): void
    {
        config(['anonymize.schedule.databases' => ['alfa']]);
        $this->fakeBackupCommand(['alfa' => 1]);
        $this->useBpm(status: 500);

        $this->assertSame(1, Artisan::call('backup:anonymized-nightly'));

        $this->assertCount(1, $this->sentSubjects());
        $body = (string) Mail::mailer('array')->getSymfonyTransport()->messages()->first()->getOriginalMessage()->getBody()->bodyToString();
        $this->assertStringContainsString('UnicoBPM non ha ricevuto', $body);
    }

    public function test_a_failed_restore_reports_to_unicobpm_as_anonymized_restore(): void
    {
        $this->useBpm();
        $this->store->latest = null;

        $this->assertSame(1, Artisan::call('backup:restore-anonymized', ['database' => ['alfa']]));

        $bpm = $this->bpmRequests();
        $this->assertSame('backup.esito', $bpm[0]['event']);
        $this->assertSame('anonymized_restore', $bpm[0]['data']['kind']);
        $this->assertSame('failed', $bpm[0]['data']['status']);
        $this->assertStringContainsString('nessuna copia anonimizzata', $bpm[0]['data']['databases'][0]['error']);
        $this->assertCount(1, $this->sentSubjects());
        $this->assertStringContainsString('Ripristino del backup anonimizzato fallito', $this->sentSubjects()[0]);
    }

    public function test_the_regular_nightly_payload_is_unchanged(): void
    {
        $payload = \App\Services\BpmReporter::payload(['databases' => 0], [], 5.0, now()->setDate(2026, 10, 12)->setTime(2, 0), kind: 'nightly');

        $this->assertArrayNotHasKey('kind', $payload['data']);
        $this->assertSame('2026-10-12', substr($payload['subject_ref'], -10));
        $this->assertStringEndsWith(':2026-10-12_02-00', $payload['event_id']);
        $this->assertStringNotContainsString('anonymized', $payload['event_id']);
    }

    public function test_the_restore_replaces_the_target_completely_with_the_latest_copy(): void
    {
        $target = $this->trackSchema('anon_dst_'.bin2hex(random_bytes(4)));
        $this->prepareCopy(hoursOld: 3);
        DB::statement("CREATE DATABASE `{$target}`");
        DB::statement("CREATE TABLE `{$target}`.`vecchia_tabella` (id INT)");
        config(['anonymize.restore.targets' => "alfa:{$target}", 'anonymize.restore.heartbeat_url' => 'https://hc.test/restore']);
        Http::fake();

        $this->assertSame(0, Artisan::call('backup:restore-anonymized', ['database' => ['alfa']]), Artisan::output());

        $this->assertSame(2, (int) DB::selectOne("SELECT COUNT(*) AS n FROM `{$target}`.`clients`")->n);
        $this->assertNull(DB::selectOne('SELECT 1 AS x FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$target, 'vecchia_tabella']), 'Le tabelle che non sono nella copia spariscono.');
        Http::assertSent(fn ($request): bool => $request->url() === 'https://hc.test/restore');
        $this->assertSame($target, cache()->get('anonymized:last-restore:alfa')['target']);
        $this->assertSame([], glob(storage_path('app/backups/anonymized-restore/*')) ?: [], 'Il file scaricato viene eliminato.');
    }

    public function test_without_a_mapping_the_target_is_the_database_name_with_the_anon_suffix_never_the_original(): void
    {
        $this->trackSchema('alfa_anon');
        $this->prepareCopy(hoursOld: 1);

        $this->assertSame(0, Artisan::call('backup:restore-anonymized', ['database' => ['alfa']]), Artisan::output());

        $this->assertSame(2, (int) DB::selectOne('SELECT COUNT(*) AS n FROM `alfa_anon`.`clients`')->n);
    }

    public function test_a_copy_older_than_the_limit_is_not_restored_and_the_target_is_left_alone(): void
    {
        $target = $this->trackSchema('anon_dst_'.bin2hex(random_bytes(4)));
        $this->prepareCopy(hoursOld: 40);
        DB::statement("CREATE DATABASE `{$target}`");
        DB::statement("CREATE TABLE `{$target}`.`resta` (id INT)");
        config(['anonymize.restore.targets' => "alfa:{$target}", 'anonymize.restore.heartbeat_url' => 'https://hc.test/restore']);
        Http::fake();

        $this->assertSame(1, Artisan::call('backup:restore-anonymized', ['database' => ['alfa']]));

        $this->assertStringContainsString('troppo vecchia', Artisan::output());
        $this->assertNotNull(DB::selectOne('SELECT 1 AS x FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$target, 'resta']));
        Http::assertSent(fn ($request): bool => $request->url() === 'https://hc.test/restore/fail');
        $this->assertCount(1, $this->sentSubjects());
    }

    public function test_it_never_replaces_the_database_of_the_app_itself(): void
    {
        $own = (string) config('database.connections.'.config('database.default').'.database');
        $this->prepareCopy(hoursOld: 1);
        config(['anonymize.restore.targets' => "alfa:{$own}"]);
        $tables = DB::selectOne('SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?', [$own])->n;

        $this->assertSame(1, Artisan::call('backup:restore-anonymized', ['database' => ['alfa']]));

        $this->assertStringContainsString('stessa app', Artisan::output());
        $this->assertSame($tables, DB::selectOne('SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?', [$own])->n);
    }

    public function test_an_unmapped_existing_target_needs_confirmation_or_yes(): void
    {
        $target = $this->trackSchema('anon_dst_'.bin2hex(random_bytes(4)));
        $this->prepareCopy(hoursOld: 1);
        DB::statement("CREATE DATABASE `{$target}`");
        DB::statement("CREATE TABLE `{$target}`.`resta` (id INT)");

        $this->assertSame(1, Artisan::call('backup:restore-anonymized', ['database' => ['alfa'], '--target' => $target]));
        $this->assertNotNull(DB::selectOne('SELECT 1 AS x FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$target, 'resta']));

        $this->assertSame(0, Artisan::call('backup:restore-anonymized', ['database' => ['alfa'], '--target' => $target, '--yes' => true, '--server' => 'srv-prod']));
        $this->assertSame('srv-prod', $this->store->latestServer);
        $this->assertNull(DB::selectOne('SELECT 1 AS x FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$target, 'resta']));
    }

    public function test_no_copy_in_the_bucket_or_no_bucket_is_a_failure_with_the_fail_ping(): void
    {
        config(['anonymize.restore.heartbeat_url' => 'https://hc.test/restore']);
        Http::fake();
        $this->store->latest = null;

        $this->assertSame(1, Artisan::call('backup:restore-anonymized', ['database' => ['alfa']]));

        $this->store->configured = false;
        $this->assertSame(1, Artisan::call('backup:restore-anonymized', ['database' => ['alfa']]));

        Http::assertSentCount(2);
    }

    /** Prepara una copia «scaricabile» fatta con un vero dump di un database di prova. */
    private function prepareCopy(int $hoursOld): void
    {
        $source = $this->makeSource();
        $credentials = RestoreBackup::credentials();
        $file = sys_get_temp_dir().'/anon-copy-'.bin2hex(random_bytes(4)).'.sql.gz';

        $dump = Process::env(['MYSQL_PWD' => (string) $credentials['password']])->run(['bash', '-o', 'pipefail', '-c', 'mysqldump --host="$1" --user="$2" --no-tablespaces "$3" | gzip > "$4"', 'dump', $credentials['host'], $credentials['username'], $source, $file]);
        $this->assertTrue($dump->successful(), $dump->errorOutput());

        $at = now()->subHours($hoursOld)->toImmutable();
        $this->store->dumpFile = $file;
        $this->store->latest = ['key' => 'srv/anonymized/alfa/alfa_anon_'.$at->format('Y-m-d_H-i-s').'.sql.gz', 'at' => $at];
    }
}
