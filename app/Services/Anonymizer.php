<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Sostituisce in un database i dati personali con dati finti e controlla che non ne restino.
 *
 * Lavora solo su un database temporaneo, mai su quello di produzione. Le sostituzioni sono irreversibili: derivano da un hash con un
 * sale casuale tenuto nella sola variabile di sessione @anon_salt, che non si salva né si scrive nei messaggi. Lo stesso valore reale
 * dà lo stesso valore finto in tutte le tabelle, così relazioni e unicità restano coerenti. Il rapporto contiene solo nomi di tabelle
 * e colonne, mai valori.
 */
class Anonymizer
{
    private const STRING_TYPES = ['char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext'];

    private const LONG_TYPES = ['tinytext', 'text', 'mediumtext', 'longtext', 'json', 'tinyblob', 'blob', 'mediumblob', 'longblob', 'binary', 'varbinary'];

    private const NUMERIC_TYPES = ['tinyint', 'smallint', 'mediumint', 'int', 'bigint', 'decimal', 'float', 'double'];

    private const DATE_TYPES = ['date', 'datetime', 'timestamp'];

    private const BLANKING = ['free_text', 'file_name', 'ip'];

    /**
     * Cosa si fa in ogni tabella del database, ricavato dai soli metadati (nessun dato letto).
     *
     * @return array<string, array{action: string, columns: array<string, string>, untouched: list<string>, skipped: list<string>, all: list<string>}>
     */
    public function plan(string $schema): array
    {
        $this->assertSchema($schema);

        $plan = [];

        foreach ($this->columns($schema)->groupBy('t') as $table => $columns) {
            $entry = ['action' => 'update', 'columns' => [], 'untouched' => [], 'skipped' => [], 'all' => $columns->pluck('c')->all()];

            if ($this->matches((string) $table, (array) config('anonymize.truncate_tables'))) {
                $entry['action'] = 'truncate';
            } elseif ($this->matches((string) $table, (array) config('anonymize.keep_tables'))) {
                $entry['action'] = 'keep';

                // Una tabella di definizione resta com'è, salvo le colonne indicate una per una in config('anonymize.columns').
                foreach ($columns as $column) {
                    if (array_key_exists($table.'.'.$column->c, (array) config('anonymize.columns'))) {
                        $this->decide($entry, (string) $table, $column);
                    }
                }

                if ($entry['columns'] !== []) {
                    $entry['action'] = 'update';
                }
            } else {
                foreach ($columns as $column) {
                    $this->decide($entry, (string) $table, $column);
                }
            }

            $plan[(string) $table] = $entry;
        }

        return $plan;
    }

    /**
     * Rapporto leggibile del piano: solo nomi.
     *
     * @param  array<string, array{action: string, columns: array<string, string>, untouched: list<string>, skipped: list<string>, all: list<string>}>  $plan
     * @return array{tables_total: int, tables_truncated: list<string>, tables_kept: list<string>, columns: list<array{table: string, column: string, strategy: string}>, untouched_strings: list<string>, skipped: list<string>}
     */
    public function summarize(array $plan): array
    {
        $report = ['tables_total' => count($plan), 'tables_truncated' => [], 'tables_kept' => [], 'columns' => [], 'untouched_strings' => [], 'skipped' => []];

        foreach ($plan as $table => $entry) {
            match ($entry['action']) {
                'truncate' => $report['tables_truncated'][] = $table,
                'keep' => $report['tables_kept'][] = $table,
                default => null,
            };

            foreach ($entry['columns'] as $column => $strategy) {
                $report['columns'][] = ['table' => $table, 'column' => $column, 'strategy' => $strategy];
            }

            foreach ($entry['untouched'] as $column) {
                $report['untouched_strings'][] = "{$table}.{$column}";
            }

            foreach ($entry['skipped'] as $note) {
                $report['skipped'][] = "{$table}.{$note}";
            }
        }

        return $report;
    }

