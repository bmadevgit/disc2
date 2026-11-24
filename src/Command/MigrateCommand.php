<?php

declare(strict_types=1);

namespace App\Command;

use PDO;
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
        $migrations = [];
        foreach ($files as $file) {
            $migrations[basename($file, '.sql')] = $file;
        }
        $applied = [];
        // 004 rebuilds two parent tables. SQLite would cascade their children on DROP
        // with enforcement enabled. foreign_key_check validates the entire batch before commit.
        $rebuild = isset($migrations['004_workflow']) && !$this->workflowRebuildApplied();
        if ($rebuild) {
            $this->db->exec('PRAGMA foreign_keys=OFF');
        }
        try {
            $this->db->beginTransaction();
            $this->db->exec('CREATE TABLE IF NOT EXISTS uat_schema_migrations (version TEXT PRIMARY KEY, applied_at TEXT DEFAULT CURRENT_TIMESTAMP)');
            $this->db->exec('UPDATE uat_schema_migrations SET version=version WHERE 0');
            // A fresh install starts with the legacy base tables from tools/base-schema.sql.
            if (!$this->db->query("SELECT 1 FROM sqlite_schema WHERE type='table' AND name='cases'")->fetchColumn()) {
                throw new RuntimeException('The legacy base schema is missing.');
            }
            $done = $this->db->query('SELECT version FROM uat_schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
            $record = $this->db->prepare('INSERT OR IGNORE INTO uat_schema_migrations(version) VALUES(?)');
            foreach ($migrations as $version => $file) {
                if (in_array($version, $done, true)) {
                    continue;
                }
                $sql = file_get_contents($file);
                if ($sql === false || trim($sql) === '') {
                    throw new RuntimeException('Could not read migration '.$version);
                }
                $this->db->exec($sql);
                $record->execute([$version]);
                $applied[] = $version;
            }
            if ($this->db->query('PRAGMA foreign_key_check')->fetch() !== false) {
                throw new RuntimeException('SQLite foreign-key check failed.');
            }
            if ($this->db->query('PRAGMA integrity_check')->fetchAll(PDO::FETCH_COLUMN) !== ['ok']) {
                throw new RuntimeException('SQLite integrity check failed.');
            }
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $output->writeln('<error>'.$e->getMessage().'</error>');
            return Command::FAILURE;
        } finally {
            $this->db->exec('PRAGMA foreign_keys=ON');
        }
        foreach ($applied as $version) {
            $output->writeln('Applied '.$version);
        }
        $output->writeln('Database integrity verified.');
        return Command::SUCCESS;
    }

    private function workflowRebuildApplied(): bool
    {
        if (!$this->db->query("SELECT 1 FROM sqlite_schema WHERE type='table' AND name='uat_schema_migrations'")->fetchColumn()) {
            return false;
        }
        return (bool)$this->db->query("SELECT 1 FROM uat_schema_migrations WHERE version='004_workflow'")->fetchColumn();
    }
}
