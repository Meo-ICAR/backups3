# Canonical Database Lifecycle Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Dare a backups3 un control plane che ispeziona, costruisce, verifica, promuove, clona e ripristina i sei database canonici senza che PHPUnit o un fallback di configurazione possa toccare `unico_core` o `unico_core_test`.

**Architecture:** La guardia P0 vive nel pacchetto `meo-icar/unico-core` e blocca nome, ambiente e credenziali prima di qualsiasi SQL. Il control plane vive in backups3: classi pure per manifest, skew, stato, lock e redazione, comandi Artisan dry-run di default, catalogo e mutatore SQL che in test non aprono rete. I dump di produzione non vengono scaricati né ripristinati dai task automatici; l'esecuzione reale è solo nella sezione Manual, con dry-run e `--confirm-run-id`.

**Tech Stack:** PHP 8.4, Laravel 13.31 (`backups3`), PHPUnit 12, ext-sodium (Ed25519 già presente: `php -m` mostra `sodium`), MySQL nominato solo da generatori interni. Nessuna dipendenza Composer nuova.

**Spec:** `docs/superpowers/specs/2026-10-13-canonical-database-lifecycle-design.md` (commit `4f323685a626b544addc6af76b54236d7e76d068`).

## Global Constraints

- Sei domini: `unico_core`, `unicoloan`, `proforma`, `unicooam`, `unicobpm`, `unicoagent`. Clone: gli stessi nomi con suffisso `_test`. PHPUnit: `{dominio}_phpunit_{run}_{process}`. Build: `{dominio}_build_{run-id}` con `run-id` conforme a `[a-z0-9_]+`.
- `run_id` è un ULID minuscolo di 26 caratteri, regex `[0-9a-hjkmnp-tv-z]{26}`. `<process>` è l'indice numerico del processo PHPUnit. I nomi sono generati, mai SQL libero.
- Tre trust boundary: canonici VPS, sviluppo locale `*_test`, PHPUnit effimero. Produzione vietata in locale; `_test` e `_phpunit_` vietati sulla VPS.
- `unico-core` migra solo `unico_core`. Ogni app migra solo il proprio database. Niente FK fisiche cross-database. UnicoBPM non prende ownership delle tabelle altrui. `backups3` non decide ownership né approvazioni.
- Path v1: `s3://{bucket}/{tenant_prefix}/database-lifecycle/v1/runs/{run_id}/manifest.json`, `manifest.sig`, `dumps/{domain}.sql.gz`, `reports/{phase}.json`. Lo storico `{destination_prefix}/databases/{physical_name}/{physical_name}_{timestamp}.sql.gz` è solo lettura e non si rinomina.
- Skew accettato solo se `skew <= 15 minuti`. Discordanza nome/metadata/intestazione superiore a 60 secondi invalida il file. Finestra per formare la tupla: 24 ore. Gruppo parziale vietato: esito `FAILED_INSPECT`.
- Manifest JSON canonico UTF-8, chiavi ordinate, timestamp UTC RFC 3339, `manifest_version: 1`, firma Ed25519. Checksum SHA-256 del gzip e dello SQL decompresso.
- `--tenant` obbligatorio. Nessun tenant implicito. Dry-run di default. Mutazione solo con `--execute --confirm-run-id={run_id}` byte per byte. `status` è read-only, non accetta `--execute`, e senza `--run-id` elenca i run. `inspect --execute` e `verify --execute` non modificano i database. Niente `--force`, niente target SQL arbitrari, niente prompt interattivi. `db:lifecycle:snapshot` non si implementa.
- Lock esclusivo per `{tenant, environment}`. Cutover e rollback prendono anche il lock sui sei domini. Lease rinnovabile, fencing token monotono. Un token scaduto non scrive stato e non esegue SQL. Lo sblocco amministrativo non è automatico.
- Errori: validation nessun retry; transient al massimo 3 tentativi; data in quarantena; approval nessuna mutazione; partial cutover con write fence attivo; security arresto immediato.
- Password letterale `password` solo nel clone di sviluppo. Vietata in produzione. PHPUnit usa credenziali sintetiche diverse.
- Approvatori distinti: due, il richiedente non copre entrambi i ruoli, scadenza 4 ore, legati a tenant, run, hash del manifest e azione.
- Retention: locale VPS 7 giorni; R2 14 giornalieri, 52 settimanali, 36 mensili; binlog 35 giorni; manifest e audit 7 anni. RPO 5 minuti. RTO 60 minuti. Blue in sola lettura per 7 giorni dopo il cutover. Riabilitazione read-only di un accesso cross-app: massimo 72 ore. Parità API prima di revocare i grant: 14 giorni.
- Repository, manifest, CLI e log non contengono password, token, endpoint con credenziali, PII o private key. Configurazione incompleta: fail-closed.
- I task 1–32 si verificano con fixture sintetiche. Non scaricare, montare o ripristinare dump R2 o dump di produzione. Non impostare `LIFECYCLE_LIVE_SQL` né `LIFECYCLE_ALLOW_REMOTE_CATALOG`. Non eseguire i blocchi Manual.
- Non modificare né mettere in stage i file già sporchi. In backups3, al 13 ottobre 2026: `app/Console/Commands/CheckBackupConnections.php`, `app/Console/Commands/ShowLastBackups.php`, `config/backup_paths.php`, `tests/Feature/Console/Commands/BackupDatabasesConfigTest.php`. In unico-core la working tree è sporca su config, modelli, service provider, alcune migration e alcuni test: non fare `git add -A` e non editare quei file. In unicooam sono sporchi `app/Providers/AppServiceProvider.php` e `phpunit.xml`. Se un task trova il proprio file già modificato, si ferma.
- Pint solo sui file del task: `vendor/bin/pint --format agent <file>`. Non usare `pint --dirty`: riformatterebbe le modifiche preesistenti.
- Non cambiare dipendenze Composer. Non fare push. Non eseguire `git config`. Se Git rifiuta `unicoagent` come dubious ownership, fermarsi e chiedere alla persona; non aggiungere `safe.directory`.
- Prima di scrivere test, leggere la skill `testing-best-practices` del repo in cui si scrive il test.
- Rollback di ogni task: `git revert` del commit di quel task, nel repo indicato. Non lanciare lifecycle, migrate o PHPUnit sull'albero revertito finché la guardia del task 1 non è di nuovo presente.

## Review Focus

Questi cinque input sono i più facili da sbagliare. Il test che li inchioda sta nel task indicato.

1. Un database chiamato `unico_core`, `unico_core_test`, `unico_core_test2` o `unico_core_test; DROP DATABASE unico_core` deve far lanciare `ProtectedDatabaseException` prima di PDO e prima di `DROP` — Task 1.
2. Una tupla con cinque domini su sei, oppure sei domini con skew 901 secondi, non produce un gruppo e non scrive un manifest promuovibile — Task 12.
3. `--confirm-run-id` con maiuscole, spazio o un carattere in più rispetto al `run_id` resta dry-run e non scrive journal né R2 — Task 15.
4. Un fencing token scaduto non aggiunge righe al journal e non emette SQL, anche se il processo è ancora in vita — Task 18.
5. Un cutover che ha spostato solo tre domini su sei resta nel write fence, non diventa `ACTIVE` e non rilascia le scritture — Task 29.

## File map

| File | Responsabilità |
|---|---|
| `/var/www/html/unico-core/src/Database/PhpUnitDatabaseGuard.php` | Rifiuta i nomi condivisi e produce il nome effimero del processo |
| `/var/www/html/unico-core/src/Database/ProtectedDatabaseException.php` | Errore di guardia, prima del SQL |
| `/var/www/html/unico-core/src/Database/CoreConnectionPolicy.php` | Fail-closed di host, porta, database, utente, password, ambiente, driver, server UUID |
| `/var/www/html/unico-core/tests/Support/CoreDatabase.php` | Nessun default `unico_core_test` / `unico`; guardia prima del capsule e del `DROP` |
| `/var/www/html/unico-core/tests/bootstrap.php` | Sostituisce un nome vietato con un nome effimero, senza aprire MySQL |
| `/var/www/html/unico-core/src/Console/MigrateCoreCommand.php` | Chiama la policy prima di `migrate` |
| `config/database.php` delle cinque app | Connessione `core` senza fallback su `DB_*` |
| `app/Providers/CoreConnectionGuardProvider.php` delle cinque app | Applica la policy al boot, prima delle migration |
| `/var/www/html/backups3/config/lifecycle.php` | Tenant, mapping, secret reference, flag live spenti |
| `/var/www/html/backups3/app/Lifecycle/*` | Dominio puro: nomi, manifest, firma, stato, lock, sanitize, SQL |
| `/var/www/html/backups3/app/Console/Commands/Lifecycle*Command.php` | I sette comandi pubblici |
| `/var/www/html/backups3/docker/lifecycle-phpunit/compose.yml` | MySQL di test senza porte pubblicate e senza rete esterna; i test non lo avviano |

`whistle` resta nel backup notturno esistente e non entra nel lifecycle. Non editare `config/backup_paths.php`.

---

### Task 1: Guardia PHPUnit di unico-core

**Files:**
- Create: `/var/www/html/unico-core/src/Database/ProtectedDatabaseException.php`
- Create: `/var/www/html/unico-core/src/Database/PhpUnitDatabaseGuard.php`
- Create: `/var/www/html/unico-core/tests/PhpUnitDatabaseGuardTest.php`
- Modify: `/var/www/html/unico-core/tests/Support/CoreDatabase.php`
- Modify: `/var/www/html/unico-core/tests/bootstrap.php`
- Test: `/var/www/html/unico-core/tests/PhpUnitDatabaseGuardTest.php`

**Interfaces:**
- Consumes: niente
- Produces: `Unico\Core\Database\ProtectedDatabaseException`; `PhpUnitDatabaseGuard::EPHEMERAL_PATTERN` string; `assertEphemeral(string $database): void`; `randomRunId(): string`; `isolateEnvironment(array $env, int $pid): array` restituisce le chiavi `CORE_TEST_DB_*`; `CoreDatabase::connectionSettings(): array` con chiavi `driver`, `host`, `port`, `database`, `username`, `password`, `charset`, `collation`, `prefix`, `strict`, `engine`. Lancia prima di aprire una connessione.

Questo test non chiama `boot()` né `migrateFresh()`. Quei metodi, oggi, fanno `DROP TABLE` su `getenv('CORE_TEST_DB_DATABASE') ?: 'unico_core_test'`.

- [ ] **Step 1: Write the failing test**

Creare `tests/PhpUnitDatabaseGuardTest.php`:

```php
<?php

namespace Unico\Core\Tests;

use PHPUnit\Framework\TestCase;
use Unico\Core\Database\PhpUnitDatabaseGuard;
use Unico\Core\Database\ProtectedDatabaseException;
use Unico\Core\Tests\Support\CoreDatabase;

class PhpUnitDatabaseGuardTest extends TestCase
{
    public function test_rejects_canonical_clone_and_injected_names(): void
    {
        foreach ([
            'unico_core',
            'unico_core_test',
            'unico_core_test2',
            'unico_core_test; DROP DATABASE unico_core',
            '',
            'unico_core_phpunit_01arz3ndektsv4rrffq69g5fav',
            'UNICO_CORE_PHPUNIT_01arz3ndektsv4rrffq69g5fav_1',
        ] as $database) {
            try {
                PhpUnitDatabaseGuard::assertEphemeral($database);
                $this->fail("Accettato un nome vietato: {$database}");
            } catch (ProtectedDatabaseException $e) {
                $this->assertStringContainsString('unico_core', $e->getMessage());
            }
        }
    }

    public function test_allows_one_ephemeral_core_database(): void
    {
        PhpUnitDatabaseGuard::assertEphemeral('unico_core_phpunit_01arz3ndektsv4rrffq69g5fav_7');
        $this->assertSame(26, strlen(PhpUnitDatabaseGuard::randomRunId()));
    }

    public function test_isolate_environment_never_returns_a_shared_database(): void
    {
        $isolated = PhpUnitDatabaseGuard::isolateEnvironment([
            'CORE_TEST_DB_DATABASE' => 'unico_core_test',
            'CORE_TEST_DB_HOST' => '127.0.0.1',
            'CORE_TEST_DB_PORT' => '3306',
            'CORE_TEST_DB_USERNAME' => 'phpunit',
            'CORE_TEST_DB_PASSWORD' => 'synthetic',
        ], 4242);

        $this->assertSame('unico_core_phpunit_'.$isolated['CORE_TEST_DB_RUN'].'_4242', $isolated['CORE_TEST_DB_DATABASE']);
        $this->assertDoesNotMatchRegularExpression('/unico_core_test$/', $isolated['CORE_TEST_DB_DATABASE']);
        PhpUnitDatabaseGuard::assertEphemeral($isolated['CORE_TEST_DB_DATABASE']);
    }

    public function test_connection_settings_reject_unico_core_test_without_defaults(): void
    {
        putenv('CORE_TEST_DB_HOST=127.0.0.1');
        putenv('CORE_TEST_DB_PORT=3306');
        putenv('CORE_TEST_DB_DATABASE=unico_core_test');
        putenv('CORE_TEST_DB_USERNAME=unico');
        putenv('CORE_TEST_DB_PASSWORD=unico');

        $this->expectException(ProtectedDatabaseException::class);
        CoreDatabase::connectionSettings();
    }

    public function test_missing_password_does_not_fall_back_to_unico(): void
    {
        putenv('CORE_TEST_DB_HOST=127.0.0.1');
        putenv('CORE_TEST_DB_PORT=3306');
        putenv('CORE_TEST_DB_DATABASE=unico_core_phpunit_01arz3ndektsv4rrffq69g5fav_7');
        putenv('CORE_TEST_DB_USERNAME=phpunit');
        putenv('CORE_TEST_DB_PASSWORD');

        $this->expectException(ProtectedDatabaseException::class);
        CoreDatabase::connectionSettings();
    }

    public function test_migrate_fresh_and_boot_call_the_guard_before_sql(): void
    {
        $source = file_get_contents(__DIR__.'/Support/CoreDatabase.php');
        $this->assertStringNotContainsString("?: 'unico_core_test'", $source);
        $this->assertStringNotContainsString("?: 'unico'", $source);
        $boot = $this->methodBody($source, 'function boot');
        $fresh = $this->methodBody($source, 'function migrateFresh');
        $this->assertLessThan(strpos($boot, 'addConnection'), strpos($boot, 'connectionSettings'));
        $this->assertLessThan(strpos($fresh, 'DROP TABLE'), strpos($fresh, 'connectionSettings'));
    }

    private function methodBody(string $source, string $signature): string
    {
        $start = strpos($source, $signature);
        $this->assertNotFalse($start);

        return substr($source, $start, 1200);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /var/www/html/unico-core && vendor/bin/phpunit tests/PhpUnitDatabaseGuardTest.php --filter 'test_rejects_canonical_clone_and_injected_names'`