    /**
     * Sostituisce i dati nel database indicato. Da chiamare solo su un database temporaneo.
     *
     * @return array{tables_total: int, tables_truncated: list<string>, tables_kept: list<string>, columns: list<array{table: string, column: string, strategy: string}>, untouched_strings: list<string>, skipped: list<string>}
     */
    public function anonymize(string $schema, ?string $passwordHash = null): array
    {
        $plan = $this->plan($schema);
        $db = DB::connection();

        $db->statement('SET SESSION foreign_key_checks = 0');
        $db->statement('SET @anon_salt = '.$db->getPdo()->quote(bin2hex(random_bytes(32))));

        try {
            // Trigger ed eventi della copia non devono scrivere altrove né far partire nulla durante la sostituzione.
            foreach ($db->select('SELECT TRIGGER_NAME AS n FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ?', [$schema]) as $trigger) {
                $db->statement('DROP TRIGGER IF EXISTS '.$this->id($schema).'.'.$this->id($trigger->n));
            }

            foreach ($db->select('SELECT EVENT_NAME AS n FROM information_schema.EVENTS WHERE EVENT_SCHEMA = ?', [$schema]) as $event) {
                $db->statement('DROP EVENT IF EXISTS '.$this->id($schema).'.'.$this->id($event->n));
            }

            $columns = $this->columns($schema)->keyBy(fn (object $column): string => $column->t.'.'.$column->c);

            foreach ($plan as $table => $entry) {
                $qualified = $this->id($schema).'.'.$this->id($table);

                if ($entry['action'] === 'truncate') {
                    $db->statement("TRUNCATE TABLE {$qualified}");

                    continue;
                }

                if ($entry['action'] !== 'update' || $entry['columns'] === []) {
                    continue;
                }

                $assignments = [];

                foreach ($entry['columns'] as $column => $strategy) {
                    $assignments[] = $this->id($column).' = '.$this->expression($strategy, $columns[$table.'.'.$column], $passwordHash);
                }

                $db->statement("UPDATE {$qualified} SET ".implode(', ', $assignments));
            }

        } finally {
            $db->statement('SET @anon_salt = NULL');
            $db->statement('SET SESSION foreign_key_checks = 1');
        }

        return $this->summarize($plan);
    }

    /**
     * Un accesso noto per chi fa manutenzione: il primo utente prende l'indirizzo e la password indicati. Si chiama dopo il controllo
     * finale, perché l'indirizzo è vero e il controllo lo segnalerebbe. Restituisce false se la copia non ha una tabella users adatta.
     */
    public function grantMaintenanceLogin(string $schema, string $email, ?string $passwordHash): bool
    {
        $this->assertSchema($schema);

        $users = $this->plan($schema)['users'] ?? null;

        if ($users === null || array_diff(['id', 'email', 'password'], $users['all']) !== []) {
            return false;
        }

        return DB::update('UPDATE '.$this->id($schema).'.`users` SET `email` = ?, `password` = ? ORDER BY `id` LIMIT 1', [mb_strtolower($email), $passwordHash ?? '!']) > 0;
    }

    /**
     * Cerca nel database valori che somigliano a dati personali. Restituisce tabella, colonna, modello e numero di righe: mai i valori.
     *
     * @return list<array{table: string, column: string, pattern: string, rows: int}>
     */
    public function scan(string $schema): array
    {
        $this->assertSchema($schema);

        $patterns = (array) config('anonymize.scan.patterns');
        $ignored = (array) config('anonymize.scan.ignore_columns');
        $fake = (string) config('anonymize.scan.fake_values', '');
        $pdo = DB::connection()->getPdo();
        $findings = [];

        $columns = $this->columns($schema)
            ->filter(fn (object $column): bool => (in_array($column->dt, self::STRING_TYPES, true) || $column->dt === 'json') && ! in_array($column->t.'.'.$column->c, $ignored, true))
            ->groupBy('t');

        foreach ($columns as $table => $tableColumns) {
            $checks = [];

            foreach ($tableColumns as $column) {
                $value = 'CAST('.$this->id($column->c).' AS CHAR)';
                $notFake = $fake === '' ? '' : ' AND NOT REGEXP_LIKE('.$value.', '.$pdo->quote($fake).", 'i')";

                foreach ($patterns as $name => $pattern) {
                    $condition = 'REGEXP_LIKE('.$value.', '.$pdo->quote($pattern['regex']).", 'i')".$notFake;

                    if (! empty($pattern['except'])) {
                        $condition .= ' AND NOT REGEXP_LIKE('.$value.', '.$pdo->quote($pattern['except']).", 'i')";
                    }

                    $checks[] = ['column' => $column->c, 'pattern' => (string) $name, 'sql' => $condition];
                }
            }

            foreach (array_chunk($checks, 100) as $chunk) {
                $select = [];

                foreach ($chunk as $i => $check) {
                    $select[] = "COALESCE(SUM({$check['sql']}), 0) AS c{$i}";
                }

                $row = (array) DB::selectOne('SELECT '.implode(', ', $select).' FROM '.$this->id($schema).'.'.$this->id((string) $table));

                foreach ($chunk as $i => $check) {
                    if ((int) $row["c{$i}"] > 0) {
                        $findings[] = ['table' => (string) $table, 'column' => $check['column'], 'pattern' => $check['pattern'], 'rows' => (int) $row["c{$i}"]];
                    }
                }
            }
        }

        return $findings;
    }

