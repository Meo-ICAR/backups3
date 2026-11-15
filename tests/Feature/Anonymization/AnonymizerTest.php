<?php

namespace Tests\Feature\Anonymization;

use App\Services\Anonymizer;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AnonymizerTest extends AnonymizationTestCase
{
    public function test_it_replaces_personal_data_and_keeps_relations_consistent(): void
    {
        $schema = $this->makeSource();
        $anonymizer = new Anonymizer;
        $report = $anonymizer->anonymize($schema, 'hash-prova');
        $this->assertSame([], $anonymizer->scan($schema));
        $this->assertTrue($anonymizer->grantMaintenanceLogin($schema, 'Manutenzione@Example.com', 'hash-prova'));

        $client = DB::selectOne("SELECT * FROM `{$schema}`.`clients` ORDER BY id LIMIT 1");
        $this->assertStringEndsWith('@example.invalid', $client->email);
        $this->assertStringStartsWith('ZZZZZZ', $client->codice_fiscale);
        $this->assertSame(16, strlen($client->codice_fiscale));
        $this->assertStringStartsWith('IT00Z', $client->iban);
        $this->assertSame(27, strlen($client->iban));
        $this->assertStringStartsWith('+39000', $client->telefono);
        $this->assertStringStartsWith('Nome', $client->nome);
        $this->assertStringStartsWith('Cognome', $client->cognome);
        $this->assertStringStartsWith('Via Esempio', $client->indirizzo);
        $this->assertNull($client->note);
        $this->assertEqualsWithDelta(25000.00, (float) $client->reddito, 25000 * 0.31);
        $this->assertLessThanOrEqual(1826, abs((strtotime($client->data_nascita) - strtotime('1980-01-01')) / 86400));

        $this->assertSame(2, (int) DB::selectOne("SELECT COUNT(DISTINCT email) AS n FROM `{$schema}`.`clients`")->n, 'Le e-mail finte restano uniche.');
        $this->assertSame(2, (int) DB::selectOne("SELECT COUNT(*) AS n FROM `{$schema}`.`pratiche` p JOIN `{$schema}`.`clients` c ON c.codice_fiscale = p.client_cf")->n, 'Lo stesso valore reale dà lo stesso valore finto anche nelle altre tabelle.');
        $this->assertSame(0, (int) DB::selectOne("SELECT COUNT(*) AS n FROM `{$schema}`.`activity_log`")->n);
        $this->assertSame('Carta d\'identità', DB::selectOne("SELECT name FROM `{$schema}`.`document_types`")->name, 'Le tabelle di definizione restano come sono.');
        $this->assertSame(0, (int) DB::selectOne('SELECT COUNT(*) AS n FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ?', [$schema])->n, 'I trigger della copia vengono eliminati.');

        $users = DB::select("SELECT * FROM `{$schema}`.`users` ORDER BY id");
        $this->assertSame('manutenzione@example.com', $users[0]->email);
        $this->assertSame('hash-prova', $users[0]->password);
        $this->assertStringEndsWith('@example.invalid', $users[1]->email);
        $this->assertSame('hash-prova', $users[1]->password);
        $this->assertStringStartsWith('anon_', (string) $users[0]->remember_token);

        $this->assertContains('activity_log', $report['tables_truncated']);
        $this->assertContains('document_types', $report['tables_kept']);
        $this->assertNotEmpty($report['columns']);
        $this->assertStringNotContainsString('mario', json_encode($report), 'Il rapporto non contiene valori.');

        $this->assertNoOriginalValuesIn($schema);
    }

    public function test_a_database_without_a_users_table_gets_no_maintenance_login(): void
    {
        $schema = $this->makeSource();
        DB::statement("DROP TABLE `{$schema}`.`users`");

        $this->assertFalse((new Anonymizer)->grantMaintenanceLogin($schema, 'm@example.com', 'hash'));
    }

    public function test_each_run_uses_a_new_random_salt_so_the_replacement_is_not_reproducible(): void
    {
        $first = $this->makeSource();
        $second = $this->makeSource();

        (new Anonymizer)->anonymize($first);
        (new Anonymizer)->anonymize($second);

        $this->assertNotSame(
            DB::selectOne("SELECT email FROM `{$first}`.`clients` ORDER BY id LIMIT 1")->email,
            DB::selectOne("SELECT email FROM `{$second}`.`clients` ORDER BY id LIMIT 1")->email,
        );
        $this->assertNull(DB::selectOne('SELECT @anon_salt AS s')->s, 'Il sale non resta nella sessione.');
    }

    public function test_the_scan_finds_personal_data_in_uncovered_columns_without_showing_values(): void
    {
        $schema = $this->makeSource(withUncoveredColumn: true);

        $this->assertContains("clients.riservato", (new Anonymizer)->summarize((new Anonymizer)->plan($schema))['untouched_strings']);

        (new Anonymizer)->anonymize($schema);
        $findings = (new Anonymizer)->scan($schema);

        $this->assertSame([['table' => 'clients', 'column' => 'riservato', 'pattern' => 'email', 'rows' => 1]], $findings);
        $this->assertStringNotContainsString('mario', json_encode($findings));
    }

    public function test_a_hash_with_a_long_run_of_digits_is_not_taken_for_a_phone_number_but_a_real_number_is(): void
    {
        $schema = $this->makeSource(withUncoveredColumn: true);
        DB::statement("ALTER TABLE `{$schema}`.`document_types` ADD COLUMN `hash` VARCHAR(64) NULL");
        DB::statement("UPDATE `{$schema}`.`document_types` SET `hash` = 'a3331234567890bcd3331234567ef'");
        DB::statement("UPDATE `{$schema}`.`clients` SET `riservato` = NULL");
        (new Anonymizer)->anonymize($schema);

        $this->assertSame([], (new Anonymizer)->scan($schema), 'Un hash non è un telefono.');

        DB::statement("UPDATE `{$schema}`.`clients` SET `riservato` = 'chiamare il 333 1234567 dopo le 18' WHERE id = 1");
        DB::statement("UPDATE `{$schema}`.`clients` SET `riservato` = '3331234567' WHERE id = 2");

        $this->assertSame([['table' => 'clients', 'column' => 'riservato', 'pattern' => 'telefono', 'rows' => 2]], (new Anonymizer)->scan($schema), 'Un numero isolato resta riconosciuto.');
    }

    public function test_known_false_positives_can_be_ignored(): void
    {
        $schema = $this->makeSource(withUncoveredColumn: true);
        (new Anonymizer)->anonymize($schema);

        config(['anonymize.scan.ignore_columns' => ['clients.riservato']]);

        $this->assertSame([], (new Anonymizer)->scan($schema));
    }

    public function test_a_column_can_be_covered_by_an_explicit_rule(): void
    {
        $schema = $this->makeSource(withUncoveredColumn: true);
        config(['anonymize.columns' => ['clients.riservato' => 'email']]);

        (new Anonymizer)->anonymize($schema);

        $this->assertSame([], (new Anonymizer)->scan($schema));
    }

    public function test_a_kept_definition_table_can_still_have_single_columns_replaced(): void
    {
        $schema = $this->makeSource();
        DB::statement("ALTER TABLE `{$schema}`.`document_types` ADD COLUMN `body` TEXT NULL");
        DB::statement("UPDATE `{$schema}`.`document_types` SET `body` = 'scrivere a mario.rossi@gmail.com'");
        config(['anonymize.columns' => ['document_types.body' => 'free_text']]);

        $report = (new Anonymizer)->anonymize($schema);

        $this->assertNull(DB::selectOne("SELECT body FROM `{$schema}`.`document_types`")->body);
        $this->assertSame('Carta d\'identità', DB::selectOne("SELECT name FROM `{$schema}`.`document_types`")->name, 'Il resto della tabella resta com\'è.');
        $this->assertNotContains('document_types', $report['tables_kept']);
        $this->assertSame([], (new Anonymizer)->scan($schema));
    }

    public function test_tables_matching_a_wildcard_are_emptied(): void
    {
        $schema = $this->makeSource();
        DB::statement("CREATE TABLE `{$schema}`.`_etl_id_map` (`nk` VARCHAR(100))");
        DB::statement("INSERT INTO `{$schema}`.`_etl_id_map` VALUES ('mario.rossi@gmail.com')");

        $report = (new Anonymizer)->anonymize($schema);

        $this->assertContains('_etl_id_map', $report['tables_truncated']);
        $this->assertSame(0, (int) DB::selectOne("SELECT COUNT(*) AS n FROM `{$schema}`.`_etl_id_map`")->n);
    }

    public function test_it_refuses_an_invalid_schema_name(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Anonymizer)->plan('db`; DROP DATABASE x; --');
    }

    private function assertNoOriginalValuesIn(string $schema): void
    {
        $everything = '';

        foreach (DB::select('SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?', [$schema]) as $table) {
            foreach (DB::select("SELECT * FROM `{$schema}`.`{$table->t}`") as $row) {
                $everything .= ' '.implode(' ', array_map('strval', (array) $row));
            }
        }

        foreach ($this->originalValues() as $original) {
            $this->assertStringNotContainsString($original, $everything, "Nel database anonimizzato resta «{$original}».");
        }
    }
}