Expected: FAIL con classe `PhpUnitDatabaseGuard` non trovata. Questo filtro non chiama `CoreDatabase`. Non lanciare la suite intera.

- [ ] **Step 3: Write minimal implementation**

`src/Database/ProtectedDatabaseException.php`:

```php
<?php

namespace Unico\Core\Database;

use RuntimeException;

class ProtectedDatabaseException extends RuntimeException
{
}
```

`src/Database/PhpUnitDatabaseGuard.php`:

```php
<?php

namespace Unico\Core\Database;

final class PhpUnitDatabaseGuard
{
    public const EPHEMERAL_PATTERN = '/^unico_core_phpunit_[0-9a-hjkmnp-tv-z]{26}_[0-9]+$/';

    public static function assertEphemeral(string $database): void
    {
        if (preg_match(self::EPHEMERAL_PATTERN, $database) !== 1) {
            throw new ProtectedDatabaseException("Database PHPUnit vietato per unico_core: {$database}");
        }
    }

    public static function randomRunId(): string
    {
        $alphabet = '0123456789abcdefghjkmnpqrstvwxyz';
        $id = '';
        $bytes = random_bytes(26);
        for ($i = 0; $i < 26; $i++) {
            $id .= $alphabet[ord($bytes[$i]) % strlen($alphabet)];
        }

        return $id;
    }

    /**
     * @param  array<string, string|false>  $env
     * @return array{CORE_TEST_DB_DATABASE: string, CORE_TEST_DB_RUN: string, CORE_TEST_DB_HOST: string, CORE_TEST_DB_PORT: string, CORE_TEST_DB_USERNAME: string, CORE_TEST_DB_PASSWORD: string}
     */
    public static function isolateEnvironment(array $env, int $pid): array
    {
        $current = (string) ($env['CORE_TEST_DB_DATABASE'] ?? '');
        $run = self::randomRunId();
        if (preg_match(self::EPHEMERAL_PATTERN, $current) === 1 && str_ends_with($current, '_'.$pid)) {
            $run = substr($current, strlen('unico_core_phpunit_'), 26);
        }

        return [
            'CORE_TEST_DB_DATABASE' => 'unico_core_phpunit_'.$run.'_'.$pid,
            'CORE_TEST_DB_RUN' => $run,
            'CORE_TEST_DB_HOST' => (string) ($env['CORE_TEST_DB_HOST'] ?? ''),
            'CORE_TEST_DB_PORT' => (string) ($env['CORE_TEST_DB_PORT'] ?? ''),
            'CORE_TEST_DB_USERNAME' => (string) ($env['CORE_TEST_DB_USERNAME'] ?? ''),
            'CORE_TEST_DB_PASSWORD' => (string) ($env['CORE_TEST_DB_PASSWORD'] ?? ''),
        ];
    }
}
```

In `tests/Support/CoreDatabase.php` aggiungere il `use` e questi due punti, senza cambiare il resto del boot già presente. `connectionSettings()` è nuovo. `boot()` lo chiama prima di `addConnection`. `migrateFresh()` lo chiama prima di `boot()` e quindi prima del `DROP`.

```php
use Unico\Core\Database\PhpUnitDatabaseGuard;

public static function connectionSettings(): array
{
    $required = [];
    foreach (['HOST', 'PORT', 'DATABASE', 'USERNAME', 'PASSWORD'] as $suffix) {
        $value = getenv('CORE_TEST_DB_'.$suffix);
        if ($value === false || $value === '') {
            throw new \Unico\Core\Database\ProtectedDatabaseException("CORE_TEST_DB_{$suffix} è obbligatorio");
        }
        $required[$suffix] = $value;
    }
    PhpUnitDatabaseGuard::assertEphemeral($required['DATABASE']);

    return [
        'driver' => 'mysql',
        'host' => $required['HOST'],
        'port' => $required['PORT'],
        'database' => $required['DATABASE'],
        'username' => $required['USERNAME'],
        'password' => $required['PASSWORD'],
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
        'strict' => true,
        'engine' => 'InnoDB',
    ];
}
```

Sostituire l'array passato a `addConnection([...], 'core')` con `self::connectionSettings()`. Prima riga di `migrateFresh()`, prima di `self::boot()`:

```php
self::connectionSettings();
```

Eliminare i fallback `?: '127.0.0.1'`, `?: 3306`, `?: 'unico_core_test'`, `?: 'unico'`.

In `tests/bootstrap.php`, dopo il load del `.env` e senza aprire PDO:

```php
use Unico\Core\Database\PhpUnitDatabaseGuard;

$isolated = PhpUnitDatabaseGuard::isolateEnvironment(array_merge($_ENV, [
    'CORE_TEST_DB_DATABASE' => getenv('CORE_TEST_DB_DATABASE') ?: '',
    'CORE_TEST_DB_HOST' => getenv('CORE_TEST_DB_HOST') ?: '',
    'CORE_TEST_DB_PORT' => getenv('CORE_TEST_DB_PORT') ?: '',
    'CORE_TEST_DB_USERNAME' => getenv('CORE_TEST_DB_USERNAME') ?: '',
    'CORE_TEST_DB_PASSWORD' => getenv('CORE_TEST_DB_PASSWORD') ?: '',
]), getmypid());
foreach ($isolated as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /var/www/html/unico-core && vendor/bin/phpunit tests/PhpUnitDatabaseGuardTest.php`

Expected: PASS. Non lanciare `vendor/bin/phpunit` senza path: gli altri test chiamano `migrateFresh()` e, se il nome effimero non esiste, devono fallire sulla connessione, non su `unico_core_test`. Se un test raggiunge `DROP TABLE` su `unico_core` o `unico_core_test`, fermarsi.

- [ ] **Step 5: Commit**

Nel repo `unico-core`, solo questi file:

```bash
git -C /var/www/html/unico-core add \
  src/Database/ProtectedDatabaseException.php \
  src/Database/PhpUnitDatabaseGuard.php \
  tests/PhpUnitDatabaseGuardTest.php \
  tests/Support/CoreDatabase.php \
  tests/bootstrap.php
git -C /var/www/html/unico-core commit -m "$(cat <<'EOF'
fix: block PHPUnit from resetting shared core databases

EOF
)"
```

**Rollback:** `git -C /var/www/html/unico-core revert HEAD`. Non eseguire PHPUnit finché la guardia non viene ripristinata.

### Task 2: Policy fail-closed della connessione core

**Files:**
- Create: `/var/www/html/unico-core/src/Database/CoreConnectionPolicy.php`
- Modify: `/var/www/html/unico-core/src/Console/MigrateCoreCommand.php` (file pulito; se risulta sporco, fermarsi)
- Test: `/var/www/html/unico-core/tests/CoreConnectionPolicyTest.php`

**Interfaces:**
- Consumes: `ProtectedDatabaseException`
- Produces: `CoreConnectionPolicy::assert(array $connection, string $environment): void`; `CoreConnectionPolicy::assertServerUuid(?string $actual, ?string $expected): void`. Ambiente `production` solo database `unico_core`; `local` solo `unico_core_test`; `testing` solo il pattern del task 1. Driver `sqlite` sempre rifiutato. Chiavi obbligatorie: `host`, `port`, `database`, `username`, `password`. UUID diverso: eccezione prima di qualsiasi query.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Unico\Core\Tests;

use PHPUnit\Framework\TestCase;
use Unico\Core\Database\CoreConnectionPolicy;
use Unico\Core\Database\ProtectedDatabaseException;

class CoreConnectionPolicyTest extends TestCase
{
    public function test_missing_core_keys_do_not_fall_back(): void
    {
        $this->expectException(ProtectedDatabaseException::class);
        CoreConnectionPolicy::assert([
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'unico_core',
            'username' => 'core',
            'password' => '',
        ], 'production');
    }

    public function test_environment_accepts_only_its_database(): void
    {
        $base = ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '3306', 'username' => 'core', 'password' => 'secret'];
        CoreConnectionPolicy::assert($base + ['database' => 'unico_core'], 'production');
        CoreConnectionPolicy::assert($base + ['database' => 'unico_core_test'], 'local');
        CoreConnectionPolicy::assert($base + ['database' => 'unico_core_phpunit_01arz3ndektsv4rrffq69g5fav_7'], 'testing');