    /**
     * @param  array{action: string, columns: array<string, string>, untouched: list<string>, skipped: list<string>, all: list<string>}  $entry
     */
    private function decide(array &$entry, string $table, object $column): void
    {
        $key = $table.'.'.$column->c;

        if (str_contains((string) $column->extra, 'GENERATED') || in_array($key, (array) config('anonymize.keep_columns'), true)) {
            return;
        }

        $strategy = ((array) config('anonymize.columns'))[$key] ?? null;

        if ($strategy === 'keep') {
            return;
        }

        if ($strategy === null) {
            foreach ((array) config('anonymize.patterns') as $name => $regex) {
                if (preg_match($regex, (string) $column->c) === 1) {
                    $strategy = (string) $name;

                    break;
                }
            }
        }

        if ($strategy === 'birth_date' && in_array($column->dt, self::STRING_TYPES, true)) {
            $strategy = 'free_text';
        }

        if ($strategy !== null && ! $this->accepts($strategy, $column->dt)) {
            $entry['skipped'][] = "{$column->c} ({$strategy} non applicabile a {$column->dt})";
            $strategy = null;
        }

        if ($strategy === null && in_array($column->dt, self::LONG_TYPES, true)) {
            $strategy = 'free_text';
        }

        if ($strategy === null) {
            if (in_array($column->dt, ['char', 'varchar'], true)) {
                $entry['untouched'][] = (string) $column->c;
            }

            return;
        }

        // Una colonna chiave non si svuota: si usa un valore finto unico.
        if (in_array($strategy, self::BLANKING, true) && in_array($column->ck, ['PRI', 'UNI'], true) && in_array($column->dt, self::STRING_TYPES, true)) {
            $strategy = 'secret';
        }

        $entry['columns'][(string) $column->c] = $strategy;
    }

    private function accepts(string $strategy, string $type): bool
    {
        return match ($strategy) {
            'birth_date' => in_array($type, self::DATE_TYPES, true),
            'amount', 'coords' => in_array($type, self::NUMERIC_TYPES, true),
            'zip' => in_array($type, [...self::STRING_TYPES, ...self::NUMERIC_TYPES], true),
            'free_text', 'file_name', 'ip' => in_array($type, [...self::STRING_TYPES, ...self::LONG_TYPES], true),
            default => in_array($type, self::STRING_TYPES, true),
        };
    }

