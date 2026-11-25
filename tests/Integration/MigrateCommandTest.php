<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Command\MigrateCommand;
use App\Infrastructure\Persistence\ConnectionFactory;
use PDO;
use Symfony\Component\Console\Tester\CommandTester;

final class MigrateCommandTest extends SyntheticDatabaseTestCase
{
    private const VERSIONS = ['001_uat', '002_comment2', '003_subject_national_id', '004_workflow'];

    public function testFreshBaseAndRepeatedRunPreservesRecords(): void
    {
        $this->base();
        $db = $this->connection();
        $command = new CommandTester(new MigrateCommand($db, $this->root.'/migrations'));
        self::assertSame(0, $command->execute([]));
        self::assertSame(self::VERSIONS, $db->query('SELECT version FROM uat_schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN));
        $rows = $db->query('SELECT * FROM uat_schema_migrations')->fetchAll();
        self::assertSame(0, $command->execute([]));
        self::assertSame($rows, $db->query('SELECT * FROM uat_schema_migrations')->fetchAll());
        self::assertSame('ok', $db->query('PRAGMA integrity_check')->fetchColumn());
        self::assertSame([], $db->query('PRAGMA foreign_key_check')->fetchAll());
    }

    public function testFreshBaseMatchesProceduralSchema(): void
    {
        $root = $this->legacyRoot();
        $this->base();
        $db = $this->connection();
        self::assertSame(0, (new CommandTester(new MigrateCommand($db, $this->root.'/migrations')))->execute([]));
        $schema = $db->query('SELECT type,name,sql FROM sqlite_schema ORDER BY name')->fetchAll();
        $this->initializeFixture($root);
        $legacy = new PDO('sqlite:'.$this->directory.'/legacy/disc2-dev.sqlite');
        self::assertSame($schema, $legacy->query('SELECT type,name,sql FROM sqlite_schema ORDER BY name')->fetchAll(PDO::FETCH_ASSOC));
    }

    public function testCopiedLegacyDatabaseRetainsEveryLogicalRecord(): void
    {
        $this->initializeFixture($this->legacyRoot());
        copy($this->directory.'/legacy/disc2-dev.sqlite', $this->path);
        $db = $this->connection();
        $before = $this->dumpTables($db);
        self::assertSame(0, (new CommandTester(new MigrateCommand($db, $this->root.'/migrations')))->execute([]));
        self::assertSame($before, $this->dumpTables($db));
    }

    public function testInvalidFinalMigrationRollsBackWholeBatchAndRestoresForeignKeys(): void
    {
        $this->base();
        $dir = $this->directory.'/migrations';
        mkdir($dir);
        foreach (glob($this->root.'/migrations/*.sql') as $file) {
            copy($file, $dir.'/'.basename($file));
        }
        file_put_contents($dir.'/005_invalid.sql', "INSERT INTO uat_case_meta(case_id) VALUES(999)");
        $db = $this->connection();
        $before = $this->dumpTables($db);
        $command = new CommandTester(new MigrateCommand($db, $dir));
        self::assertSame(1, $command->execute([]));
        self::assertSame($before, $this->dumpTables($db));
        self::assertSame(1, $db->query('PRAGMA foreign_keys')->fetchColumn());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failingInserts')]
    public function testRawTransactionFailureReleasesWriterAndPreservesOriginalError(string $insert): void
    {
        $this->base();
        $peer = $this->connection();
        $peer->exec('PRAGMA busy_timeout=20');
        // Model the PHP 8.2 PDO driver without changing SQLite's actual transaction state.
        $db = new class('sqlite:'.$this->path) extends PDO {
            public function inTransaction(): bool
            {
                return false;
            }
        };
        $dir = $this->directory.'/migrations';
        mkdir($dir);
        file_put_contents($dir.'/001_partial.sql', 'CREATE TABLE partial_batch(value TEXT UNIQUE)');
        file_put_contents($dir.'/002_failure.sql', "INSERT INTO partial_batch VALUES('one'); $insert");
        $command = new CommandTester(new MigrateCommand($db, $dir));
        self::assertSame(1, $command->execute([]));
        self::assertStringContainsString('UNIQUE constraint failed: partial_batch.value', $command->getDisplay());
        self::assertStringNotContainsString('no transaction is active', $command->getDisplay());
        self::assertSame(0, $db->query("SELECT COUNT(*) FROM sqlite_schema WHERE name IN ('partial_batch','uat_schema_migrations')")->fetchColumn());
        $peer->exec('BEGIN IMMEDIATE');
        $peer->exec('INSERT INTO cases(id) VALUES(17)');
        $peer->exec('COMMIT');
        self::assertSame(17, $db->query('SELECT id FROM cases')->fetchColumn());
    }

    public static function failingInserts(): iterable
    {
        yield 'statement abort leaves transaction open' => ["INSERT INTO partial_batch VALUES('one')"];
        yield 'SQLite already rolled back' => ["INSERT OR ROLLBACK INTO partial_batch VALUES('one')"];
    }

    public function testAlreadyMigratedDatabaseWithBrokenForeignKeyFailsChecks(): void
    {
        $this->copiedFixture();
        $db = $this->connection();
        $db->exec('PRAGMA foreign_keys=OFF');
        $db->exec('INSERT INTO uat_case_meta(case_id) VALUES(999)');
        $db->exec('PRAGMA foreign_keys=ON');
        self::assertSame(1, (new CommandTester(new MigrateCommand($db, $this->root.'/migrations')))->execute([]));
    }

    public function testPopulatedPreWorkflowDatabaseRetainsChildrenWhenParentsAreRebuilt(): void
    {
        $db = $this->preWorkflowDatabase();
        $db->exec("INSERT INTO cases(id) VALUES(7);
            INSERT INTO uat_tracks(id,case_id,track_type,duration_days) VALUES(11,7,'discipline_serious',180);
            INSERT INTO uat_subjects(id,case_id,sequence_no) VALUES(17,7,1);
            INSERT INTO uat_track_subjects(track_id,subject_id) VALUES(11,17);
            INSERT INTO uat_extensions(track_id,sequence_no,requested_days) VALUES(11,1,7);
            INSERT INTO uat_committee_orders(track_id,event_type,order_number) VALUES(11,'appoint','test order');
            INSERT INTO uat_subject_findings(track_id,subject_id,finding,incident_detail,offense_basis)
                VALUES(11,17,'guilty','historical incident','original basis');
            INSERT INTO uat_referrals(track_id,subject_id,note) VALUES(11,17,'historical referral');
            INSERT INTO uat_committee_sets(id,track_id,set_code) VALUES(19,11,'previous');
            INSERT INTO uat_committee_set_subjects(committee_set_id,subject_id,ended_at) VALUES(19,17,'old');
            INSERT INTO uat_decisions(id,case_id,subject_id,route_type) VALUES(23,7,17,'serious');
            INSERT INTO uat_kk_reports(decision_id,sequence_no,letter_number) VALUES(23,1,'synthetic');");
        $db = $this->connection();
        self::assertSame(0, (new CommandTester(new MigrateCommand($db, $this->root.'/migrations')))->execute([]));
        self::assertSame(['id'=>11,'set_no'=>1,'duration_days'=>180], $db->query('SELECT id,set_no,duration_days FROM uat_tracks')->fetch());
        self::assertSame(['track_id'=>11,'subject_id'=>17,'ended_at'=>null], $db->query('SELECT * FROM uat_track_subjects')->fetch());
        self::assertSame(7, $db->query('SELECT requested_days FROM uat_extensions WHERE track_id=11')->fetchColumn());
        self::assertSame('test order', $db->query('SELECT order_number FROM uat_committee_orders WHERE track_id=11')->fetchColumn());
        self::assertSame(['incident_detail'=>'historical incident','offense_basis'=>'original basis','recommend_serious'=>0],
            $db->query('SELECT incident_detail,offense_basis,recommend_serious FROM uat_subject_findings WHERE subject_id=17')->fetch());
        self::assertSame('historical referral', $db->query('SELECT note FROM uat_referrals WHERE track_id=11')->fetchColumn());
        self::assertSame('previous', $db->query('SELECT set_code FROM uat_committee_sets WHERE id=19')->fetchColumn());
        self::assertSame('old', $db->query('SELECT ended_at FROM uat_committee_set_subjects WHERE committee_set_id=19')->fetchColumn());
        self::assertSame(11, $db->query('SELECT track_id FROM uat_decisions WHERE id=23')->fetchColumn());
        self::assertSame('synthetic', $db->query('SELECT letter_number FROM uat_kk_reports WHERE decision_id=23')->fetchColumn());
    }

    public function testRebuildPreservesDeletedAutoincrementHighWaterMarks(): void
    {
        $db = $this->preWorkflowDatabase();
        $db->exec("INSERT INTO cases(id) VALUES(7);
            INSERT INTO uat_subjects(id,case_id,sequence_no) VALUES(17,7,1);
            INSERT INTO uat_tracks(id,case_id,track_type) VALUES(11,7,'discipline_serious'),(90,7,'fact');
            INSERT INTO uat_decisions(id,case_id,subject_id,route_type) VALUES(120,7,17,'serious');
            DELETE FROM uat_tracks WHERE id=90; DELETE FROM uat_decisions WHERE id=120");
        $command = new CommandTester(new MigrateCommand($db, $this->root.'/migrations'));
        self::assertSame(0, $command->execute([]), $command->getDisplay());
        $db->exec("INSERT INTO uat_tracks(case_id,track_type) VALUES(7,'fact')");
        self::assertSame('91', $db->lastInsertId());
        $db->exec("INSERT INTO uat_decisions(case_id,subject_id,route_type,track_id) VALUES(7,17,'serious',11)");
        self::assertSame('121', $db->lastInsertId());
    }

    public function testUpgradeFrom003UsesNormalCascadesInFollowingVersion(): void
    {
        $db = $this->preWorkflowDatabase();
        $db->exec("INSERT INTO cases(id) VALUES(7);
            INSERT INTO uat_tracks(id,case_id,track_type) VALUES(11,7,'fact');
            INSERT INTO uat_subjects(id,case_id,sequence_no) VALUES(17,7,1);
            INSERT INTO uat_track_subjects(track_id,subject_id) VALUES(11,17);
            INSERT INTO uat_subject_status_history(subject_id,status_name) VALUES(17,'fixture')");
        $dir = $this->directory.'/migrations';
        mkdir($dir);
        copy($this->root.'/migrations/004_workflow.sql', $dir.'/004_workflow.sql');
        file_put_contents($dir.'/005_fixture.sql', 'DELETE FROM uat_subjects WHERE id=17');
        $db = $this->connection();
        $command = new CommandTester(new MigrateCommand($db, $dir));
        self::assertSame(0, $command->execute([]), $command->getDisplay());
        self::assertSame(0, $db->query('SELECT COUNT(*) FROM uat_subject_status_history')->fetchColumn());
        self::assertSame(0, $db->query('SELECT COUNT(*) FROM uat_track_subjects')->fetchColumn());
        self::assertSame('005_fixture', $db->query('SELECT MAX(version) FROM uat_schema_migrations')->fetchColumn());
    }

    public function testMigratorReservesWriterBeforeReadingExistingLedger(): void
    {
        $this->copiedFixture();
        $peer = $this->connection();
        $peer->exec('PRAGMA busy_timeout=1');
        $dir = $this->directory.'/migrations';
        mkdir($dir);
        file_put_contents($dir.'/005_fixture.sql', 'CREATE TABLE arrived_once(id INTEGER)');
        $competing = new CommandTester(new MigrateCommand($peer, $dir));
        // Interleave two real connections deterministically immediately after ledger discovery.
        $first = new class('sqlite:'.$this->path) extends PDO {
            public \Closure $atLedger;
            public function exec(string $statement): int|false
            {
                $result = parent::exec($statement);
                if (str_starts_with($statement, 'CREATE TABLE IF NOT EXISTS uat_schema_migrations')) {
                    ($this->atLedger)();
                }
                return $result;
            }
        };
        $first->exec('PRAGMA foreign_keys=ON');
        $competingStatus = null;
        $first->atLedger = static function () use ($competing, &$competingStatus): void {
            $competingStatus = $competing->execute([]);
        };
        $owner = new CommandTester(new MigrateCommand($first, $dir));
        self::assertSame(0, $owner->execute([]), $owner->getDisplay());
        self::assertSame(1, $competingStatus);
        self::assertStringContainsString('locked', $competing->getDisplay());
        self::assertSame(0, $competing->execute([]));
        self::assertStringNotContainsString('Applied', $competing->getDisplay());
        self::assertSame(1, $peer->query("SELECT COUNT(*) FROM uat_schema_migrations WHERE version='005_fixture'")->fetchColumn());
    }

    public function testUnexpectedCascadingDependencyCannotLoseExistingRowsDuringRebuild(): void
    {
        $db = $this->preWorkflowDatabase();
        $db->exec("INSERT INTO cases(id) VALUES(1);
            INSERT INTO uat_tracks(id,case_id,track_type) VALUES(1,1,'fact');
            CREATE TABLE extra_records(track_id INTEGER REFERENCES uat_tracks(id) ON DELETE CASCADE);
            INSERT INTO extra_records VALUES(1)");
        $db = $this->connection();
        $before = $this->dumpTables($db);
        $command = new CommandTester(new MigrateCommand($db, $this->root.'/migrations'));
        self::assertSame(1, $command->execute([]));
        self::assertStringContainsString('extra_records', $command->getDisplay());
        self::assertSame($before, $this->dumpTables($db));
    }

    public function testFutureVersionUsesForeignKeyActionsOnAlreadyRebuiltDatabase(): void
    {
        $this->copiedFixture();
        $db = $this->connection();
        $dir = $this->directory.'/migrations';
        mkdir($dir);
        file_put_contents($dir.'/005_fixture.sql', 'DELETE FROM uat_subjects WHERE id=1');
        $db->exec("INSERT INTO uat_subject_status_history(subject_id,status_name) VALUES(1,'fixture')");
        $command = new CommandTester(new MigrateCommand($db, $dir));
        self::assertSame(0, $command->execute([]));
        self::assertSame(0, $db->query('SELECT COUNT(*) FROM uat_subject_status_history')->fetchColumn());
    }

    public function testIntegrityFailureWithValidForeignKeysRollsBackPendingVersion(): void
    {
        $this->copiedFixture();
        $db = $this->connection();
        $db->exec('PRAGMA ignore_check_constraints=ON');
        $db->exec("INSERT INTO uat_access_assignments(bma_personal_id,access_role) VALUES('broken','broken')");
        $db->exec('PRAGMA ignore_check_constraints=OFF');
        $dir = $this->directory.'/migrations';
        mkdir($dir);
        file_put_contents($dir.'/005_fixture.sql', 'CREATE TABLE synthetic_rollback(id INTEGER)');
        $command = new CommandTester(new MigrateCommand($db, $dir));
        self::assertSame(1, $command->execute([]));
        self::assertStringContainsString('integrity check failed', $command->getDisplay());
        self::assertFalse($db->query("SELECT 1 FROM sqlite_schema WHERE name='synthetic_rollback'")->fetchColumn());
    }

    public function testConsoleUsesExternalProductionFile(): void
    {
        $db = $this->base();
        $config = $this->directory.'/production-config.php';
        file_put_contents($config, '<?php return '.var_export(['database_path'=>$this->path], true).';');
        $process = proc_open([PHP_BINARY, $this->root.'/bin/console', 'app:migrate', '--env=prod', '--no-debug'],
            [1=>['pipe', 'w'], 2=>['redirect', 1]], $pipes, $this->root,
            ['APP_ENV'=>'prod', 'APP_SECRET'=>'synthetic-cli-test', 'DISC2_CONFIG_FILE'=>$config]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        self::assertSame(0, proc_close($process), $output);
        self::assertStringContainsString('Applied 004_workflow', $output);
        self::assertSame(self::VERSIONS, $db->query('SELECT version FROM uat_schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN));
    }

    private function preWorkflowDatabase(): PDO
    {
        $db = $this->base();
        foreach (array_slice(self::VERSIONS, 0, 3) as $version) {
            $db->exec(file_get_contents($this->root.'/migrations/'.$version.'.sql'));
            $db->prepare('INSERT OR IGNORE INTO uat_schema_migrations(version) VALUES(?)')->execute([$version]);
        }
        return $db;
    }

    private function connection(): PDO
    {
        return (new ConnectionFactory($this->directory, 'test'))->open();
    }

    private function dumpTables(PDO $db): array
    {
        $rows = [];
        foreach ($db->query("SELECT name FROM sqlite_schema WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $rows[$table] = $db->query('SELECT * FROM "'.str_replace('"', '""', $table).'" ORDER BY rowid')->fetchAll();
        }
        return $rows;
    }
}