        foreach ([['unico_core_test', 'production'], ['unico_core', 'local'], ['unico_core_test', 'testing'], ['unico_core', 'staging']] as [$database, $environment]) {
            try {
                CoreConnectionPolicy::assert($base + ['database' => $database], $environment);
                $this->fail("{$environment} ha accettato {$database}");
            } catch (ProtectedDatabaseException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_sqlite_and_uuid_mismatch_fail_before_a_query(): void
    {
        $this->expectException(ProtectedDatabaseException::class);
        CoreConnectionPolicy::assert([
            'driver' => 'sqlite',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'unico_core',
            'username' => 'core',
            'password' => 'secret',
        ], 'production');
    }

    public function test_server_uuid_must_match_before_sql(): void
    {
        CoreConnectionPolicy::assertServerUuid('11111111-1111-1111-1111-111111111111', '11111111-1111-1111-1111-111111111111');
        $this->expectException(ProtectedDatabaseException::class);
        CoreConnectionPolicy::assertServerUuid('22222222-2222-2222-2222-222222222222', '11111111-1111-1111-1111-111111111111');
    }

    public function test_migrate_command_checks_the_policy_before_migrate(): void
    {
        $source = file_get_contents(__DIR__.'/../src/Console/MigrateCoreCommand.php');
        $this->assertLessThan(strpos($source, "\$this->call("), strpos($source, 'CoreConnectionPolicy::assert'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /var/www/html/unico-core && vendor/bin/phpunit tests/CoreConnectionPolicyTest.php --filter test_environment_accepts_only_its_database`

Expected: FAIL, classe assente.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

namespace Unico\Core\Database;

final class CoreConnectionPolicy
{
    public static function assert(array $connection, string $environment): void
    {
        if (($connection['driver'] ?? '') === 'sqlite') {
            throw new ProtectedDatabaseException('La connessione core non può usare SQLite');
        }
        foreach (['host', 'port', 'database', 'username', 'password'] as $key) {
            if (! isset($connection[$key]) || $connection[$key] === '' || $connection[$key] === null) {
                throw new ProtectedDatabaseException("CORE_DB {$key} è obbligatorio");
            }
        }
        $database = (string) $connection['database'];
        $allowed = match ($environment) {
            'production' => $database === 'unico_core',
            'local' => $database === 'unico_core_test',
            'testing' => preg_match(PhpUnitDatabaseGuard::EPHEMERAL_PATTERN, $database) === 1,
            default => false,
        };
        if (! $allowed) {
            throw new ProtectedDatabaseException("Database core {$database} vietato in {$environment}");
        }
    }

    public static function assertServerUuid(?string $actual, ?string $expected): void
    {
        if ($expected === null || $expected === '' || $actual !== $expected) {
            throw new ProtectedDatabaseException('Server UUID core non coincidente');
        }
    }
}
```

In `MigrateCoreCommand::handle()`, prima di `useCoreMigrationsTable()` e prima di `$this->call`:

```php
$connectionName = (string) config('unico-core.connection');
\Unico\Core\Database\CoreConnectionPolicy::assert(
    (array) config("database.connections.{$connectionName}"),
    (string) app()->environment(),
);
$expectedUuid = config('unico-core.expected_server_uuid');
if (is_string($expectedUuid) && $expectedUuid !== '') {
    \Unico\Core\Database\CoreConnectionPolicy::assertServerUuid(
        (string) config('unico-core.observed_server_uuid'),
        $expectedUuid,
    );
}
```

`observed_server_uuid` è un valore già noto al processo. Questo comando non esegue `SELECT @@server_uuid` prima della policy. Il probe che legge l'UUID arriva nel task 14 e solo dopo il match del nome.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /var/www/html/unico-core && vendor/bin/phpunit tests/CoreConnectionPolicyTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git -C /var/www/html/unico-core add src/Database/CoreConnectionPolicy.php src/Console/MigrateCoreCommand.php tests/CoreConnectionPolicyTest.php
git -C /var/www/html/unico-core commit -m "$(cat <<'EOF'
fix: fail closed when the core connection is incomplete

EOF
)"
```

**Rollback:** revert di questo commit. Il task 1 resta.

### Task 3: Wiring unicoloan

**Files:**
- Modify: `/var/www/html/unicoloan/config/database.php` (blocco `'core'`, circa linee 71–85)
- Create: `/var/www/html/unicoloan/app/Providers/CoreConnectionGuardProvider.php`
- Modify: `/var/www/html/unicoloan/bootstrap/providers.php`
- Create: `/var/www/html/unicoloan/tests/BootstrapEphemeral.php`
- Modify: `/var/www/html/unicoloan/phpunit.xml` (aggiungere il bootstrap; togliere il fallback implicito della connessione core)
- Test: `/var/www/html/unicoloan/tests/Unit/CoreConnectionGuardTest.php`

**Interfaces:**
- Consumes: `CoreConnectionPolicy::assert(array $connection, string $environment): void`
- Produces: provider registrato che al boot valida `config('database.connections.core')`. Il bootstrap di PHPUnit imposta `CORE_DB_DATABASE=unico_core_phpunit_{ulid}_{pid}` e `DB_DATABASE=unicoloan_phpunit_{ulid}_{pid}` senza aprire una connessione.

Se `config/database.php` o `phpunit.xml` sono già modificati, fermarsi.

- [ ] **Step 1: Write the failing test**

`tests/Unit/CoreConnectionGuardTest.php` estende `Tests\TestCase` e non usa `RefreshDatabase`:

```php
<?php

namespace Tests\Unit;

use Tests\TestCase;
use Unico\Core\Database\PhpUnitDatabaseGuard;

class CoreConnectionGuardTest extends TestCase
{
    public function test_core_connection_has_no_application_fallback(): void
    {
        $core = config('database.connections.core');
        $this->assertSame('mysql', $core['driver']);
        $this->assertNotSame(config('database.connections.mysql.database'), $core['database']);
        PhpUnitDatabaseGuard::assertEphemeral($core['database']);
        $this->assertMatchesRegularExpression(
            '/^unicoloan_phpunit_[0-9a-hjkmnp-tv-z]{26}_[0-9]+$/',
            (string) config('database.connections.mysql.database'),
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /var/www/html/unicoloan && php artisan test --compact tests/Unit/CoreConnectionGuardTest.php`

Expected: FAIL. Il boot può già lanciare `ProtectedDatabaseException` perché la connessione attuale ricade su `DB_*`. Non lanciare la suite intera.

- [ ] **Step 3: Write minimal implementation**

Sostituire il blocco `'core'` con valori senza secondo argomento di fallback:

```php
'core' => [
    'driver' => 'mysql',
    'host' => env('CORE_DB_HOST'),
    'port' => env('CORE_DB_PORT'),
    'database' => env('CORE_DB_DATABASE'),
    'username' => env('CORE_DB_USERNAME'),
    'password' => env('CORE_DB_PASSWORD'),
    'unix_socket' => env('CORE_DB_SOCKET', ''),
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
    'prefix_indexes' => true,
    'strict' => true,
    'engine' => 'InnoDB',
],
```

`app/Providers/CoreConnectionGuardProvider.php`:

```php
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Unico\Core\Database\CoreConnectionPolicy;

class CoreConnectionGuardProvider extends ServiceProvider
{
    public function boot(): void
    {
        CoreConnectionPolicy::assert((array) config('database.connections.core'), (string) $this->app->environment());
    }
}
```

Aggiungerlo in `bootstrap/providers.php` accanto ad `AppServiceProvider`.

`tests/BootstrapEphemeral.php` genera due ULID con `PhpUnitDatabaseGuard::randomRunId()`, pid `getmypid()`, e fa `putenv` / `$_ENV` / `$_SERVER` per `CORE_DB_HOST`, `CORE_DB_PORT`, `CORE_DB_DATABASE`, `CORE_DB_USERNAME`, `CORE_DB_PASSWORD`, `DB_DATABASE`. Host `127.0.0.1`, porta `3306`, utente `phpunit`, password `synthetic-phpunit`. Non crea database e non istanzia PDO. Rifiuta di proseguire se `CORE_DB_DATABASE` è `unico_core` o `unico_core_test`.

In `phpunit.xml`, dentro `<phpunit>`, impostare `bootstrap="tests/BootstrapEphemeral.php"`. Non lasciare `DB_DATABASE=unicoloan_test` come destinazione del core: il bootstrap sovrascrive `DB_DATABASE` e `CORE_DB_DATABASE` dopo il load, con `force` non necessario se il bootstrap è l'ultimo a scrivere `putenv`. Aggiungere anche `<env name="CORE_DB_HOST" value="127.0.0.1"/>` e le altre `CORE_DB_*` vuote solo se il bootstrap le sostituisce sempre: il bootstrap deve sostituirle sempre in `testing`.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /var/www/html/unicoloan && php artisan test --compact tests/Unit/CoreConnectionGuardTest.php`

Expected: PASS, senza query MySQL. Poi `vendor/bin/pint --format agent config/database.php app/Providers/CoreConnectionGuardProvider.php tests/Unit/CoreConnectionGuardTest.php tests/BootstrapEphemeral.php`.

Non lanciare `php artisan test` senza path: `RefreshDatabase` cercherebbe l'effimero inesistente. Questo è il comportamento voluto finché l'operatore non crea quel database nel Manual M0.

- [ ] **Step 5: Commit**

```bash
git -C /var/www/html/unicoloan add config/database.php app/Providers/CoreConnectionGuardProvider.php bootstrap/providers.php tests/BootstrapEphemeral.php tests/Unit/CoreConnectionGuardTest.php phpunit.xml
git -C /var/www/html/unicoloan commit -m "$(cat <<'EOF'
fix: stop the loan app from inheriting the core database

EOF
)"
```

**Rollback:** revert. Ripristina il fallback e `unicoloan_test` in PHPUnit; non lanciare migrate nel mezzo.

### Task 4: Wiring proforma

**Files:**
- Modify: `/var/www/html/proforma/config/database.php` blocco `'core'` linee 71–85
- Create: `/var/www/html/proforma/app/Providers/CoreConnectionGuardProvider.php`
- Modify: `/var/www/html/proforma/bootstrap/providers.php`
- Create: `/var/www/html/proforma/tests/BootstrapEphemeral.php`
- Modify: `/var/www/html/proforma/phpunit.xml`
- Test: `/var/www/html/proforma/tests/Unit/CoreConnectionGuardTest.php`

**Interfaces:**
- Consumes: `CoreConnectionPolicy::assert`
- Produces: in testing, `CORE_DB_DATABASE=unico_core_phpunit_{ulid}_{pid}` e `DB_DATABASE=proforma_phpunit_{ulid}_{pid}`

Stesso codice del task 3, con questi valori diversi: regex del database applicativo `^proforma_phpunit_[0-9a-hjkmnp-tv-z]{26}_[0-9]+$`. Il provider è identico. `phpunit.xml` oggi ha `DB_DATABASE=proforma_test`, `UNICO_CORE_DB_CONNECTION=mysql`, `UNICO_CORE_MIGRATE=true`: il bootstrap deve comunque puntare il core all'effimero `unico_core_phpunit_*`, non a `proforma_test`.

- [ ] **Step 1: Write the failing test**

Copiare il test del task 3 sostituendo `unicoloan_phpunit_` con `proforma_phpunit_`.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /var/www/html/proforma && php artisan test --compact tests/Unit/CoreConnectionGuardTest.php`

Expected: FAIL.

- [ ] **Step 3: Write minimal implementation**

Stesso blocco `core`, stesso provider, stesso bootstrap del task 3 con prefisso `proforma_phpunit_`. Registrare il provider. Bootstrap di `phpunit.xml`: `tests/BootstrapEphemeral.php`.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /var/www/html/proforma && php artisan test --compact tests/Unit/CoreConnectionGuardTest.php && vendor/bin/pint --format agent config/database.php app/Providers/CoreConnectionGuardProvider.php tests/Unit/CoreConnectionGuardTest.php tests/BootstrapEphemeral.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git -C /var/www/html/proforma add config/database.php app/Providers/CoreConnectionGuardProvider.php bootstrap/providers.php tests/BootstrapEphemeral.php tests/Unit/CoreConnectionGuardTest.php phpunit.xml
git -C /var/www/html/proforma commit -m "$(cat <<'EOF'
fix: stop proforma from inheriting the core database

EOF
)"
```

**Rollback:** revert di questo commit.

### Task 5: Wiring unicooam

**Files:**
- Modify: `/var/www/html/unicooam/config/database.php` blocco `'core'` linee 71–85, solo se pulito
- Create: `/var/www/html/unicooam/app/Providers/CoreConnectionGuardProvider.php`
- Modify: `/var/www/html/unicooam/bootstrap/providers.php` solo se `phpunit.xml` non è sporco
- Create: `/var/www/html/unicooam/tests/BootstrapEphemeral.php`
- Create: `/var/www/html/unicooam/phpunit.core-guard.xml` (file nuovo; non toccare `phpunit.xml` se sporco)
- Test: `/var/www/html/unicooam/tests/Unit/CoreConnectionGuardTest.php`

**Interfaces:**
- Consumes: `CoreConnectionPolicy::assert`
- Produces: database applicativo `unicooam_phpunit_{ulid}_{pid}` e core effimero

`phpunit.xml` e `AppServiceProvider.php` sono sporchi. Non editarli e non fare `git add` su di loro.

- [ ] **Step 1: Write the failing test**

Test identico al task 3 con regex `^unicooam_phpunit_[0-9a-hjkmnp-tv-z]{26}_[0-9]+$`.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /var/www/html/unicooam && git status --short -- phpunit.xml app/Providers/AppServiceProvider.php`

Se quei file sono modificati, il passo di verifica usa una config dedicata, non la suite di default. Creare prima il test, poi:

Run: `cd /var/www/html/unicooam && vendor/bin/phpunit -c phpunit.core-guard.xml tests/Unit/CoreConnectionGuardTest.php`

Expected: FAIL finché il bootstrap e il provider non esistono. Non lanciare `php artisan test` senza `-c`: userebbe il `phpunit.xml` sporco.

- [ ] **Step 3: Write minimal implementation**

Riscrivere solo il blocco `'core'` come nel task 3. Provider identico. Bootstrap identico con prefisso `unicooam_phpunit_`.

`phpunit.core-guard.xml` è una copia dei nodi di suite e di `<php>` del `phpunit.xml` committato (`git show HEAD:phpunit.xml`), con `bootstrap="tests/BootstrapEphemeral.php"` e senza usare il working tree sporco. Non registrare il provider in `bootstrap/providers.php` finché `phpunit.xml` è sporco: la registrazione farebbe fallire la suite esistente su `unicooam_test`. Il file `phpunit.core-guard.xml` registra il controllo nel bootstrap stesso:

```php
CoreConnectionPolicy::assert([
    'driver' => 'mysql',
    'host' => getenv('CORE_DB_HOST'),
    'port' => getenv('CORE_DB_PORT'),
    'database' => getenv('CORE_DB_DATABASE'),
    'username' => getenv('CORE_DB_USERNAME'),
    'password' => getenv('CORE_DB_PASSWORD'),
], 'testing');
```

Quando `phpunit.xml` torna pulito, aggiungere il provider a `bootstrap/providers.php` e `bootstrap="tests/BootstrapEphemeral.php"` in `phpunit.xml` nello stesso commit. Fino ad allora il commit di questo task non include `phpunit.xml` né `AppServiceProvider.php`.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /var/www/html/unicooam && vendor/bin/phpunit -c phpunit.core-guard.xml tests/Unit/CoreConnectionGuardTest.php`

Expected: PASS. Pint solo sui file nuovi e su `config/database.php`.

- [ ] **Step 5: Commit**

```bash
git -C /var/www/html/unicooam add config/database.php app/Providers/CoreConnectionGuardProvider.php tests/BootstrapEphemeral.php tests/Unit/CoreConnectionGuardTest.php phpunit.core-guard.xml
git -C /var/www/html/unicooam commit -m "$(cat <<'EOF'
fix: guard the OAM core connection without touching local edits

EOF
)"
```

**Rollback:** revert. Non ripristinare a mano il `phpunit.xml` sporco.

### Task 6: Wiring unicobpm

**Files:**
- Modify: `/var/www/html/unicobpm/config/database.php` blocco `'core'` linee 70–84
- Create: `/var/www/html/unicobpm/app/Providers/CoreConnectionGuardProvider.php`
- Modify: `/var/www/html/unicobpm/bootstrap/providers.php`
- Create: `/var/www/html/unicobpm/tests/BootstrapEphemeral.php`
- Modify: `/var/www/html/unicobpm/phpunit.xml`
- Test: `/var/www/html/unicobpm/tests/Unit/CoreConnectionGuardTest.php`

**Interfaces:**
- Consumes: `CoreConnectionPolicy::assert`
- Produces: `DB_DATABASE=unicobpm_phpunit_{ulid}_{pid}`. Oggi `phpunit.xml` imposta `CORE_DB_DATABASE=unicobpm_test` e `DB_UNICOOAM=unicobpm_test`: il bootstrap deve sostituire `CORE_DB_DATABASE` con l'effimero core, non con `unicobpm_test`.

- [ ] **Step 1: Write the failing test**

Test del task 3 con regex `^unicobpm_phpunit_[0-9a-hjkmnp-tv-z]{26}_[0-9]+$` e asserzione che `config('database.connections.core.database')` non è `unicobpm_test`.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /var/www/html/unicobpm && php artisan test --compact tests/Unit/CoreConnectionGuardTest.php`

Expected: FAIL.

- [ ] **Step 3: Write minimal implementation**

Stesso wiring del task 3. Nel bootstrap, dopo aver scritto l'effimero core, se `getenv('CORE_DB_DATABASE')` è `unicobpm_test` o `unico_core_test` lanciare `ProtectedDatabaseException`.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /var/www/html/unicobpm && php artisan test --compact tests/Unit/CoreConnectionGuardTest.php && vendor/bin/pint --format agent config/database.php app/Providers/CoreConnectionGuardProvider.php tests/BootstrapEphemeral.php tests/Unit/CoreConnectionGuardTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git -C /var/www/html/unicobpm add config/database.php app/Providers/CoreConnectionGuardProvider.php bootstrap/providers.php tests/BootstrapEphemeral.php tests/Unit/CoreConnectionGuardTest.php phpunit.xml
git -C /var/www/html/unicobpm commit -m "$(cat <<'EOF'
fix: stop BPM tests from pointing the core connection at the app database

EOF
)"
```

**Rollback:** revert.

### Task 7: Wiring unicoagent

**Files:**
- Modify: `/var/www/html/unicoagent/config/database.php` blocco `'core'` linee 68–82
- Create: `/var/www/html/unicoagent/app/Providers/CoreConnectionGuardProvider.php`
- Modify: `/var/www/html/unicoagent/bootstrap/providers.php`
- Create: `/var/www/html/unicoagent/tests/BootstrapEphemeral.php`
- Modify: `/var/www/html/unicoagent/phpunit.xml`
- Test: `/var/www/html/unicoagent/tests/Unit/CoreConnectionGuardTest.php`

**Interfaces:**
- Consumes: `CoreConnectionPolicy::assert`, che rifiuta `sqlite` sulla connessione core
- Produces: `UNICO_CORE_DB_CONNECTION=core` e `CORE_DB_*` effimeri. Il database applicativo può restare sqlite `:memory:` perché non è la connessione core. `phpunit.xml` oggi ha `UNICO_CORE_DB_CONNECTION=sqlite`: va cambiato in `core`.

Se `git status` fallisce per dubious ownership, scrivere i file ma non committare; fermarsi e dirlo.

- [ ] **Step 1: Write the failing test**

```php
public function test_agent_phpunit_does_not_use_sqlite_for_core(): void
{
    $this->assertSame('core', config('unico-core.connection'));
    $this->assertNotSame('sqlite', config('database.connections.core.driver'));
    \Unico\Core\Database\PhpUnitDatabaseGuard::assertEphemeral(config('database.connections.core.database'));
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /var/www/html/unicoagent && php artisan test --compact tests/Unit/CoreConnectionGuardTest.php`

Expected: FAIL, perché il core di test è sqlite.

- [ ] **Step 3: Write minimal implementation**

Blocco `core` come nel task 3. Provider identico. Bootstrap che imposta `UNICO_CORE_DB_CONNECTION=core` e i cinque `CORE_DB_*`, con database effimero. In `phpunit.xml` sostituire `<env name="UNICO_CORE_DB_CONNECTION" value="sqlite"/>` con `value="core"` e aggiungere il bootstrap. Non cambiare `DB_CONNECTION=sqlite` del database applicativo.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /var/www/html/unicoagent && php artisan test --compact tests/Unit/CoreConnectionGuardTest.php && vendor/bin/pint --format agent config/database.php app/Providers/CoreConnectionGuardProvider.php tests/BootstrapEphemeral.php tests/Unit/CoreConnectionGuardTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

Solo se Git accetta il repo senza cambiare la config:

```bash
git -C /var/www/html/unicoagent add config/database.php app/Providers/CoreConnectionGuardProvider.php bootstrap/providers.php tests/BootstrapEphemeral.php tests/Unit/CoreConnectionGuardTest.php phpunit.xml
git -C /var/www/html/unicoagent commit -m "$(cat <<'EOF'
fix: keep agent PHPUnit off the shared core database

EOF
)"
```

**Rollback:** revert, oppure rimuovere i file nuovi se il commit non è stato fatto.

### Task 8: Nomi, RunId e isolamento PHPUnit

**Files:**
- Create: `/var/www/html/backups3/app/Lifecycle/Domains.php`
- Create: `/var/www/html/backups3/app/Lifecycle/RunId.php`
- Create: `/var/www/html/backups3/app/Lifecycle/PhpunitIsolation.php`
- Create: `/var/www/html/backups3/docker/lifecycle-phpunit/compose.yml`
- Test: `/var/www/html/backups3/tests/Unit/Lifecycle/NamingTest.php`

**Interfaces:**
- Consumes: niente dei comandi
- Produces: `Domains::LOGICAL` mappa `core|loan|accounting|oam|bpm|agent` → `canonical`, `clone`, `source`, `legacy`; `RunId::generate(): string`; `RunId::isPublic(string $id): bool`; `RunId::sqlSafe(string $id): string`; `Domains::phpunit(string $logical, string $runId, int $process): string`; `Domains::build(string $logical, string $runId): string`; `PhpunitIsolation::assertProcess(array $env, string $database): void`. `sqlSafe` restituisce l'ULID minuscolo, già conforme a `[a-z0-9_]+`.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Lifecycle/NamingTest.php`:

```php
<?php

namespace Tests\Unit\Lifecycle;

use App\Lifecycle\Domains;
use App\Lifecycle\PhpunitIsolation;
use App\Lifecycle\RunId;
use PHPUnit\Framework\TestCase;

class NamingTest extends TestCase
{
    public function test_public_run_id_is_a_lowercase_ulid_and_sql_safe(): void
    {
        $id = RunId::generate();
        $this->assertTrue(RunId::isPublic($id));
        $this->assertSame($id, RunId::sqlSafe($id));
        $this->assertFalse(RunId::isPublic('01ARZ3NDEKTSV4RRFFQ69G5FAV'));
        $this->assertFalse(RunId::isPublic('unico_core'));
    }

    public function test_domain_names_match_the_approved_map(): void
    {
        $this->assertSame('unico_core', Domains::LOGICAL['core']['canonical']);
        $this->assertSame('unico_core_test', Domains::LOGICAL['core']['clone']);
        $this->assertSame('unico_core_test', Domains::LOGICAL['core']['source']);
        $this->assertSame('unicoloan_core', Domains::LOGICAL['loan']['source']);
        $this->assertSame('unicoloan', Domains::LOGICAL['loan']['legacy']);
        $this->assertSame('proforma_core', Domains::LOGICAL['accounting']['source']);
        $this->assertSame('unicooam_core', Domains::LOGICAL['oam']['source']);
        $this->assertSame('unicobpm_dev', Domains::LOGICAL['bpm']['source']);
        $this->assertSame('unicoagent', Domains::LOGICAL['agent']['source']);
        $this->assertNull(Domains::LOGICAL['agent']['legacy']);
        $this->assertNotContains('whistle', array_column(Domains::LOGICAL, 'canonical'));
        $this->assertSame(
            'unico_core_phpunit_01arz3ndektsv4rrffq69g5fav_3',
            Domains::phpunit('core', '01arz3ndektsv4rrffq69g5fav', 3),
        );
        $this->assertSame(
            'unicoloan_build_01arz3ndektsv4rrffq69g5fav',
            Domains::build('loan', '01arz3ndektsv4rrffq69g5fav'),
        );
    }

    public function test_phpunit_process_rejects_vps_credentials_and_shared_names(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PhpunitIsolation::assertProcess([
            'R2_SECRET_ACCESS_KEY' => 'secret',
            'DB_HOST' => '10.0.0.8',
        ], 'unico_core_test');
    }

    public function test_compose_file_has_no_published_ports_and_no_external_network(): void
    {
        $compose = file_get_contents(dirname(__DIR__, 3).'/docker/lifecycle-phpunit/compose.yml');
        $this->assertStringContainsString('internal: true', $compose);
        $this->assertStringNotContainsString('ports:', $compose);
        $this->assertStringNotContainsString('R2_', $compose);
        $this->assertStringNotContainsString('unico_core_test', $compose);
    }
}
```

Il test di isolamento va spezzato: un caso accetta `DB_HOST=127.0.0.1`, database `unico_core_phpunit_01arz3ndektsv4rrffq69g5fav_3` e env senza chiavi `R2_`, `DB_BACKUP_`, `BPM_BRIDGE`. Un secondo caso, quello sopra, rifiuta.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /var/www/html/backups3 && php artisan test --compact tests/Unit/Lifecycle/NamingTest.php`

Expected: FAIL, classi assenti.

- [ ] **Step 3: Write minimal implementation**

`RunId::generate()` usa `strtolower((string) \Illuminate\Support\Str::ulid())` e rilancia se non passa `isPublic`. `isPublic` è `preg_match('/^[0-9a-hjkmnp-tv-z]{26}$/', $id) === 1`. `sqlSafe` ritorna `$id` solo se `isPublic`, altrimenti `InvalidArgumentException`.

`Domains::phpunit` e `build` usano `RunId::sqlSafe` e il nome canonico della mappa. `phpunit` concatena `_{$process}` solo se `$process >= 0`.

`PhpunitIsolation::assertProcess` rifiuta se il database non matcha `'/^(unico_core|unicoloan|proforma|unicooam|unicobpm|unicoagent)_phpunit_[0-9a-hjkmnp-tv-z]{26}_[0-9]+$/'`, se una chiave env inizia con `R2_`, `DB_BACKUP_`, `BPM_BRIDGE`, `AWS_`, o se `DB_HOST` non è `127.0.0.1`.

`docker/lifecycle-phpunit/compose.yml`:

```yaml
services:
  phpunit-mysql:
    image: mysql:8.4
    environment:
      MYSQL_ROOT_PASSWORD: synthetic-phpunit
      MYSQL_DATABASE: unico_core_phpunit_01arz3ndektsv4rrffq69g5fav_1
    networks: [isolated]
networks:
  isolated:
    internal: true
```

Nessuna chiave `ports`. I test non eseguono `docker compose up`.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /var/www/html/backups3 && php artisan test --compact tests/Unit/Lifecycle/NamingTest.php && vendor/bin/pint --format agent app/Lifecycle/Domains.php app/Lifecycle/RunId.php app/Lifecycle/PhpunitIsolation.php tests/Unit/Lifecycle/NamingTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git -C /var/www/html/backups3 add app/Lifecycle/Domains.php app/Lifecycle/RunId.php app/Lifecycle/PhpunitIsolation.php docker/lifecycle-phpunit/compose.yml tests/Unit/Lifecycle/NamingTest.php
git -C /var/www/html/backups3 commit -m "$(cat <<'EOF'
feat: name canonical, clone, build, and ephemeral databases

EOF
)"
```

**Rollback:** revert. Non avviare il compose.

### Task 9: JSON canonico e manifest v1

**Files:**
- Create: `/var/www/html/backups3/app/Lifecycle/CanonicalJson.php`
- Create: `/var/www/html/backups3/app/Lifecycle/Manifest.php`
- Test: `/var/www/html/backups3/tests/Unit/Lifecycle/ManifestTest.php`

**Interfaces:**
- Consumes: `RunId::isPublic`, `Domains::LOGICAL`
- Produces: `CanonicalJson::encode(array $value): string`; `Manifest::fromArray(array $data): self`; `toCanonical(): string`; `objectKey(): string` nel formato `s3://{bucket}/{tenant_prefix}/database-lifecycle/v1/runs/{run_id}/manifest.json`; proprietà `parentRunId`, `boundedSkewSeconds`, `purpose`, `reports`. Purpose ammessi: `inspect`, `build`, `dev-clone`, `cutover`, `rollback`.

- [ ] **Step 1: Write the failing test**

Il test codifica `['b' => 1, 'a' => ['d' => 1, 'c' => 2]]` e si aspetta `{"a":{"c":2,"d":1},"b":1}`. Un manifest minimo con sei domini, `manifest_version` 1, timestamp `2026-10-13T09:00:00Z`, `bounded_skew_seconds` 120, `purpose` `inspect`, passa `toCanonical()`. Manca un dominio: eccezione. `purpose` `snapshot`: eccezione. Una chiave `password` nel payload: eccezione da `fromArray`. `objectKey()` per bucket `bucket`, prefix `tenant-a`, run `01arz3ndektsv4rrffq69g5fav` è `s3://bucket/tenant-a/database-lifecycle/v1/runs/01arz3ndektsv4rrffq69g5fav/manifest.json`.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /var/www/html/backups3 && php artisan test --compact tests/Unit/Lifecycle/ManifestTest.php`

Expected: FAIL.

- [ ] **Step 3: Write minimal implementation**

`CanonicalJson::encode` ordina le chiavi con `ksort` ricorsivo e usa `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR`. `Manifest::fromArray` verifica versione 1, run id, sei chiavi di dominio prese da `Domains::LOGICAL`, skew intero `>= 0` e `<= 900`, purpose nell'elenco, assenza ricorsiva delle chiavi `password`, `secret`, `token`, `private_key`, `authorization`. I report di fase sono una lista di `{phase, sha256}`.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /var/www/html/backups3 && php artisan test --compact tests/Unit/Lifecycle/ManifestTest.php && vendor/bin/pint --format agent app/Lifecycle/CanonicalJson.php app/Lifecycle/Manifest.php tests/Unit/Lifecycle/ManifestTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git -C /var/www/html/backups3 add app/Lifecycle/CanonicalJson.php app/Lifecycle/Manifest.php tests/Unit/Lifecycle/ManifestTest.php
git -C /var/www/html/backups3 commit -m "$(cat <<'EOF'
feat: add the version 1 canonical lifecycle manifest

EOF
)"
```

**Rollback:** revert.

### Task 10: Firma Ed25519

**Files:**
- Create: `/var/www/html/backups3/app/Lifecycle/ManifestSigner.php`
- Test: `/var/www/html/backups3/tests/Unit/Lifecycle/ManifestSignerTest.php`

**Interfaces:**
- Consumes: `CanonicalJson::encode`
- Produces: `ManifestSigner::sign(string $payload, string $secretKey, string $keyId): string` (base64 della firma detached); `verify(string $payload, string $signature, array $publicKeys): void`. `$publicKeys` è `array<string, string>` key id → public key binaria. Key id sconosciuto, firma alterata o secret path dentro il repo: `LifecycleException`.

- [ ] **Step 1: Write the failing test**

Generare la coppia con `sodium_crypto_sign_keypair()`. Firmare il payload `{"manifest_version":1}`. `verify` con la public key giusta passa. Un byte cambiato nel payload lancia. Un key id `other` lancia. `ManifestSigner::assertSecretPath('/var/www/html/backups3/.keys/ed25519')` lancia perché il path è sotto il repo. Un path `/run/lifecycle/ed25519` passa il controllo di path e non legge il file se non esiste: il metodo controlla solo il prefisso, non apre il secret. Il test non scrive chiavi su disco.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /var/www/html/backups3 && php artisan test --compact tests/Unit/Lifecycle/ManifestSignerTest.php`

Expected: FAIL.

- [ ] **Step 3: Write minimal implementation**

`sign` usa `sodium_crypto_sign_detached`. `verify` usa `sodium_crypto_sign_verify_detached` e rifiuta key id assente. `assertSecretPath` rifiuta se `str_starts_with(realpath` non applicabile: confrontare il path normalizzato con `dirname(base_path())` e con `base_path()`. Se il path è dentro `base_path()`, eccezione. Non loggare la chiave.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /var/www/html/backups3 && php artisan test --compact tests/Unit/Lifecycle/ManifestSignerTest.php && vendor/bin/pint --format agent app/Lifecycle/ManifestSigner.php tests/Unit/Lifecycle/ManifestSignerTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git -C /var/www/html/backups3 add app/Lifecycle/ManifestSigner.php tests/Unit/Lifecycle/ManifestSignerTest.php
git -C /var/www/html/backups3 commit -m "$(cat <<'EOF'
feat: sign lifecycle manifests with Ed25519

EOF
)"
```

**Rollback:** revert. Le chiavi di test non sono su disco.

### Task 11: Redazione segreti

**Files:**
- Create: `/var/www/html/backups3/app/Lifecycle/Redactor.php`
- Create: `/var/www/html/backups3/app/Lifecycle/LifecycleException.php`
- Test: `/var/www/html/backups3/tests/Unit/Lifecycle/RedactorTest.php`

**Interfaces:**
- Consumes: niente
- Produces: `Redactor::redact(string $text): string`; `LifecycleException`

- [ ] **Step 1: Write the failing test**

Input con `password=secret`, `mysql://user:secret@db:3306/unico_core`, `BEGIN OPENSSH PRIVATE KEY`, email `a@b.com`, telefono `+39 333 1234567`. Output contiene `[redacted]` e non contiene `secret`, la email, il numero, `OPENSSH`. Un manifest canonico passato a `Redactor::assertClean` con la parola `password` lancia `LifecycleException`.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /var/www/html/backups3 && php artisan test --compact tests/Unit/Lifecycle/RedactorTest.php`

Expected: FAIL.

- [ ] **Step 3: Write minimal implementation**

Sostituire con regex, in quest'ordine: blocco private key, URL con userinfo, coppie `password|secret|token|api_key=...`, email, numeri telefonici che iniziano con `+` e hanno almeno 8 cifre. `assertClean` rilancia se il testo redactato è diverso dall'originale.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /var/www/html/backups3 && php artisan test --compact tests/Unit/Lifecycle/RedactorTest.php && vendor/bin/pint --format agent app/Lifecycle/Redactor.php app/Lifecycle/LifecycleException.php tests/Unit/Lifecycle/RedactorTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git -C /var/www/html/backups3 add app/Lifecycle/Redactor.php app/Lifecycle/LifecycleException.php tests/Unit/Lifecycle/RedactorTest.php
git -C /var/www/html/backups3 commit -m "$(cat <<'EOF'
feat: redact secrets and personal data from lifecycle output

EOF
)"
```

**Rollback:** revert.

### Task 12: Selezione storica e skew

**Files:**
- Create: `/var/www/html/backups3/app/Lifecycle/HistoricalObject.php`
- Create: `/var/www/html/backups3/app/Lifecycle/HistoricalTupleSelector.php`
- Test: `/var/www/html/backups3/tests/Unit/Lifecycle/HistoricalTupleSelectorTest.php`

**Interfaces:**
- Consumes: `Domains::LOGICAL` chiavi logiche e `source`
- Produces: `HistoricalObject` con `logical`, `objectKey`, `gzipValid`, `filenameAt`, `metadataAt`, `headerAt` come `\DateTimeImmutable` UTC; `HistoricalTupleSelector::select(array $objects): Selection`. `Selection` ha `accepted: bool`, `skewSeconds: ?int`, `objects: array`, `reason: string`. Oggetto con `gzipValid=false` o con scarto tra i tre orologi `> 60` secondi è scartato prima della tupla.

- [ ] **Step 1: Write the failing test**

Costruire sei oggetti alle 09:00:00 e un secondo core alle 09:02:00. La tupla scelta ha skew 120 e il core delle 09:02:00, perché a parità non c'è: la tupla più stretta vince. Secondo caso: due tuple entrambe con skew 120; vince quella il cui massimo è più recente. Terzo caso: togliere `agent`; `accepted` è false e `reason` è `FAILED_INSPECT`. Quarto: sei oggetti con skew 901 secondi; rifiutato. Quinto: skew 900; accettato. Sesto: `headerAt` 61 secondi dopo `filenameAt`; quell'oggetto è invalido e, se era l'unico del dominio, il risultato è `FAILED_INSPECT`. Un object key storico non viene modificato: il selector restituisce la stessa stringa ricevuta.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /var/www/html/backups3 && php artisan test --compact tests/Unit/Lifecycle/HistoricalTupleSelectorTest.php`

Expected: FAIL.

- [ ] **Step 3: Write minimal implementation**

Scartare gli oggetti invalidi. Raggruppare per `logical`. Se manca una delle sei chiavi di `Domains::LOGICAL`, ritornare `FAILED_INSPECT`. Prodotto cartesiano solo quando ogni gruppo ha almeno un elemento. Tenere le tuple con `max-min <= 86400`. Tra queste, scartare `skew > 900`. Ordinare per skew ascendente e poi per max timestamp discendente. `skew = max-min` in secondi.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /var/www/html/backups3 && php artisan test --compact tests/Unit/Lifecycle/HistoricalTupleSelectorTest.php && vendor/bin/pint --format agent app/Lifecycle/HistoricalObject.php app/Lifecycle/HistoricalTupleSelector.php tests/Unit/Lifecycle/HistoricalTupleSelectorTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git -C /var/www/html/backups3 add app/Lifecycle/HistoricalObject.php app/Lifecycle/HistoricalTupleSelector.php tests/Unit/Lifecycle/HistoricalTupleSelectorTest.php
git -C /var/www/html/backups3 commit -m "$(cat <<'EOF'
feat: select a complete historical dump group inside 15 minutes

EOF
)"
```

**Rollback:** revert. Nessun oggetto R2 è stato letto.

### Task 13: Classificazione ownership

**Files:**
- Create: `/var/www/html/backups3/app/Lifecycle/OwnershipClassifier.php`
- Test: `/var/www/html/backups3/tests/Unit/Lifecycle/OwnershipClassifierTest.php`

**Interfaces:**
- Consumes: `Domains::LOGICAL`
- Produces: `OwnershipClassifier::classify(array $primary, array $legacy, array $owners): OwnershipReport`. `$owners` mappa tabella → dominio logico. Report con `import`, `conflict`, `reroute`, `quarantine`, `views`. `blocksBuild` true se c'è un conflict. `blocksCutover` true se c'è una quarantena o una vista senza decisione firmata.

- [ ] **Step 1: Write the failing test**

Primario `loan` ha `clients`. Legacy `unicoloan` ha `clients` con schema diverso: conflict e `blocksBuild`. Legacy ha `extra_loan` assente dal primario e owner `loan`: import. Legacy ha `blacklist_clienti_fornitori` owner `oam`: reroute verso `oam`, non verso `core`. Tabella `mystery` senza owner: quarantine e `blocksCutover`. Vista `bad_view` senza decisione: `blocksCutover`. La stessa vista con `signedDecision=true`: non blocca. `activity_log` owner `loan` non viene spostato nel core.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /var/www/html/backups3 && php artisan test --compact tests/Unit/Lifecycle/OwnershipClassifierTest.php`

Expected: FAIL.

- [ ] **Step 3: Write minimal implementation**

Confrontare schema come stringa normalizzata passata dal chiamante (`schema` nel record tabella). Non interrogare MySQL. Record: `['name' => 'clients', 'schema' => 'id:int', 'kind' => 'table|view', 'signedDecision' => false]`.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /var/www/html/backups3 && php artisan test --compact tests/Unit/Lifecycle/OwnershipClassifierTest.php && vendor/bin/pint --format agent app/Lifecycle/OwnershipClassifier.php tests/Unit/Lifecycle/OwnershipClassifierTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git -C /var/www/html/backups3 add app/Lifecycle/OwnershipClassifier.php tests/Unit/Lifecycle/OwnershipClassifierTest.php
git -C /var/www/html/backups3 commit -m "$(cat <<'EOF'
feat: classify legacy tables by the approved owner

EOF
)"
```

**Rollback:** revert.

### Task 14: Configurazione tenant fail-closed

**Files:**
- Create: `/var/www/html/backups3/config/lifecycle.php`
- Create: `/var/www/html/backups3/app/Lifecycle/TenantConfig.php`
- Modify: `/var/www/html/backups3/bootstrap/providers.php`
- Create: `/var/www/html/backups3/app/Providers/LifecycleServiceProvider.php`
- Test: `/var/www/html/backups3/tests/Unit/Lifecycle/TenantConfigTest.php`

**Interfaces:**
- Consumes: `Domains::LOGICAL`, `CoreConnectionPolicy::assertServerUuid`
- Produces: `TenantConfig::load(array $config, string $tenant): TenantProfile`. Proprietà `id`, `prefix`, `blueEndpointId`, `greenEndpointId`, `allowedServerUuids`, `secretRefs` (nomi, non valori), `domains`. `allow_remote_catalog` e `live_sql` default `false`. Tenant assente o incompleto: `LifecycleException`. Nessun tenant di default se la stringa è vuota.

- [ ] **Step 1: Write the failing test**

Config con un solo tenant `acme` e prefix `acme`. `load($config, '')` lancia. `load($config, 'missing')` lancia. `load($config, 'acme')` restituisce il prefix. Se manca `secret_refs.signature`, lancia. `config('lifecycle.live_sql')` è false e `config('lifecycle.allow_remote_catalog')` è false. Un UUID osservato diverso da `allowed_server_uuids` lancia prima che il test apra una connessione: chiamare `TenantProfile::assertServer('wrong')`.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /var/www/html/backups3 && php artisan test --compact tests/Unit/Lifecycle/TenantConfigTest.php`

Expected: FAIL.

- [ ] **Step 3: Write minimal implementation**

`config/lifecycle.php` legge `LIFECYCLE_TENANTS` come JSON. Se l'env manca, la mappa è `[]`, non un tenant finto. Flag:

```php
'live_sql' => (bool) env('LIFECYCLE_LIVE_SQL', false),
'allow_remote_catalog' => (bool) env('LIFECYCLE_ALLOW_REMOTE_CATALOG', false),
```

Il provider registra il config file già caricato da Laravel perché sta in `config/`. Il provider, in `testing`, forza i due flag a false anche se l'env fosse true. `TenantProfile::assertServer` confronta con `allowedServerUuids` e lancia `LifecycleException`.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /var/www/html/backups3 && php artisan test --compact tests/Unit/Lifecycle/TenantConfigTest.php && vendor/bin/pint --format agent config/lifecycle.php app/Lifecycle/TenantConfig.php app/Providers/LifecycleServiceProvider.php tests/Unit/Lifecycle/TenantConfigTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git -C /var/www/html/backups3 add config/lifecycle.php app/Lifecycle/TenantConfig.php app/Providers/LifecycleServiceProvider.php bootstrap/providers.php tests/Unit/Lifecycle/TenantConfigTest.php
git -C /var/www/html/backups3 commit -m "$(cat <<'EOF'
feat: require an explicit lifecycle tenant

EOF
)"
```

**Rollback:** revert. Non lasciare un tenant di default nel config.

### Task 15: Gate dry-run e conferma

**Files:**
- Create: `/var/www/html/backups3/app/Lifecycle/ExecutionGate.php`
- Test: `/var/www/html/backups3/tests/Unit/Lifecycle/ExecutionGateTest.php`

**Interfaces:**
- Consumes: `RunId::isPublic`, `Redactor::assertClean`
- Produces: `ExecutionGate::decide(string $command, ?string $tenant, ?string $runId, bool $execute, ?string $confirmRunId): ExecutionDecision`. `ExecutionDecision::dryRun` bool, `persist` bool. Comandi: `inspect`, `build`, `verify`, `cutover`, `rollback`, `clone`, `status`.

- [ ] **Step 1: Write the failing test**

Senza `--execute`, `persist` è false. Con `--execute` e confirm uguale al run, `persist` è true per `build`. Per `inspect`, `persist` può essere true ma `mutatesDatabases` è false. Confirm `01ARZ3NDEKTSV4RRFFQ69G5FAV` contro run minuscolo: dry-run, `persist` false. Confirm con spazio finale: `persist` false. `status` con `execute=true`: eccezione. `status` senza run id: dry-run lecito. Tenant vuoto: eccezione per ogni comando. `snapshot`: eccezione. Il messaggio di `describe()` non contiene `password=`.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /var/www/html/backups3 && php artisan test --compact tests/Unit/Lifecycle/ExecutionGateTest.php`

Expected: FAIL.

- [ ] **Step 3: Write minimal implementation**

Il confronto del confirm è `$confirmRunId === $runId` senza `trim` e senza `strtolower`. `mutatesDatabases` è true solo per `build`, `cutover`, `rollback`, `clone` quando `persist` è true. `inspect` e `verify` hanno `persist` true solo per journal e manifest.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /var/www/html/backups3 && php artisan test --compact tests/Unit/Lifecycle/ExecutionGateTest.php && vendor/bin/pint --format agent app/Lifecycle/ExecutionGate.php tests/Unit/Lifecycle/ExecutionGateTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git -C /var/www/html/backups3 add app/Lifecycle/ExecutionGate.php tests/Unit/Lifecycle/ExecutionGateTest.php
git -C /var/www/html/backups3 commit -m "$(cat <<'EOF'
feat: keep lifecycle commands dry-run until the run id is confirmed

EOF
)"
```

**Rollback:** revert.

### Task 16: State machine

**Files:**
- Create: `/var/www/html/backups3/app/Lifecycle/RunState.php`
- Create: `/var/www/html/backups3/app/Lifecycle/StateMachine.php`
- Test: `/var/www/html/backups3/tests/Unit/Lifecycle/StateMachineTest.php`

**Interfaces:**
- Consumes: `LifecycleException`
- Produces: enum `RunState` con i casi della spec sezione 10; `StateMachine::transition(RunState $from, string $event): RunState`; `StateMachine::resume(RunState $failed): RunState` che da `FAILED_INSPECT` torna a `INSPECTING`, da `FAILED_BUILD` a `BUILDING`, da `FAILED_VERIFY` a `VERIFYING`, da `FAILED_CUTOVER` a `CUTTING_OVER`, da `FAILED_CLONE` a `CLONING`. `ROLLED_BACK` e `RETIRED` non hanno resume.

- [ ] **Step 1: Write the failing test**

Percorso `PLANNED` → `INSPECTING` → `INSPECTED` → `BUILDING` → `BUILT` → `VERIFYING` → `VERIFIED` → `CUTOVER_PENDING` → `CUTTING_OVER` → `ACTIVE` → `ROLLBACK_PENDING` → `ROLLING_BACK` → `ROLLED_BACK`. Un evento `retire` da `ACTIVE` va a `RETIRED`. Da `INSPECTED`, `clone` va a `CLONING` → `SANITIZING` → `CLONE_VERIFIED` → `CLONE_READY`. `FAILED_INSPECT` con `resume` torna `INSPECTING`. `ROLLED_BACK` con `resume` lancia. Transizione `PLANNED` → `ACTIVE` lancia.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /var/www/html/backups3 && php artisan test --compact tests/Unit/Lifecycle/StateMachineTest.php`

Expected: FAIL.

- [ ] **Step 3: Write minimal implementation**

Tabella esplicita evento → stato destinazione, una mappa per stato di partenza. Nessun default che faccia cadere in `ACTIVE`.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /var/www/html/backups3 && php artisan test --compact tests/Unit/Lifecycle/StateMachineTest.php && vendor/bin/pint --format agent app/Lifecycle/RunState.php app/Lifecycle/StateMachine.php tests/Unit/Lifecycle/StateMachineTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git -C /var/www/html/backups3 add app/Lifecycle/RunState.php app/Lifecycle/StateMachine.php tests/Unit/Lifecycle/StateMachineTest.php
git -C /var/www/html/backups3 commit -m "$(cat <<'EOF'
feat: encode the lifecycle run state machine

EOF
)"
```

**Rollback:** revert.

### Task 17: Journal append-only

**Files:**
- Create: `/var/www/html/backups3/app/Lifecycle/Journal.php`
- Test: `/var/www/html/backups3/tests/Unit/Lifecycle/JournalTest.php`

**Interfaces:**
- Consumes: `RunState`, `CanonicalJson`, `Redactor`
- Produces: `Journal::open(string $directory, string $runId): self`; `append(array $record): void`; `read(): array`. Ogni riga ha `previous_sha256` e `sha256`. Un run `ROLLED_BACK` o `RETIRED` rifiuta append ulteriori. Il directory di test è `storage/framework/testing/lifecycle`.

- [ ] **Step 1: Write the failing test**

Due append sullo stesso run. La seconda riga ha `previous_sha256` uguale allo sha della prima. Riscrivere il file a mano e poi `read()` lancia. Append con `'password' => 'x'` lancia prima di scrivere. Journal su disco pieno simulato da un directory non scrivibile: l'eccezione è `LifecycleException` e il file precedente, se c'era, resta. Creare il directory di test con `mkdir` in `setUp` e cancellarlo in `tearDown`.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /var/www/html/backups3 && php artisan test --compact tests/Unit/Lifecycle/JournalTest.php`

Expected: FAIL.

- [ ] **Step 3: Write minimal implementation**

File `journal.jsonl`. `sha256` è `hash('sha256', $canonicalLine)`. `read` ricalcola la catena. Non usare `file_put_contents` sull'intero file: solo `FILE_APPEND`.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /var/www/html/backups3 && php artisan test --compact tests/Unit/Lifecycle/JournalTest.php && vendor/bin/pint --format agent app/Lifecycle/Journal.php tests/Unit/Lifecycle/JournalTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git -C /var/www/html/backups3 add app/Lifecycle/Journal.php tests/Unit/Lifecycle/JournalTest.php
git -C /var/www/html/backups3 commit -m "$(cat <<'EOF'
feat: append lifecycle transitions to a hashed journal

EOF
)"
```

**Rollback:** revert. Il journal di test sta sotto `storage/framework/testing` ed è gitignored.

### Task 18: Lock e fencing token

**Files:**
- Create: `/var/www/html/backups3/app/Lifecycle/LifecycleLock.php`
- Test: `/var/www/html/backups3/tests/Unit/Lifecycle/LifecycleLockTest.php`

**Interfaces:**
- Consumes: `Journal::append`, `LifecycleException`
- Produces: `LifecycleLock::acquire(string $tenant, string $environment, int $now, int $leaseSeconds): LockGrant`; `assertUsable(LockGrant $grant, int $now): void`; `renew`; `release`. `LockGrant` ha `token` int monotono e `expiresAt`. Un token scaduto fa lanciare sia a `Journal::append` (il journal riceve il token e rifiuta) sia a `SqlPolicy` nel task 22. Sblocco amministrativo: `LifecycleLock::break(LockGrant $grant, array $approverIds, bool $processGone): void` richiede `processGone` true e due approvatori distinti, altrimenti lancia e il lock resta.

- [ ] **Step 1: Write the failing test**

Acquire a t=0 lease 10. `assertUsable` a t=11 lancia. Il token successivo è 2. `break` con un solo approvatore lancia. `break` con due approvatori e `processGone` false lancia. Con due approvatori e processo assente, il lock si libera. Un grant scaduto passato a un journal di test non aggiunge righe: il test conta le righe prima e dopo.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /var/www/html/backups3 && php artisan test --compact tests/Unit/Lifecycle/LifecycleLockTest.php`

Expected: FAIL.

- [ ] **Step 3: Write minimal implementation**

Stato del lock in un file JSON nel directory del journal, separato dal jsonl. `assertUsable` confronta `$now < expiresAt` e token uguale all'ultimo emesso. `Journal::append` guadagna un argomento opzionale `?LockGrant $grant = null`; se presente e scaduto rispetto a `now` nel record, non scrive. Per il test, il record include `now`.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /var/www/html/backups3 && php artisan test --compact tests/Unit/Lifecycle/LifecycleLockTest.php tests/Unit/Lifecycle/JournalTest.php && vendor/bin/pint --format agent app/Lifecycle/LifecycleLock.php app/Lifecycle/Journal.php tests/Unit/Lifecycle/LifecycleLockTest.php`

Expected: PASS, journal compreso.

- [ ] **Step 5: Commit**

```bash
git -C /var/www/html/backups3 add app/Lifecycle/LifecycleLock.php app/Lifecycle/Journal.php tests/Unit/Lifecycle/LifecycleLockTest.php
git -C /var/www/html/backups3 commit -m "$(cat <<'EOF'
feat: fence lifecycle writes with a leased lock token

EOF
)"
```

**Rollback:** revert.

### Task 19: Classi di errore e retry

**Files:**
- Create: `/var/www/html/backups3/app/Lifecycle/FailureClass.php`
- Create: `/var/www/html/backups3/app/Lifecycle/RetryPolicy.php`
- Test: `/var/www/html/backups3/tests/Unit/Lifecycle/RetryPolicyTest.php`

**Interfaces:**
- Consumes: `LifecycleException`
- Produces: enum `FailureClass` con `Validation`, `Transient`, `Data`, `Approval`, `PartialCutover`, `Security`; `RetryPolicy::decide(FailureClass $class, int $attempt): RetryDecision` con `retry` bool e `delaySeconds` int. Transient: tentativi 1, 2, 3 con delay 1, 2, 4 secondi; il quarto no. Validation, Approval, Security, Data, PartialCutover: mai retry. Data e PartialCutover impostano `quarantine` true. `cleanupRetainsDiagnostics` è sempre true.

- [ ] **Step 1: Write the failing test**

Un caso per ogni classe. Il quarto transient non riprova. `Validation` al primo tentativo non riprova. `PartialCutover` ha `quarantine` true e `retry` false.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /var/www/html/backups3 && php artisan test --compact tests/Unit/Lifecycle/RetryPolicyTest.php`

Expected: FAIL.

- [ ] **Step 3: Write minimal implementation**

`match` sul enum. Nessun ramo che riprova `Security`.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /var/www/html/backups3 && php artisan test --compact tests/Unit/Lifecycle/RetryPolicyTest.php && vendor/bin/pint --format agent app/Lifecycle/FailureClass.php app/Lifecycle/RetryPolicy.php tests/Unit/Lifecycle/RetryPolicyTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git -C /var/www/html/backups3 add app/Lifecycle/FailureClass.php app/Lifecycle/RetryPolicy.php tests/Unit/Lifecycle/RetryPolicyTest.php
git -C /var/www/html/backups3 commit -m "$(cat <<'EOF'
feat: classify lifecycle failures and cap transient retries

EOF
)"
```

**Rollback:** revert.

### Task 20: Comando inspect

**Files:**
- Create: `/var/www/html/backups3/app/Lifecycle/ObjectCatalog.php`
- Create: `/var/www/html/backups3/app/Lifecycle/InMemoryCatalog.php`
- Create: `/var/www/html/backups3/app/Lifecycle/RejectingCatalog.php`
- Create: `/var/www/html/backups3/app/Console/Commands/LifecycleInspectCommand.php`
- Modify: `/var/www/html/backups3/app/Providers/LifecycleServiceProvider.php`
- Test: `/var/www/html/backups3/tests/Feature/Console/LifecycleInspectCommandTest.php`

**Interfaces:**
- Consumes: `ExecutionGate::decide`, `HistoricalTupleSelector::select`, `Manifest::fromArray`, `ManifestSigner`, `Journal`, `TenantConfig::load`, `OwnershipClassifier`
- Produces: comando `db:lifecycle:inspect` con opzioni `--tenant`, `--run-id`, `--execute`, `--confirm-run-id`. In testing il provider lega `ObjectCatalog` a `InMemoryCatalog`. `RejectingCatalog::list()` lancia sempre. Nessuna chiamata a `aws`.

- [ ] **Step 1: Write the failing test**

Feature test che mette sei fixture `HistoricalObject` nell'`InMemoryCatalog`, tenant `acme`, run `01arz3ndektsv4rrffq69g5fav`. Senza `--execute` l'exit code è 0, l'output contiene `dry-run` e i sei nomi sorgente, e il journal non esiste. Con `--execute` e confirm sbagliato l'output contiene `dry-run` e non c'è journal. Con confirm esatto il journal ha una riga `INSPECTED` e il manifest in `storage/framework/testing/lifecycle/acme/.../manifest.json` non contiene `password`. Catalogo con cinque domini: exit code 1 e output `FAILED_INSPECT`. Firma mancante sul persist: exit code 1. `artisan list` non contiene `db:lifecycle:snapshot`. Il test non imposta credenziali R2.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /var/www/html/backups3 && php artisan test --compact tests/Feature/Console/LifecycleInspectCommandTest.php`

Expected: FAIL, comando assente.

- [ ] **Step 3: Write minimal implementation**

`php artisan make:command LifecycleInspectCommand --no-interaction`, poi sostituire il corpo. `handle()` chiama il gate. Se non persiste, stampa il piano e ritorna 0. Se persiste, seleziona, classifica fixture di ownership vuote come senza conflitti, firma con una chiave di test iniettata dal container (`testing` genera una coppia in memoria nel provider, non su disco), scrive manifest e journal. `RejectingCatalog` è il binding quando `allow_remote_catalog` è false, cioè sempre in testing e di default fuori dai test. `InMemoryCatalog` si usa solo se il test lo bind-a. Non implementare il client AWS in questo task.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /var/www/html/backups3 && php artisan test --compact tests/Feature/Console/LifecycleInspectCommandTest.php && vendor/bin/pint --format agent app/Console/Commands/LifecycleInspectCommand.php app/Lifecycle/ObjectCatalog.php app/Lifecycle/InMemoryCatalog.php app/Lifecycle/RejectingCatalog.php app/Providers/LifecycleServiceProvider.php tests/Feature/Console/LifecycleInspectCommandTest.php`

Expected: PASS. `php artisan list --raw` mostra `db:lifecycle:inspect` e non `db:lifecycle:snapshot`.

- [ ] **Step 5: Commit**

```bash
git -C /var/www/html/backups3 add app/Console/Commands/LifecycleInspectCommand.php app/Lifecycle/ObjectCatalog.php app/Lifecycle/InMemoryCatalog.php app/Lifecycle/RejectingCatalog.php app/Providers/LifecycleServiceProvider.php tests/Feature/Console/LifecycleInspectCommandTest.php
git -C /var/www/html/backups3 commit -m "$(cat <<'EOF'
feat: inspect a synthetic dump group without contacting storage

EOF
)"
```

**Rollback:** revert. Il catalogo remoto non esiste ancora, quindi non c'è nulla da spegnere su R2.

### Task 21: Comando status

**Files:**
- Create: `/var/www/html/backups3/app/Console/Commands/LifecycleStatusCommand.php`
- Test: `/var/www/html/backups3/tests/Feature/Console/LifecycleStatusCommandTest.php`

**Interfaces:**
- Consumes: `Journal::read`, `ExecutionGate`
- Produces: `db:lifecycle:status`. Con `--tenant` elenca i run. Con `--tenant` e `--run-id` mostra l'ultimo stato. `--execute` fa exit code 1. Non scrive.

- [ ] **Step 1: Write the failing test**

Scrivere un journal di fixture con `INSPECTED`. `status` lo stampa. `--execute` fallisce e il file non cambia mtime.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /var/www/html/backups3 && php artisan test --compact tests/Feature/Console/LifecycleStatusCommandTest.php`

Expected: FAIL.

- [ ] **Step 3: Write minimal implementation**

Comando che legge solo. Se il gate riceve `execute=true`, l'eccezione diventa exit code 1.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /var/www/html/backups3 && php artisan test --compact tests/Feature/Console/LifecycleStatusCommandTest.php && vendor/bin/pint --format agent app/Console/Commands/LifecycleStatusCommand.php tests/Feature/Console/LifecycleStatusCommandTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git -C /var/www/html/backups3 add app/Console/Commands/LifecycleStatusCommand.php tests/Feature/Console/LifecycleStatusCommandTest.php
git -C /var/www/html/backups3 commit -m "$(cat <<'EOF'
feat: show lifecycle run status without writing

EOF
)"
```

**Rollback:** revert.

### Task 22: Nomi build, SQL policy e ownership delle migration

**Files:**
- Create: `/var/www/html/backups3/app/Lifecycle/SqlPolicy.php`
- Create: `/var/www/html/backups3/app/Lifecycle/MigrationOwnership.php`
- Test: `/var/www/html/backups3/tests/Unit/Lifecycle/SqlPolicyTest.php`

**Interfaces:**
- Consumes: `Domains::build`, `LockGrant`, `FailureClass`
- Produces: `SqlPolicy::assert(string $sql, string $database, string $phase, ?LockGrant $grant, int $now): void`; `MigrationOwnership::assert(string $sql, string $ownerDatabase): void`. Fasi che possono contenere `DROP DATABASE` limitato al nome build del run: solo `build-cleanup` e solo se il nome matcha `^[a-z]+_build_[0-9a-hjkmnp-tv-z]{26}$`. `DROP` su `unico_core` o `unico_core_test` lancia sempre. Una migration di `unicoloan` che contiene `` `unico_core`.`clients` `` lancia. Una migration non reversibile è accettata solo se il manifest ha `non_reversible: true` e `rollback: blue-switch`. Il blue attivo rifiuta qualsiasi migration: fase `blue-active`.

- [ ] **Step 1: Write the failing test**

Casi: `DROP DATABASE unico_core` in fase `build` lancia; `DROP DATABASE \`unicoloan_build_01arz3ndektsv4rrffq69g5fav\`` in fase `build-cleanup` con grant valido passa; lo stesso SQL con grant scaduto lancia; `TRUNCATE clients` fuori fase lancia; SQL `` ALTER TABLE `unico_core`.`clients` `` con owner `unicoloan` lancia; owner `unico_core` sullo stesso SQL passa. Token scaduto: nessun ritorno silenzioso.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /var/www/html/backups3 && php artisan test --compact tests/Unit/Lifecycle/SqlPolicyTest.php`

Expected: FAIL.

- [ ] **Step 3: Write minimal implementation**

Cercare con regex case insensitive `DROP DATABASE`, `DROP TABLE`, `TRUNCATE`, `RENAME`, `GRANT`, `CREATE USER`, `DEFINER`. Qualificazione: `` `([a-z0-9_]+)`\. `` oppure `([a-z0-9_]+)\.`. Il nome qualificato deve essere uguale all'owner. Prima di tutto, se il grant è scaduto, lanciare.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /var/www/html/backups3 && php artisan test --compact tests/Unit/Lifecycle/SqlPolicyTest.php && vendor/bin/pint --format agent app/Lifecycle/SqlPolicy.php app/Lifecycle/MigrationOwnership.php tests/Unit/Lifecycle/SqlPolicyTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git -C /var/www/html/backups3 add app/Lifecycle/SqlPolicy.php app/Lifecycle/MigrationOwnership.php tests/Unit/Lifecycle/SqlPolicyTest.php
git -C /var/www/html/backups3 commit -m "$(cat <<'EOF'
feat: reject destructive SQL outside a fenced build database

EOF
)"
```

**Rollback:** revert.

### Task 23: Build su fixture sintetiche

**Files:**
- Create: `/var/www/html/backups3/app/Lifecycle/StatementPlanner.php`
- Create: `/var/www/html/backups3/app/Lifecycle/GuardedMysqlMutator.php`
- Create: `/var/www/html/backups3/app/Console/Commands/LifecycleBuildCommand.php`
- Test: `/var/www/html/backups3/tests/Feature/Console/LifecycleBuildCommandTest.php`
- Test: `/var/www/html/backups3/tests/Unit/Lifecycle/StatementPlannerTest.php`

**Interfaces:**
- Consumes: `SqlPolicy`, `StatementPlanner::plan(string $logical, string $runId, string $fixtureSql): array`, `GuardedMysqlMutator::execute` che lancia `LiveSqlDisabled` se `config('lifecycle.live_sql')` è false, dopo `SqlPolicy::assert`
- Produces: `db:lifecycle:build`. Il piano contiene `CREATE DATABASE`, restore senza `DEFINER`, migration del solo owner, e il nome `unico_core_build_{run}`. Non contiene `unico_core` come target di `DROP`.

- [ ] **Step 1: Write the failing test**

Fixture SQL: `CREATE TABLE clients (id int); INSERT INTO clients VALUES (1);`. Il planner per `core` e run `01arz3ndektsv4rrffq69g5fav` produce il database `unico_core_build_01arz3ndektsv4rrffq69g5fav` e strappa `DEFINER=`. `GuardedMysqlMutator::execute` con `live_sql` false lancia `LiveSqlDisabled` anche sul nome build. Con `live_sql` true e database `unico_core` lancia `ProtectedDatabaseException` e il test verifica che la classe non ha costruito un DSN: `executedDsn` resta null. Il comando in dry-run stampa i sei nomi build e non chiama `execute`. Checksum della fixture diverso dal manifest: stato `FAILED_BUILD` e `FailureClass::Data`. Spazio disco dichiarato 0 dal probe finto: `FAILED_BUILD` prima di `execute`.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /var/www/html/backups3 && php artisan test --compact tests/Unit/Lifecycle/StatementPlannerTest.php tests/Feature/Console/LifecycleBuildCommandTest.php`

Expected: FAIL.

- [ ] **Step 3: Write minimal implementation**

`StatementPlanner` è puro. Toglie righe che contengono `DEFINER`, `CREATE USER`, `GRANT`, `EVENT`. `GuardedMysqlMutator` salva il DSN solo dopo i controlli; nel ramo `unico_core` non arriva a comporlo. Il probe di spazio e versione MySQL è un'interfaccia `BuildProbe` con fake nel test: `freeBytes`, `mysqlVersion`, `serverUuid`. Il comando non istanzia PDO.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /var/www/html/backups3 && php artisan test --compact tests/Unit/Lifecycle/StatementPlannerTest.php tests/Feature/Console/LifecycleBuildCommandTest.php && vendor/bin/pint --format agent app/Lifecycle/StatementPlanner.php app/Lifecycle/GuardedMysqlMutator.php app/Console/Commands/LifecycleBuildCommand.php tests/Unit/Lifecycle/StatementPlannerTest.php tests/Feature/Console/LifecycleBuildCommandTest.php`

Expected: PASS. Nessun database MySQL nuovo sulla macchina.

- [ ] **Step 5: Commit**

```bash
git -C /var/www/html/backups3 add app/Lifecycle/StatementPlanner.php app/Lifecycle/GuardedMysqlMutator.php app/Console/Commands/LifecycleBuildCommand.php tests/Unit/Lifecycle/StatementPlannerTest.php tests/Feature/Console/LifecycleBuildCommandTest.php
git -C /var/www/html/backups3 commit -m "$(cat <<'EOF'
feat: plan a synthetic six-domain build without live SQL

EOF
)"
```

**Rollback:** revert. Non ci sono database `*_build_*` da distruggere se il mutatore non è stato eseguito.

### Task 24: Verify

**Files:**
- Create: `/var/www/html/backups3/app/Lifecycle/InvariantChecker.php`
- Create: `/var/www/html/backups3/app/Console/Commands/LifecycleVerifyCommand.php`
- Test: `/var/www/html/backups3/tests/Feature/Console/LifecycleVerifyCommandTest.php`

**Interfaces:**
- Consumes: `StateMachine`, `Manifest`, `ExecutionGate`
- Produces: `InvariantChecker::compare(array $buildCounts, array $greenCounts, array $criticalChecksums): VerifyResult`. `equivalent` true solo se conteggi e checksum coincidono sui sei domini. `db:lifecycle:verify` in `--execute` non chiama il mutatore. Smoke test è una lista di URL relativi finti già fornita dal test, eseguita da `SmokeClient` fake che non fa HTTP.

- [ ] **Step 1: Write the failing test**

Conteggi uguali e checksum uguali: `VERIFIED`. Un dominio con count diverso: `FAILED_VERIFY` e il fence non è un concetto di questo comando, ma `mutatesDatabases` è false. Smoke client che ritorna 500: `FAILED_VERIFY`. Output senza PII. Dry-run non scrive il report; execute scrive `reports/verify.json` con sha256 nel manifest.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /var/www/html/backups3 && php artisan test --compact tests/Feature/Console/LifecycleVerifyCommandTest.php`

Expected: FAIL.

- [ ] **Step 3: Write minimal implementation**

Checker puro. Comando che legge il manifest di fixture e il fake dei conteggi dal container di test. Non apre MySQL.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /var/www/html/backups3 && php artisan test --compact tests/Feature/Console/LifecycleVerifyCommandTest.php && vendor/bin/pint --format agent app/Lifecycle/InvariantChecker.php app/Console/Commands/LifecycleVerifyCommand.php tests/Feature/Console/LifecycleVerifyCommandTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git -C /var/www/html/backups3 add app/Lifecycle/InvariantChecker.php app/Console/Commands/LifecycleVerifyCommand.php tests/Feature/Console/LifecycleVerifyCommandTest.php
git -C /var/www/html/backups3 commit -m "$(cat <<'EOF'
feat: verify build and green checksums before promotion

EOF
)"
```

**Rollback:** revert del commit. I report di test sono sotto storage di testing.

### Task 25: Resume

**Files:**
- Create: `/var/www/html/backups3/app/Lifecycle/Resume.php`
- Test: `/var/www/html/backups3/tests/Unit/Lifecycle/ResumeTest.php`

**Interfaces:**
- Consumes: `Journal::read`, `StateMachine::resume`, `ManifestSigner::verify`
- Produces: `Resume::plan(array $journal, array $currentHashes, array $recordedHashes, bool $routingAlreadyAtTarget): ResumePlan`. Salta gli step con hash uguale. Se un hash di input è cambiato, `stop` true e reason `external-mutation`. Se `routingAlreadyAtTarget` e lo step è `promote`, quello step è `skip`. Un run `ROLLED_BACK` non produce step.

- [ ] **Step 1: Write the failing test**

Journal con uno step `dump-core` completato e hash uguale: skip. Hash diverso: stop. Routing già green: lo step `promote` è skip. Run terminale: eccezione.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /var/www/html/backups3 && php artisan test --compact tests/Unit/Lifecycle/ResumeTest.php`

Expected: FAIL.

- [ ] **Step 3: Write minimal implementation**

Classe pura, senza I/O. I comandi build e verify chiamano `Resume::plan` quando l'opzione `--resume` è presente. Aggiungere `--resume` a quei due comandi e un caso nel feature test di verify: hash cambiato, exit code 1, nessuna scrittura nuova.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /var/www/html/backups3 && php artisan test --compact tests/Unit/Lifecycle/ResumeTest.php tests/Feature/Console/LifecycleVerifyCommandTest.php && vendor/bin/pint --format agent app/Lifecycle/Resume.php app/Console/Commands/LifecycleVerifyCommand.php app/Console/Commands/LifecycleBuildCommand.php tests/Unit/Lifecycle/ResumeTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git -C /var/www/html/backups3 add app/Lifecycle/Resume.php app/Console/Commands/LifecycleVerifyCommand.php app/Console/Commands/LifecycleBuildCommand.php tests/Unit/Lifecycle/ResumeTest.php tests/Feature/Console/LifecycleVerifyCommandTest.php
git -C /var/www/html/backups3 commit -m "$(cat <<'EOF'
feat: resume a lifecycle run only from a matching checkpoint

EOF
)"
```

**Rollback:** revert.

### Task 26: Sanitizzazione e scanner

**Files:**
- Create: `/var/www/html/backups3/app/Lifecycle/Sanitizer.php`
- Create: `/var/www/html/backups3/app/Lifecycle/PiiScanner.php`
- Test: `/var/www/html/backups3/tests/Unit/Lifecycle/SanitizerTest.php`

**Interfaces:**
- Consumes: `Redactor`
- Produces: `Sanitizer::apply(array $rows, string $purpose, string $localKey): array`. Solo `purpose=dev-clone` riscrive. La colonna `password` diventa un hash che `password_verify('password', $hash)` accetta. Email `*@example.invalid`. Telefoni `+390000000000`. Token, api key, otp, remember token diventano stringa vuota. Nomi uguali restano uguali dopo la pseudonimizzazione (stesso input, stessa chiave → stesso output) e cambiano se la chiave cambia. `localKey` non compare nell'output. `purpose=production` lancia se l'output contiene l'hash di `password`. `PiiScanner::scan(array $rows): list<string>` ritorna i finding. `Sanitizer` svuota anche le chiavi `jobs`, `scheduled`, `mail`, `sms`, `whatsapp`, `webhook`, `payment` mettendole a lista vuota e `outbound` a false.

- [ ] **Step 1: Write the failing test**

Una riga con password, email `ada@example.com`, telefono, due nomi uguali `Ada`, un token. Dopo sanitize, verificare hash, dominio `.invalid`, nomi deterministici, token vuoto, chiave assente. Scanner su una riga non sanitizzata trova l'email. Scanner sulla riga sanitizzata ritorna `[]`. `purpose=cutover` non cambia la password e lancia se gli si chiede di applicare la policy clone.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /var/www/html/backups3 && php artisan test --compact tests/Unit/Lifecycle/SanitizerTest.php`

Expected: FAIL.

- [ ] **Step 3: Write minimal implementation**

Hash con `password_hash('password', PASSWORD_BCRYPT)`. Pseudonimo: `hash_hmac('sha256', $value, $localKey)` troncato, prefisso `alias_`. Lo scanner cerca email che non finiscono in `.invalid`, numeri `+39` che non sono `+390000000000`, `BEGIN PRIVATE KEY`, `AKIA`.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /var/www/html/backups3 && php artisan test --compact tests/Unit/Lifecycle/SanitizerTest.php && vendor/bin/pint --format agent app/Lifecycle/Sanitizer.php app/Lifecycle/PiiScanner.php tests/Unit/Lifecycle/SanitizerTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git -C /var/www/html/backups3 add app/Lifecycle/Sanitizer.php app/Lifecycle/PiiScanner.php tests/Unit/Lifecycle/SanitizerTest.php
git -C /var/www/html/backups3 commit -m "$(cat <<'EOF'
feat: sanitize clone rows and scan them for leftovers

EOF
)"
```

**Rollback:** revert. Nessun clone è stato pubblicato.

### Task 27: Clone

**Files:**
- Create: `/var/www/html/backups3/app/Console/Commands/LifecycleCloneCommand.php`
- Test: `/var/www/html/backups3/tests/Feature/Console/LifecycleCloneCommandTest.php`

**Interfaces:**
- Consumes: `Sanitizer`, `PiiScanner`, `StateMachine`, `ExecutionGate`, `StatementPlanner`
- Produces: `db:lifecycle:clone`. Pubblica i sei `*_test` solo se lo scanner è vuoto e lo stato è `CLONE_VERIFIED`. Se lo scanner trova un residuo, lo stato è `FAILED_CLONE` e `published` è false. Dry-run non pubblica. I nomi `*_test` compaiono nel piano come destinazione locale, mai come sorgente VPS da contattare.

- [ ] **Step 1: Write the failing test**

Manifest `dev-clone` firmato in memoria. Righe pulite: journal `CLONE_READY` solo con execute e confirm. Righe con email reale: `FAILED_CLONE` e il test legge `published=false` nel report. Dry-run: report assente.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /var/www/html/backups3 && php artisan test --compact tests/Feature/Console/LifecycleCloneCommandTest.php`