    private function expression(string $strategy, object $column, ?string $passwordHash): string
    {
        $pdo = DB::connection()->getPdo();
        $c = $this->id($column->c);
        $hash = "MD5(CONCAT(@anon_salt, LOWER(TRIM(CAST({$c} AS CHAR)))))";
        $number = fn (int $from, int $length, int $modulo, int $pad): string => "LPAD(CONV(SUBSTRING({$hash}, {$from}, {$length}), 16, 10) % {$modulo}, {$pad}, '0')";

        $isString = true;

        $expression = match ($strategy) {
            'email' => "CONCAT('u', LEFT({$hash}, 16), '@example.invalid')",
            'phone' => "CONCAT('+39000', {$number(1, 6, 10000000, 7)})",
            'fiscal_code' => "CONCAT('ZZZZZZ', {$number(1, 4, 100, 2)}, 'A', {$number(5, 4, 100, 2)}, 'Z', {$number(9, 4, 1000, 3)}, 'Z')",
            'vat' => "CONCAT('00', {$number(1, 10, 1000000000, 9)})",
            'iban' => "CONCAT('IT00Z', {$number(1, 9, 100000000000, 11)}, {$number(10, 9, 100000000000, 11)})",
            'surname' => "CONCAT('Cognome', LEFT({$hash}, 6))",
            'name' => "CONCAT('Nome', LEFT({$hash}, 6))",
            'full_name' => "CONCAT('Soggetto ', LEFT({$hash}, 8))",
            'birth_place' => "'Comune Esempio'",
            'address' => "CONCAT('Via Esempio ', 1 + CONV(SUBSTRING({$hash}, 1, 4), 16, 10) % 99)",
            'zip' => in_array($column->dt, self::NUMERIC_TYPES, true) ? '0' : "'00000'",
            'document_number', 'secret' => "CONCAT('anon_', SUBSTRING({$hash}, 1, 4), 'x', SUBSTRING({$hash}, 5, 4), 'x', SUBSTRING({$hash}, 9, 4))",
            'password' => $pdo->quote($passwordHash ?? '!'),
            'birth_date' => "DATE_ADD({$c}, INTERVAL ((CONV(SUBSTRING({$hash}, 1, 4), 16, 10) % 3651) - 1825) DAY)",
            'amount' => "ROUND({$c} * (0.70 + (CONV(SUBSTRING({$hash}, 1, 4), 16, 10) % 61) / 100), 2)",
            'coords' => '0',
            'free_text', 'file_name', 'ip' => $this->blank($column),
            default => throw new InvalidArgumentException("Strategia di anonimizzazione sconosciuta: {$strategy}"),
        };

        if (in_array($strategy, ['birth_date', 'amount', 'coords', 'free_text', 'file_name', 'ip'], true) || ($strategy === 'zip' && in_array($column->dt, self::NUMERIC_TYPES, true))) {
            $isString = false;
        }

        // Il valore finto deve stare nella colonna.
        if ($isString && $column->maxlen !== null && (int) $column->maxlen < 255) {
            return 'LEFT('.$expression.', '.(int) $column->maxlen.')';
        }

        return $expression;
    }

    private function blank(object $column): string
    {
        if ($column->nullable === 'YES') {
            return 'NULL';
        }

        return $column->dt === 'json' ? 'JSON_OBJECT()' : "''";
    }

    /**
     * Colonne delle tabelle (non delle viste) del database: solo metadati.
     *
     * @return \Illuminate\Support\Collection<int, object{t: string, c: string, dt: string, nullable: string, maxlen: ?int, ck: string, extra: string}>
     */
    private function columns(string $schema): \Illuminate\Support\Collection
    {
        return collect(DB::select(
            "SELECT c.TABLE_NAME AS t, c.COLUMN_NAME AS c, LOWER(c.DATA_TYPE) AS dt, c.IS_NULLABLE AS nullable, c.CHARACTER_MAXIMUM_LENGTH AS maxlen, c.COLUMN_KEY AS ck, c.EXTRA AS extra
             FROM information_schema.COLUMNS c
             JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
             WHERE c.TABLE_SCHEMA = ? AND t.TABLE_TYPE = 'BASE TABLE'
             ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION",
            [$schema],
        ));
    }

    /** @param  array<int, string>  $patterns */
    private function matches(string $table, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $table, FNM_CASEFOLD)) {
                return true;
            }
        }

        return false;
    }

    private function assertSchema(string $schema): void
    {
        if (! preg_match('/^[A-Za-z0-9_]+$/', $schema)) {
            throw new InvalidArgumentException('Nome del database non valido.');
        }
    }

    private function id(string $name): string
    {
        return '`'.str_replace('`', '``', $name).'`';
    }
}
