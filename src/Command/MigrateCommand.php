<?php

declare(strict_types=1);

namespace App\Command;

use PDO;
use PDOException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(name: 'app:migrate', description: 'Apply pending DISC2 SQLite migrations and verify database integrity.')]
final class MigrateCommand extends Command
{
    public function __construct(private readonly PDO $db, private readonly string $migrationDirectory)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $files = glob($this->migrationDirectory.'/[0-9][0-9][0-9]_*.sql');
        if (!$files) {
            throw new RuntimeException('No versioned SQL migrations found.');
        }
        $applied = [];
        $started = false;
        try {
            $this->db->exec('PRAGMA foreign_keys=ON');
            // Reserve the writer before even looking at an existing ledger in WAL mode.
            $this->db->exec('BEGIN IMMEDIATE');
            $started = true;
            $this->db->exec('CREATE TABLE IF NOT EXISTS uat_schema_migrations (version TEXT PRIMARY KEY, applied_at TEXT DEFAULT CURRENT_TIMESTAMP)');
            // A fresh install starts with the legacy base tables from tools/base-schema.sql.
            if (!$this->db->query("SELECT 1 FROM sqlite_schema WHERE type='table' AND name='cases'")->fetchColumn()) {
                throw new RuntimeException('The legacy base schema is missing.');
            }
            $done = $this->db->query('SELECT version FROM uat_schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
            $record = $this->db->prepare('INSERT OR IGNORE INTO uat_schema_migrations(version) VALUES(?)');
            foreach ($files as $file) {
                $version = basename($file, '.sql');
                if (in_array($version, $done, true)) {
                    continue;
                }
                $sql = file_get_contents($file);
                if ($sql === false || trim($sql) === '') {
                    throw new RuntimeException('Could not read migration '.$version);
                }
                if ($version === '004_workflow') {
                    $this->rebuildWorkflow($sql);
                } else {
                    $this->db->exec($sql);
                }
                $record->execute([$version]);
                $applied[] = $version;
            }
            if ($this->db->query('PRAGMA foreign_key_check')->fetch() !== false) {
                throw new RuntimeException('SQLite foreign-key check failed.');
            }
            if ($this->db->query('PRAGMA integrity_check')->fetchAll(PDO::FETCH_COLUMN) !== ['ok']) {
                throw new RuntimeException('SQLite integrity check failed.');
            }
            $this->db->exec('COMMIT');
        } catch (Throwable $e) {
            $message = $e->getMessage();
            if ($started) {
                // Raw BEGIN IMMEDIATE is invisible to PDO::inTransaction() on PHP 8.2.
                try {
                    $this->db->exec('ROLLBACK');
                } catch (PDOException $rollbackError) {
                    // SQLite can already have rolled back the whole transaction.
                    if (!str_contains($rollbackError->getMessage(), 'no transaction is active')) {
                        $message .= PHP_EOL.'Rollback failed: '.$rollbackError->getMessage();
                    }
                }
            }
            $output->writeln('<error>'.$message.'</error>');
            return Command::FAILURE;
        }
        foreach ($applied as $version) {
            $output->writeln('Applied '.$version);
        }
        $output->writeln('Database integrity verified.');
        return Command::SUCCESS;
    }

    /**
     * 004 drops tracks and decisions after copying their rows, then renames their
     * replacements. Stage their cascading children in SQLite temp storage so the
     * original SQL can run with foreign keys enabled throughout the transaction.
     */
    private function rebuildWorkflow(string $sql): void
    {
        // Parent-first order restores committee sets before their memberships.
        $children = [
            'uat_track_subjects', 'uat_committee_orders', 'uat_extensions', 'uat_subject_findings',
            'uat_committee_sets', 'uat_committee_set_subjects', 'uat_referrals', 'uat_kk_reports',
        ];
        // Refuse to drop rows from an installation-specific dependent table that
        // this historical migration cannot restore.
        $parents = ['uat_tracks', 'uat_decisions', ...$children];
        $dependencies = $this->db->query("SELECT s.name AS child,f.[table] AS parent FROM sqlite_schema s
            JOIN pragma_foreign_key_list(s.name) f WHERE s.type='table' AND f.on_delete IN ('CASCADE','SET NULL','SET DEFAULT')");
        foreach ($dependencies->fetchAll(PDO::FETCH_ASSOC) as $edge) {
            if (in_array(strtolower($edge['parent']), $parents, true) && !in_array(strtolower($edge['child']), $children, true)) {
                throw new RuntimeException('Unexpected dependency in workflow rebuild: '.$edge['child']);
            }
        }
        foreach ($children as $table) {
            $this->db->exec("CREATE TEMP TABLE saved_$table AS SELECT * FROM main.$table");
        }
        $this->db->exec($sql);
        foreach ($children as $table) {
            // These two tables gained trailing columns with defaults in 004.
            $columns = match ($table) {
                'uat_track_subjects' => '(track_id,subject_id)',
                'uat_subject_findings' => '(id,track_id,subject_id,finding,severity,allegation_detail,incident_detail,action_type,offense_basis)',
                default => '',
            };
            $this->db->exec("INSERT INTO main.$table$columns SELECT * FROM temp.saved_$table");
            $this->db->exec("DROP TABLE temp.saved_$table");
        }
    }
}