Expected: FAIL.

- [ ] **Step 3: Write minimal implementation**

Il comando chiama sanitizer e scanner sulle fixture del test, passate da un `CloneFixture` bindato nel test. Non scarica file. La pubblicazione è un flag nel report, non un `RENAME` eseguito.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /var/www/html/backups3 && php artisan test --compact tests/Feature/Console/LifecycleCloneCommandTest.php && vendor/bin/pint --format agent app/Console/Commands/LifecycleCloneCommand.php tests/Feature/Console/LifecycleCloneCommandTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git -C /var/www/html/backups3 add app/Console/Commands/LifecycleCloneCommand.php tests/Feature/Console/LifecycleCloneCommandTest.php
git -C /var/www/html/backups3 commit -m "$(cat <<'EOF'
feat: publish a dev clone only after the scanner is clean

EOF
)"
```

**Rollback:** revert. I `*_test` esistenti non sono stati toccati.

### Task 28: Approvazioni

**Files:**
- Create: `/var/www/html/backups3/app/Lifecycle/ApprovalGate.php`
- Test: `/var/www/html/backups3/tests/Unit/Lifecycle/ApprovalGateTest.php`

**Interfaces:**
- Consumes: `Manifest` hash via `hash('sha256', $canonical)`
- Produces: `ApprovalGate::assert(string $action, string $requesterId, array $approvals, int $now, string $manifestHash, string $tenant, string $runId): void`. Azioni che richiedono due approvatori: `cutover`, `rollback-after-writes`, `drop-canonical`, `reduce-retention`, `break-lock`, `rotate-signing-key`. Scadenza 14400 secondi. Il richiedente non può essere entrambi. Approvazione di un altro hash o un altro run: rifiuto. Se l'elenco è vuoto, nessuna mutazione: il metodo lancia e il chiamante non esegue SQL.

- [ ] **Step 1: Write the failing test**

Due approvatori diversi, entro 4 ore, stesso hash: passa. Uno solo: lancia. Richiedente ripetuto due volte: lancia. Approvazione a `now+14401`: lancia. Azione `inspect` non richiede approvazioni. BPM assente è rappresentato da lista vuota su `cutover`: lancia, quindi l'assenza di BPM non autorizza.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /var/www/html/backups3 && php artisan test --compact tests/Unit/Lifecycle/ApprovalGateTest.php`

