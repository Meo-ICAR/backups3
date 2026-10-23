<?php

namespace Tests\Feature\Console\Commands;

use App\Mail\BackupFailed;
use App\Services\BpmReporter;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class NightlyBackupCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'backup_paths.heartbeat_url' => 'https://hc.example.com/uuid',
            'backup_paths.bpm.url' => 'https://bpm.example.com',
            'backup_paths.bpm.key' => 'chiave-bpm',
            'backup_paths.destination_prefix' => 'vps-test',
        ]);
        Cache::put('backup:last_databases', ['at' => now()->toIso8601String(), 'databases' => [
            ['name' => 'unicooam_core', 'ok' => true, 'size_kb' => 2048],
            ['name' => 'unicoloan_core', 'ok' => false, 'error' => 'RuntimeException: dump fallito'],
        ], 'destinations' => ['r2' => true]]);
        config(['backup_paths.admin_emails' => ['admin@example.com']]);
        Mail::fake();
    }

    /** Sostituisce i comandi veri con dei finti che ritornano i codici indicati. */
    private function stubCommands(int $databases = 0, int $uploads = 0, int $env = 0, int $clean = 0): void
    {
        Artisan::command('backup:databases', fn () => $databases);
        Artisan::command('backup:uploads', fn () => $uploads);
        Artisan::command('backup:env', fn () => $env);
        Artisan::command('backup:clean', fn () => $clean);
    }

    public function test_a_successful_run_pings_the_heartbeat_and_reports_ok_to_bpm(): void
    {
        $this->stubCommands();
        Http::fake(['*' => Http::response([], 202)]);

        $this->artisan('backup:nightly')->assertExitCode(0);

        Http::assertSent(fn ($request) => $request->url() === 'https://hc.example.com/uuid');
        Http::assertSent(function ($request) {
            return $request->url() === 'https://bpm.example.com/api/events'
                && $request->header('X-Api-Key') === ['chiave-bpm']
                && $request['event'] === 'backup.esito' && $request['source'] === 'backups3'
                && $request['data']['status'] === 'ok' && $request['data']['server'] === 'vps-test'
                && $request['data']['exit_codes'] === ['databases' => 0, 'uploads' => 0, 'env' => 0, 'clean' => 0]
                && str_starts_with($request['event_id'], 'backup.esito:vps-test:')
                && $request['subject_ref'] === 'vps-test:'.now()->format('Y-m-d');
        });
    }

    public function test_a_failed_env_backup_fails_the_nightly_run(): void
    {
        $this->stubCommands(env: 1);
        Http::fake(['*' => Http::response([], 202)]);

        $this->artisan('backup:nightly')->assertExitCode(1);

        Http::assertSent(fn ($request) => $request->url() === 'https://hc.example.com/uuid/fail');
        Http::assertSent(fn ($request) => ($request['data']['exit_codes']['env'] ?? null) === 1);
    }

    public function test_a_failed_run_pings_fail_and_reports_failed_with_the_databases_that_did_not_dump(): void
    {
        $this->stubCommands(databases: 1);
        Http::fake(['*' => Http::response([], 202)]);

        $this->artisan('backup:nightly')->assertExitCode(1);

        Http::assertSent(fn ($request) => $request->url() === 'https://hc.example.com/uuid/fail');
        Http::assertSent(function ($request) {
            return $request->url() === 'https://bpm.example.com/api/events'
                && $request['data']['status'] === 'failed'
                && $request['data']['databases_failed'] === ['unicoloan_core']
                && $request['data']['databases'][0] === ['name' => 'unicooam_core', 'ok' => true, 'size_kb' => 2048];
        });
    }

    public function test_an_unreachable_bpm_never_changes_the_outcome_of_the_backup(): void
    {
        $this->stubCommands();
        Http::fake(['https://bpm.example.com/*' => Http::response('boom', 503), '*' => Http::response([], 200)]);

        $this->artisan('backup:nightly')->expectsOutputToContain('Esito non comunicato a UnicoBPM')->assertExitCode(0);
    }

    public function test_without_bpm_configuration_nothing_is_sent_to_it(): void
    {
        config(['backup_paths.bpm.url' => null, 'backup_paths.bpm.key' => null]);
        $this->stubCommands();
        Http::fake(['*' => Http::response([], 200)]);

        $this->artisan('backup:nightly')->assertExitCode(0);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/api/events'));
        $this->assertFalse(BpmReporter::isConfigured());
    }

    public function test_the_event_id_is_stable_per_server_and_minute_so_a_resend_is_harmless(): void
    {
        $at = new \DateTimeImmutable('2026-10-12 02:00:30');

        $a = BpmReporter::payload(['databases' => 0, 'uploads' => 0, 'clean' => 0], [], 12.4, $at);
        $b = BpmReporter::payload(['databases' => 0, 'uploads' => 0, 'clean' => 0], [], 99.0, $at);

        $this->assertSame('backup.esito:vps-test:2026-10-12_02-00', $a['event_id']);
        $this->assertSame($a['event_id'], $b['event_id']);
        $this->assertSame(12, $a['data']['seconds']);
    }

    public function test_the_nightly_run_is_scheduled_at_two_in_the_morning(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'backup:nightly'));

        $this->assertNotNull($event);
        $this->assertSame('0 2 * * *', $event->expression);
    }

    public function test_a_failed_run_emails_the_administrators_even_when_bpm_does_not_answer(): void
    {
        $this->stubCommands(databases: 1);
        Http::fake(['https://bpm.example.com/*' => Http::response('boom', 503), '*' => Http::response([], 200)]);

        $this->artisan('backup:nightly')->expectsOutputToContain('Avviso di fallimento inviato a admin@example.com')->assertExitCode(1);

        Mail::assertSent(BackupFailed::class, function (BackupFailed $mail): bool {
            $text = $mail->render();

            return $mail->hasTo('admin@example.com') && ! $mail->bpmInformed
                && str_contains($text, 'unicoloan_core: RuntimeException: dump fallito')
                && str_contains($text, 'R2: ok')
                && str_contains($text, '1 salvati, 0 invariati, 1 falliti')
                && str_contains($text, 'questa email è l\'unico avviso');
        });
    }

    public function test_a_successful_run_sends_no_email_and_reports_destinations_and_counts_to_bpm(): void
    {
        $this->stubCommands();
        Http::fake(['*' => Http::response([], 202)]);

        $this->artisan('backup:nightly')->expectsOutputToContain('Backup notturno completato.')->assertExitCode(0);

        Mail::assertNothingSent();
        Http::assertSent(fn ($request) => $request->url() === 'https://bpm.example.com/api/events'
            && $request['data']['destinations'] === ['r2' => true]
            && $request['data']['counts'] === ['saved' => 1, 'unchanged' => 0, 'failed' => 1]);
    }

    public function test_failure_emails_can_be_turned_off(): void
    {
        config(['backup_paths.notify_failures' => false]);
        $this->stubCommands(clean: 1);
        Http::fake(['*' => Http::response([], 202)]);

        $this->artisan('backup:nightly')->assertExitCode(1);

        Mail::assertNothingSent();
    }
}
