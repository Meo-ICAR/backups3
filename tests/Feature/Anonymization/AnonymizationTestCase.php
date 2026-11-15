<?php

namespace Tests\Feature\Anonymization;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Backups\BackupDbTestCase;

/** Base per i test del backup anonimizzato: tabelle del pannello, tabella dei backup anonimizzati e database MySQL di prova da eliminare a fine test. */
abstract class AnonymizationTestCase extends BackupDbTestCase
{
    /** @var list<string> */
    private array $schemas = [];

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('anonymized_backups');
        (require database_path('migrations/2026_11_15_120000_create_anonymized_backups_table.php'))->up();

        // I test non dipendono dal .env reale: niente UnicoBPM, heartbeat o bucket veri, e nessuna richiesta di rete non simulata.
        config([
            'anonymize.local_directory' => sys_get_temp_dir().'/backups3-anon-test-'.bin2hex(random_bytes(4)),
            'backup_paths.bpm.url' => null,
            'backup_paths.bpm.key' => null,
            'anonymize.schedule.heartbeat_url' => null,
            'anonymize.restore.heartbeat_url' => null,
            'anonymize.destination' => ['folder' => 'maintenance', 'bucket' => null, 'endpoint' => null, 'key' => null, 'secret' => null, 'prefix' => null],
            'filesystems.disks.r2.bucket' => null,
            'filesystems.disks.r2.endpoint' => null,
            'filesystems.disks.r2.key' => null,
            'filesystems.disks.r2.secret' => null,
        ]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        foreach ($this->schemas as $schema) {
            DB::statement("DROP DATABASE IF EXISTS `{$schema}`");
        }

        foreach (DB::select("SHOW DATABASES LIKE 'anontmp\\_%'") as $row) {
            DB::statement('DROP DATABASE IF EXISTS `'.array_values((array) $row)[0].'`');
        }

        @exec('rm -rf '.escapeshellarg((string) config('anonymize.local_directory')));

        parent::tearDown();
    }

    /** Un database creato dal test, da eliminare a fine test. */
    protected function trackSchema(string $schema): string
    {
        $this->schemas[] = $schema;

        return $schema;
    }

    /** Crea un database con dati che sembrano reali e lo elimina a fine test. */
    protected function makeSource(bool $withUncoveredColumn = false): string
    {
        $schema = 'anon_src_'.bin2hex(random_bytes(4));
        $this->schemas[] = $schema;

        DB::statement("CREATE DATABASE `{$schema}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        $uncovered = $withUncoveredColumn ? ', `riservato` VARCHAR(100) NULL' : '';

        DB::unprepared(<<<SQL
            CREATE TABLE `{$schema}`.`clients` (
                `id` INT AUTO_INCREMENT PRIMARY KEY, `nome` VARCHAR(50), `cognome` VARCHAR(50), `email` VARCHAR(100) UNIQUE,
                `codice_fiscale` VARCHAR(16) UNIQUE, `iban` VARCHAR(34), `telefono` VARCHAR(20), `data_nascita` DATE,
                `reddito` DECIMAL(10,2), `note` TEXT NULL, `indirizzo` VARCHAR(100){$uncovered}
            );
            CREATE TABLE `{$schema}`.`pratiche` (
                `id` INT AUTO_INCREMENT PRIMARY KEY, `client_cf` VARCHAR(16), `importo` DECIMAL(10,2),
                FOREIGN KEY (`client_cf`) REFERENCES `{$schema}`.`clients`(`codice_fiscale`) ON UPDATE CASCADE
            );
            CREATE TABLE `{$schema}`.`users` (`id` INT AUTO_INCREMENT PRIMARY KEY, `name` VARCHAR(100), `email` VARCHAR(100) UNIQUE, `password` VARCHAR(100), `remember_token` VARCHAR(100) NULL);
            CREATE TABLE `{$schema}`.`activity_log` (`id` INT AUTO_INCREMENT PRIMARY KEY, `description` TEXT);
            CREATE TABLE `{$schema}`.`document_types` (`id` INT AUTO_INCREMENT PRIMARY KEY, `name` VARCHAR(100));
            CREATE TRIGGER `{$schema}`.`clients_note_trigger` BEFORE UPDATE ON `{$schema}`.`clients` FOR EACH ROW SET NEW.`note` = 'scritto dal trigger';
        SQL);

        $riservato = $withUncoveredColumn ? ", 'mario.rossi@gmail.com'" : '';
        $riservatoColumn = $withUncoveredColumn ? ', `riservato`' : '';
        $riservatoSecond = $withUncoveredColumn ? ", 'nessun dato'" : '';

        DB::unprepared(<<<SQL
            INSERT INTO `{$schema}`.`clients` (`nome`, `cognome`, `email`, `codice_fiscale`, `iban`, `telefono`, `data_nascita`, `reddito`, `note`, `indirizzo`{$riservatoColumn}) VALUES
                ('Mario', 'Rossi', 'mario.rossi@gmail.com', 'RSSMRA80A01H501U', 'IT60X0542811101000000123456', '3331234567', '1980-01-01', 25000.00, 'Cliente di Mario Rossi, chiamare dopo le 18', 'Via Garibaldi 12'{$riservato}),
                ('Anna', 'Verdi', 'anna.verdi@libero.it', 'VRDNNA85C41F205Z', 'IT28W8000000292100645211208', '3479876543', '1985-03-01', 31000.50, NULL, 'Corso Italia 3'{$riservatoSecond});
            INSERT INTO `{$schema}`.`pratiche` (`client_cf`, `importo`) VALUES ('RSSMRA80A01H501U', 15000.00), ('VRDNNA85C41F205Z', 9000.00);
            INSERT INTO `{$schema}`.`users` (`name`, `email`, `password`, `remember_token`) VALUES ('Luca Bianchi', 'luca.bianchi@studio.it', 'hash-reale-1', 'tok1'), ('Sara Neri', 'sara.neri@studio.it', 'hash-reale-2', NULL);
            INSERT INTO `{$schema}`.`activity_log` (`description`) VALUES ('Mario Rossi ha aperto la pratica');
            INSERT INTO `{$schema}`.`document_types` (`name`) VALUES ('Carta d''identità');
        SQL);

        return $schema;
    }

    /** @return list<string> */
    protected function originalValues(): array
    {
        return ['mario.rossi@gmail.com', 'RSSMRA80A01H501U', 'IT60X0542811101000000123456', '3331234567', 'Rossi', 'Garibaldi', 'anna.verdi@libero.it', 'VRDNNA85C41F205Z', 'luca.bianchi@studio.it', 'Luca Bianchi', 'Sara Neri', 'hash-reale'];
    }
}