Expected: FAIL.

- [ ] **Step 3: Write minimal implementation**

Controllo puro. Non chiamare HTTP. Il client BPM è fuori da questo task: il comando riceve le approvazioni già nel manifest di fixture.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /var/www/html/backups3 && php artisan test --compact tests/Unit/Lifecycle/ApprovalGateTest.php && vendor/bin/pint --format agent app/Lifecycle/ApprovalGate.php tests/Unit/Lifecycle/ApprovalGateTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git -C /var/www/html/backups3 add app/Lifecycle/ApprovalGate.php tests/Unit/Lifecycle/ApprovalGateTest.php
git -C /var/www/html/backups3 commit -m "$(cat <<'EOF'
feat: require two distinct approvals before a destructive lifecycle action

EOF
)"
```

**Rollback:** revert.

### Task 29: Write fence e cutover

**Files:**
- Create: `/var/www/html/backups3/app/Lifecycle/WriteFence.php`
- Create: `/var/www/html/backups3/app/Console/Commands/LifecycleCutoverCommand.php`
- Test: `/var/www/html/backups3/tests/Feature/Console/LifecycleCutoverCommandTest.php`

**Interfaces:**
- Consumes: `ApprovalGate`, `LifecycleLock`, `StateMachine`, `ExecutionGate`, `InvariantChecker`
- Produces: `WriteFence::engage(array $domains): void`; `release(): void` solo se i sei domini sono `switched` e gli smoke sono verdi; `switchedCount(): int`. `db:lifecycle:cutover`. Se tre domini sono switched e il quarto probe fallisce, lo stato è `FAILED_CUTOVER`, `fence.released` è false, `ACTIVE` non è nel journal. `blue_readonly_until` è `now+7 days`. Il piano include il backup pre-cutover come nome di artefatto, senza crearlo da un dump reale.

- [ ] **Step 1: Write the failing test**

Dry-run: fence non engaged, journal assente. Execute con approvazioni valide e sei probe verdi: journal finisce in `ACTIVE` e il fence risulta rilasciato solo nell'ultima riga. Probe che fallisce al quarto dominio: journal `FAILED_CUTOVER`, `released` false, le righe non contengono `ACTIVE`. Confirm errato: niente journal. Output redactato.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /var/www/html/backups3 && php artisan test --compact tests/Feature/Console/LifecycleCutoverCommandTest.php`

