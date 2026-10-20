<?php

namespace Tests\Feature\Backups;

use App\Models\BackupDb;
use App\Models\BackupDbFile;
use App\Services\DumpChangeDetector;
use RuntimeException;

class DumpChangeDetectorTest extends BackupDbTestCase
{
    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = sys_get_temp_dir().'/dump-change-'.uniqid();
        mkdir($this->folder);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->folder.'/*'));
        rmdir($this->folder);

        parent::tearDown();
    }

    private function dump(string $name, string $body, string $completedOn = '2026-10-20 02:00:01'): string
    {
        $file = "{$this->folder}/{$name}.sql.gz";
        file_put_contents($file, gzencode("-- MySQL dump 10.13\n{$body}\n-- Dump completed on {$completedOn}\n", 9, FORCE_GZIP));

        return $file;
    }

    public function test_the_dump_date_does_not_change_the_hash_but_the_content_does(): void
    {
        $detector = new DumpChangeDetector;

        $first = $detector->hash($this->dump('a', 'INSERT INTO t VALUES (1);', '2026-10-20 02:00:01'));
        $later = $detector->hash($this->dump('b', 'INSERT INTO t VALUES (1);', '2026-10-21 02:00:09'));
        $changed = $detector->hash($this->dump('c', 'INSERT INTO t VALUES (2);'));

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first);
        $this->assertSame($first, $later);
        $this->assertNotSame($first, $changed);
    }

    public function test_a_corrupt_file_is_an_error_not_an_empty_hash(): void
    {
        file_put_contents($file = "{$this->folder}/rotto.sql.gz", 'non è un gzip');

        $this->expectException(RuntimeException::class);

        (new DumpChangeDetector)->hash($file);
    }

    public function test_unchanged_only_when_enabled_hash_equal_and_a_backup_exists_on_r2(): void
    {
        $detector = new DumpChangeDetector;
        $backupDb = BackupDb::query()->where('database_name', 'alfa')->firstOrFail();

        // Mai salvato: va salvato.
        $this->assertFalse($detector->isUnchanged($backupDb, 'abc'));
        $this->assertFalse($detector->isUnchanged(null, 'abc'));

        // Hash uguale ma su R2 non risulta nessun backup (tolto dalla retention o mai arrivato): si salva comunque.
        $backupDb->update(['last_dump_hash' => 'abc']);
        $this->assertFalse($detector->isUnchanged($backupDb->fresh(), 'abc'));

        BackupDbFile::query()->create(['backupdb_id' => $backupDb->id, 'r2_key' => 'k', 'backed_up_at' => now(), 'size_bytes' => 1]);
        $this->assertTrue($detector->isUnchanged($backupDb->fresh(), 'abc'));
        $this->assertFalse($detector->isUnchanged($backupDb->fresh(), 'diverso'));

        // Con l'opzione spenta il dump si salva sempre.
        $backupDb->update(['only_if_changed' => false]);
        $this->assertFalse($detector->isUnchanged($backupDb->fresh(), 'abc'));
    }
}
