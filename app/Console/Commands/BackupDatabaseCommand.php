<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use PDO;
use Symfony\Component\Console\Command\Command as CommandAlias;

/**
 * spec-077: snapshot the SQLite DB before a migration (run from the deploy step).
 *
 * A bad migration or a column rename on the live in-place SQLite file is the
 * project's biggest data-loss risk — losing external_api_cache + restaurants is
 * a multi-month rebuild gated by the ~250/mo SerpApi quota, not a re-fetch.
 *
 * `VACUUM INTO` produces a transactionally-consistent snapshot while the DB is
 * live (no need to stop the world). Older snapshots are rotated so the disk
 * doesn't fill. Returns SUCCESS even when there's nothing to back up (in-memory
 * DB / file missing) so the deploy never blocks on the safety net.
 */
class BackupDatabaseCommand extends Command
{
    protected $signature = 'db:backup
        {--keep=10 : Number of recent snapshots to retain}
        {--path= : Backup directory (default: storage/backups)}';

    protected $description = 'Snapshot the SQLite DB (VACUUM INTO) with rotation — run before migrations.';

    public function handle(): int
    {
        $dbPath = Config::get('database.connections.sqlite.database');

        if (! is_string($dbPath) || $dbPath === ':memory:' || ! file_exists($dbPath)) {
            $this->warn('SQLite DB is in-memory or not found; nothing to back up.');

            return CommandAlias::SUCCESS;
        }

        $dir = rtrim((string) ($this->option('path') ?: storage_path('backups')), '/');
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            $this->error("Cannot create backup directory: {$dir}");

            return CommandAlias::FAILURE;
        }

        // VACUUM INTO writes a consistent snapshot to a new file (live-safe).
        $backup = "{$dir}/pre-migrate-".time().'.sqlite';

        try {
            $pdo = new PDO('sqlite:'.$dbPath);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec('VACUUM INTO '.$pdo->quote($backup));
            $this->info("DB backup created: {$backup}");
        } catch (\Throwable $e) {
            $this->error('DB backup failed: '.$e->getMessage());

            return CommandAlias::FAILURE;
        }

        $this->rotate($dir, (int) $this->option('keep'));

        return CommandAlias::SUCCESS;
    }

    /**
     * Keep only the N newest pre-migrate snapshots (filenames carry a unix ts).
     */
    private function rotate(string $dir, int $keep): void
    {
        if ($keep < 1) {
            return;
        }

        $existing = glob("{$dir}/pre-migrate-*.sqlite") ?: [];
        sort($existing); // oldest first (names are zero-padded-ish unix timestamps)

        $delete = array_slice($existing, 0, max(0, count($existing) - $keep));
        foreach ($delete as $old) {
            @unlink($old);
        }
    }
}