Expected: FAIL.

- [ ] **Step 3: Write minimal implementation**

`WriteFence` tiene un array dei sei domini. `release` lancia se il conteggio non è 6. Il comando usa un `CutoverProbe` fake. Non cambia DNS, non riavvia pool, non legge binlog. Quei passi sono voci del piano stampato (`capture-gtid`, `pre-cutover-backup`, `switch-bundle`, `smoke`) e diventano righe di journal solo nel fake. `GuardedMysqlMutator` non viene chiamato.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /var/www/html/backups3 && php artisan test --compact tests/Feature/Console/LifecycleCutoverCommandTest.php && vendor/bin/pint --format agent app/Lifecycle/WriteFence.php app/Console/Commands/LifecycleCutoverCommand.php tests/Feature/Console/LifecycleCutoverCommandTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git -C /var/www/html/backups3 add app/Lifecycle/WriteFence.php app/Console/Commands/LifecycleCutoverCommand.php tests/Feature/Console/LifecycleCutoverCommandTest.php
git -C /var/www/html/backups3 commit -m "$(cat <<'EOF'
feat: keep the write fence closed unless all six domains switch

EOF
)"
```

**Rollback:** revert. Nessun routing reale è cambiato.

### Task 30: Rollback

**Files:**
- Create: `/var/www/html/backups3/app/Lifecycle/RollbackPlanner.php`
- Create: `/var/www/html/backups3/app/Console/Commands/LifecycleRollbackCommand.php`
- Test: `/var/www/html/backups3/tests/Feature/Console/LifecycleRollbackCommandTest.php`

**Interfaces:**
- Consumes: `WriteFence`, `ApprovalGate`, `StateMachine`
- Produces: `RollbackPlanner::plan(bool $writesReleased, bool $deltaApplicable, bool $preCutoverBackupVerified): RollbackPlan`. Se le scritture non sono ancora state rilasciate, il piano è `switch-back-blue` e lo stato finale del comando, in execute, è `FAILED_CUTOVER` se si interrompe, oppure `ROLLED_BACK` se il probe dei sei domini torna blue. Se il delta non è applicabile e il backup pre-cutover non è verificato, `blocked` true e il fence resta engaged. Il piano non contiene un restore eseguito.

- [ ] **Step 1: Write the failing test**

Tre casi: prima del rilascio, switch-back e fence poi rilasciato solo a sei probe verdi; delta non applicabile senza backup verificato, `blocked` e fence engaged; delta applicabile dopo il rilascio, stato `ROLLED_BACK`. Un probe fermo a tre domini non rilascia il fence.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /var/www/html/backups3 && php artisan test --compact tests/Feature/Console/LifecycleRollbackCommandTest.php`

