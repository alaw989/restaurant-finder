<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use PDO;
use Tests\TestCase;

/**
 * spec-077: the pre-migration DB backup command. `VACUUM INTO` snapshots a
 * file-based SQLite DB (live-consistent) so a bad migration is recoverable —
 * losing external_api_cache is a multi-month rebuild gated by the SerpApi quota.
 */
class BackupDatabaseCommandTest extends TestCase
{
    private string $fileDb;

    private function makeFileDb(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ip360_');
        $pdo = new PDO('sqlite:'.$path);
        $pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY)');
        $pdo->exec('INSERT INTO t VALUES (1), (2), (3)');

        return $path;
    }

    public function test_creates_valid_snapshot_for_file_based_sqlite(): void
    {
        $this->fileDb = $this->makeFileDb();
        Config::set('database.connections.sqlite.database', $this->fileDb);
        $dir = sys_get_temp_dir().'/ip360-backup-'.uniqid();

        $this->artisan('db:backup', ['--path' => $dir, '--keep' => 5])
            ->assertSuccessful();

        $backups = glob($dir.'/pre-migrate-*.sqlite');
        $this->assertCount(1, $backups, 'one snapshot created');

        $snapshot = new PDO('sqlite:'.$backups[0]);
        $this->assertSame(3, (int) $snapshot->query('SELECT COUNT(*) FROM t')->fetchColumn());

        @unlink($this->fileDb);
        array_map('unlink', $backups);
    }

    public function test_skips_gracefully_for_in_memory_db(): void
    {
        Config::set('database.connections.sqlite.database', ':memory:');
        $dir = sys_get_temp_dir().'/ip360-skip-'.uniqid();

        $this->artisan('db:backup', ['--path' => $dir])->assertSuccessful();
        $this->assertFileDoesNotExist($dir, 'no backup created for an in-memory DB');
    }

    public function test_rotates_keeping_only_n_newest(): void
    {
        $this->fileDb = $this->makeFileDb();
        Config::set('database.connections.sqlite.database', $this->fileDb);
        $dir = sys_get_temp_dir().'/ip360-rot-'.uniqid();
        @mkdir($dir, 0775, true);

        // Four pre-existing snapshots with ascending PAST timestamps.
        for ($i = 0; $i < 4; $i++) {
            file_put_contents("{$dir}/pre-migrate-".(time() - (100 - $i)).'.sqlite', 'old');
        }

        $this->artisan('db:backup', ['--path' => $dir, '--keep' => 2])
            ->assertSuccessful();

        $remaining = glob($dir.'/pre-migrate-*.sqlite');
        $this->assertCount(2, $remaining, 'only the 2 newest snapshots are retained');

        @unlink($this->fileDb);
        array_map('unlink', $remaining);
    }
}
