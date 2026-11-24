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

    public function testFreshBaseMatchesLegacySchemaAndRepeatedRunPreservesRecords(): void
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

        $schema = $db->query('SELECT type,name,sql FROM sqlite_schema ORDER BY name')->fetchAll();
        $this->initializeLegacy();
        $legacy = new PDO('sqlite:'.$this->directory.'/legacy/disc2-dev.sqlite');
        self::assertSame($schema, $legacy->query('SELECT type,name,sql FROM sqlite_schema ORDER BY name')->fetchAll(PDO::FETCH_ASSOC));
    }

    public function testCopiedLegacyDatabaseRetainsEveryLogicalRecord(): void
    {
        $this->copiedFixture();
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
        $db = $this->base();
        foreach (array_slice(self::VERSIONS, 0, 3) as $version) {
            $db->exec(file_get_contents($this->root.'/migrations/'.$version.'.sql'));
            $db->prepare('INSERT OR IGNORE INTO uat_schema_migrations(version) VALUES(?)')->execute([$version]);
        }
        $db->exec("INSERT INTO cases(id) VALUES(7);
            INSERT INTO uat_tracks(id,case_id,track_type,duration_days) VALUES(11,7,'discipline_serious',180);
            INSERT INTO uat_subjects(id,case_id,sequence_no) VALUES(17,7,1);
            INSERT INTO uat_track_subjects(track_id,subject_id) VALUES(11,17);
            INSERT INTO uat_extensions(track_id,sequence_no,requested_days) VALUES(11,1,7);
            INSERT INTO uat_decisions(id,case_id,subject_id,route_type) VALUES(23,7,17,'serious');
            INSERT INTO uat_kk_reports(decision_id,sequence_no,letter_number) VALUES(23,1,'synthetic');");
        $db = $this->connection();
        self::assertSame(0, (new CommandTester(new MigrateCommand($db, $this->root.'/migrations')))->execute([]));
        self::assertSame(['id'=>11,'set_no'=>1,'duration_days'=>180], $db->query('SELECT id,set_no,duration_days FROM uat_tracks')->fetch());
        self::assertSame(['track_id'=>11,'subject_id'=>17,'ended_at'=>null], $db->query('SELECT * FROM uat_track_subjects')->fetch());
        self::assertSame(7, $db->query('SELECT requested_days FROM uat_extensions WHERE track_id=11')->fetchColumn());
        self::assertSame(11, $db->query('SELECT track_id FROM uat_decisions WHERE id=23')->fetchColumn());
        self::assertSame('synthetic', $db->query('SELECT letter_number FROM uat_kk_reports WHERE decision_id=23')->fetchColumn());
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