Expected: FAIL.

- [ ] **Step 3: Write minimal implementation**

Planner puro più comando che journalizza il piano. Nessun client binlog. L'artefatto pre-cutover è un nome nel piano: `pre-cutover/{run_id}.json`, non un file scaricato.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /var/www/html/backups3 && php artisan test --compact tests/Feature/Console/LifecycleRollbackCommandTest.php && vendor/bin/pint --format agent app/Lifecycle/RollbackPlanner.php app/Console/Commands/LifecycleRollbackCommand.php tests/Feature/Console/LifecycleRollbackCommandTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git -C /var/www/html/backups3 add app/Lifecycle/RollbackPlanner.php app/Console/Commands/LifecycleRollbackCommand.php tests/Feature/Console/LifecycleRollbackCommandTest.php
git -C /var/www/html/backups3 commit -m "$(cat <<'EOF'
feat: block rollback when it would drop writes

EOF
)"
```

**Rollback:** revert del commit di codice. Non è un rollback di database.

### Task 31: Transizione API-first

**Files:**
- Create: `/var/www/html/backups3/app/Lifecycle/CrossAppAccess.php`
- Create: `/var/www/html/backups3/app/Lifecycle/ShadowRead.php`
- Test: `/var/www/html/backups3/tests/Unit/Lifecycle/CrossAppAccessTest.php`

**Interfaces:**
- Consumes: `ApprovalGate`, `OwnershipClassifier`
- Produces: `CrossAppAccess::grantStatus(array $census, int $parityDays, int $now): string` ritorna `shadow`, `read-only`, `revoked` o `blocked`. Revoca solo dopo 14 giorni di parità. Riabilitazione read-only: `temporaryUntil` non oltre 72 ore, due approvazioni, scritture ancora `api`. `ShadowRead::compare(array $databaseRows, array $apiRows): bool`. `CrossAppAccess::scan(string $phpSource): list<string>` segnala `DB::connection('core')` dentro un file di unicoloan se la tabella non è dell'owner. Il log del compare non contiene il corpo delle righe, solo conteggi e correlation id.

- [ ] **Step 1: Write the failing test**

Parità 13 giorni: non `revoked`. Parità 14: `revoked` se le approvazioni ci sono. Riabilitazione a 73 ore: `blocked`. `ShadowRead` con una riga diversa: false. Sorgente PHP di fixture `DB::connection('proforma')->table('clientis')` dentro owner `loan`: finding. Blacklist letta da BPM via stringa `Http::get('/api/v1/blacklist')`: nessun finding di scrittura DB.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /var/www/html/backups3 && php artisan test --compact tests/Unit/Lifecycle/CrossAppAccessTest.php`

Expected: FAIL.

- [ ] **Step 3: Write minimal implementation**

Classi pure. Lo scanner è una regex sul sorgente di fixture, non un walk dei repo fratelli. Non modifica unicoloan, proforma, oam, bpm o agent in questo task.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /var/www/html/backups3 && php artisan test --compact tests/Unit/Lifecycle/CrossAppAccessTest.php && vendor/bin/pint --format agent app/Lifecycle/CrossAppAccess.php app/Lifecycle/ShadowRead.php tests/Unit/Lifecycle/CrossAppAccessTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git -C /var/www/html/backups3 add app/Lifecycle/CrossAppAccess.php app/Lifecycle/ShadowRead.php tests/Unit/Lifecycle/CrossAppAccessTest.php
git -C /var/www/html/backups3 commit -m "$(cat <<'EOF'
feat: gate cross-app database access on measured API parity

EOF
)"
```

**Rollback:** revert. Gli accessi DB esistenti non sono stati revocati.

### Task 32: Retention, PITR, audit e Object Lock

**Files:**
- Create: `/var/www/html/backups3/app/Lifecycle/RetentionPolicy.php`
- Create: `/var/www/html/backups3/app/Lifecycle/AuditRecord.php`
- Create: `/var/www/html/backups3/app/Lifecycle/ObjectLockPrecondition.php`
- Test: `/var/www/html/backups3/tests/Unit/Lifecycle/RetentionPolicyTest.php`

**Interfaces:**
- Consumes: `Redactor`, `ApprovalGate`
- Produces: `RetentionPolicy::keep(array $datedKeys, \DateTimeImmutable $now): array` con locali oltre 7 giorni esclusi, e classi `daily` 14, `weekly` 52, `monthly` 36. Binlog oltre 35 giorni esclusi. Manifest oltre 7 anni esclusi. `rpoSeconds` 300, `rtoSeconds` 3600. `AuditRecord::from(array $input): array` senza chiavi segrete. `ObjectLockPrecondition::assert(bool $bucketHasObjectLock, string $phase): void` lancia se la fase è `steady-state` e il flag è false. Non crea bucket. `backup:clean` non si modifica.

- [ ] **Step 1: Write the failing test**

Un file locale di 8 giorni è fuori. Il quindicesimo giornaliero è fuori. Il binlog di 36 giorni è fuori. Il manifest di 8 anni è fuori. Un audit con `password` in input non ha quella chiave in output e ha `actor`, `approvers`, `command`, `manifest_hash`, `fencing_token`, `server_uuid`. Fase `steady-state` con object lock false lancia. Fase `inspect` con object lock false non lancia. Ridurre la retention senza due approvazioni lancia tramite `ApprovalGate`.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /var/www/html/backups3 && php artisan test --compact tests/Unit/Lifecycle/RetentionPolicyTest.php`

Expected: FAIL.

- [ ] **Step 3: Write minimal implementation**

Funzioni pure su elenchi di date. Nessun `aws s3 rm`. Il drill è `RestoreDrill::schedule(): array` con `cadence=weekly`, `copies=synthetic`, `usesProductionDump=false`.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /var/www/html/backups3 && php artisan test --compact tests/Unit/Lifecycle/RetentionPolicyTest.php && vendor/bin/pint --format agent app/Lifecycle/RetentionPolicy.php app/Lifecycle/AuditRecord.php app/Lifecycle/ObjectLockPrecondition.php tests/Unit/Lifecycle/RetentionPolicyTest.php`

Expected: PASS. Poi la regressione stretta dei test di lifecycle, non l'intera suite se include comandi che parlano con R2:

Run: `cd /var/www/html/backups3 && php artisan test --compact tests/Unit/Lifecycle tests/Feature/Console/LifecycleInspectCommandTest.php tests/Feature/Console/LifecycleStatusCommandTest.php tests/Feature/Console/LifecycleBuildCommandTest.php tests/Feature/Console/LifecycleVerifyCommandTest.php tests/Feature/Console/LifecycleCloneCommandTest.php tests/Feature/Console/LifecycleCutoverCommandTest.php tests/Feature/Console/LifecycleRollbackCommandTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git -C /var/www/html/backups3 add app/Lifecycle/RetentionPolicy.php app/Lifecycle/AuditRecord.php app/Lifecycle/ObjectLockPrecondition.php tests/Unit/Lifecycle/RetentionPolicyTest.php
git -C /var/www/html/backups3 commit -m "$(cat <<'EOF'
feat: encode retention, audit, and object-lock rules without deleting backups

EOF
)"
```

**Rollback:** revert. La retention reale di `backup:clean` non è cambiata.

---

## Manual operations

Questi blocchi li esegue una persona, a mano, dopo che i task 1–32 sono verdi. Un agente che implementa il piano non li lancia, non passa `--execute`, e non scarica dump.

### Manual M0: MySQL effimero di PHPUnit

Non è un restore. Crea solo database il cui nome matcha `*_phpunit_{ulid}_{pid}` su `127.0.0.1`, dentro la rete `internal` del compose del task 8.

1. Dry-run mentale: `docker compose -f docker/lifecycle-phpunit/compose.yml config` e controllare che non ci siano `ports` né rete esterna.
2. Avvio locale, solo se si vuole far passare anche le suite applicative: `docker compose -f docker/lifecycle-phpunit/compose.yml up -d`.
3. Rollback: `docker compose -f docker/lifecycle-phpunit/compose.yml down -v`. Non cancella `unico_core_test` sull'istanza di sviluppo, perché quel volume è altro.

### Manual M1: Inspect reale

1. Dry-run, che non scrive:

```bash
php artisan db:lifecycle:inspect --tenant={tenant} --run-id={run_id}
```

2. Leggere skew, object key storici e tabelle in quarantena. Se il gruppo non c'è, fermarsi e produrre un backup nuovo. Non allargare i 15 minuti.
3. Solo dopo due approvazioni e con il flag remoto acceso nel secret store, non nel repo:

```bash
php artisan db:lifecycle:inspect --tenant={tenant} --run-id={run_id} --execute --confirm-run-id={run_id}
```

4. Rollback: cancellare solo il manifest e il journal di quel run. I dump storici restano.

### Manual M2: Build e verify sul green

1. Dry-run di `db:lifecycle:build` e `db:lifecycle:verify`.
2. Execute di build solo sul nodo green, database `*_build_{run-id}`.
3. Execute di verify. Se fallisce, il mutatore di cleanup può distruggere solo i `*_build_{run-id}` di quel run, con un secondo confirm.
4. Rollback: green e build di quel run. Blue intatto.

### Manual M3: Clone di sviluppo

1. Dry-run di `db:lifecycle:clone`.
2. Execute solo se lo scanner del dry-run è vuoto.
3. Rollback: revocare l'utente locale e distruggere i sei `*_test` di quel run nel solo staging. Nessun effetto VPS.

### Manual M4: Cutover

1. Dry-run. Controllare fence, backup pre-cutover, sei domini, due approvatori.
2. Execute con il confirm esatto.
3. Se il comando si ferma prima del rilascio, lasciare il fence e lanciare il dry-run di rollback.
4. Rollback prima delle scritture: switch a blue. Dopo le scritture: Manual M5.

### Manual M5: Rollback dopo le scritture

1. Dry-run di `db:lifecycle:rollback`.
2. Se il piano è `blocked`, non forzare. Resta il fence.
3. Execute solo con delta applicabile oppure backup pre-cutover verificato più replay fino al punto verificato.
4. Un revert Git non riporta i database indietro.

### Manual M6: Steady state

1. Non ridurre la retention finché due drill settimanali sintetici non sono dentro RPO 5 minuti e RTO 60 minuti.
2. Se il bucket non ha Object Lock, fermare la fase 6. Non cancellare il backup precedente.
3. Rollback: tenere il giornaliero precedente.

## Spec coverage

| Sezione | Task |
|---|---|
| 3 Naming, 15 PHPUnit, 16.1 guardia | 1–8 |
| 4 Ownership, 17 mapping, 19 blacklist | 8, 13, 22, 31 |
| 5–7 R2, skew, manifest, firma | 9–12, 20 |
| 8 Multi-tenant | 14 |
| 9 Comandi, 10 stati, 11 lock | 15–21, 23–25, 27, 29, 30 |
| 12 Build e verify | 22–24 |
| 13 Cutover e rollback | 28–30 |
| 14 Clone | 26–27 |
| 16 Guardie | 1, 2, 14, 15, 18, 22 |
| 18 Retention e audit | 32 |
| 20 Fasi 0–6 | 1–32 nell'ordine, Manual M0–M6 |
| 22 Test sicuri | ogni task; niente dump reali |
| `snapshot` non implementato | 15, 20 |
