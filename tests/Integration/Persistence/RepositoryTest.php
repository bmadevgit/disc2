<?php

declare(strict_types=1);

namespace App\Tests\Integration\Persistence;

use App\Domain\Access\AccessAssignment;
use App\Domain\Access\AccessRole;
use App\Domain\Access\CaseScope;
use App\Infrastructure\Persistence\ConnectionFactory;
use App\Infrastructure\Persistence\Repository\AccessAssignmentRepository;
use App\Infrastructure\Persistence\Repository\AuditRepository;
use App\Infrastructure\Persistence\Repository\CaseRepository;
use App\Infrastructure\Persistence\Repository\SubjectRepository;
use App\Infrastructure\Persistence\Repository\UserRepository;
use App\Infrastructure\Persistence\TransactionManager;
use App\Tests\Integration\SyntheticDatabaseTestCase;
use RuntimeException;

final class RepositoryTest extends SyntheticDatabaseTestCase
{
    public function testConfiguredConnectionsAndMissingProductionDatabase(): void
    {
        $this->base();
        foreach (['test', 'dev'] as $environment) {
            $db = (new ConnectionFactory($this->directory, $environment))->open();
            self::assertSame(1, $db->query('PRAGMA foreign_keys')->fetchColumn());
            self::assertSame('wal', $db->query('PRAGMA journal_mode')->fetchColumn());
            self::assertSame(5000, $db->query('PRAGMA busy_timeout')->fetchColumn());
        }
        $config = $this->directory.'/external.php';
        file_put_contents($config, '<?php return '.var_export(['database_path' => $this->path], true).';');
        $db = (new ConnectionFactory($this->directory, 'prod', $config))->open();
        self::assertSame($this->path, $db->query('PRAGMA database_list')->fetch()['file']);
        file_put_contents($config, '<?php return '.var_export(['database_path' => $this->directory.'/missing.db'], true).';');
        try {
            (new ConnectionFactory($this->directory, 'prod', $config))->open();
            self::fail('Missing database should fail closed.');
        } catch (RuntimeException) {
            self::assertFileDoesNotExist($this->directory.'/missing.db');
        }
    }

    public function testProductionRejectsDatabaseUnderPublicRoot(): void
    {
        $this->base();
        mkdir($this->directory.'/public');
        $file = $this->directory.'/public/exposed.db';
        copy($this->path, $file);
        $config = $this->directory.'/external.php';
        file_put_contents($config, '<?php return '.var_export(['database_path'=>$file], true).';');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('outside the public directory');
        (new ConnectionFactory($this->directory, 'prod', $config))->open();
    }

    public function testLoginLookupCutoffAndUnitAssignmentCompatibility(): void
    {
        $this->copiedFixture();
        $db = (new ConnectionFactory($this->directory, 'test'))->open();
        $users = new UserRepository($db);
        self::assertNull($users->byUsername('demo-owner', true));
        self::assertSame('admin', $users->byUsername('demo-admin', true)['role']);
        foreach (['fail', 'success', 'fail'] as $status) {
            $users->logLogin('synthetic', '', '', '127.0.0.1', str_repeat('a', 510), $status);
        }
        $db->exec("UPDATE login_log SET created_at='2000-01-01 00:00:00' WHERE id=1");
        self::assertSame(1, $users->failuresSince('127.0.0.1', '2001-01-01 00:00:00'));
        self::assertSame(0, $users->failuresSince('127.0.0.2', '2001-01-01 00:00:00'));
        self::assertSame(500, $db->query('SELECT LENGTH(user_agent) FROM login_log LIMIT 1')->fetchColumn());
        $users->assignInitialUnit('synthetic', 'demo@example.invalid', 'old');
        $users->assignInitialUnit('synthetic', 'demo@example.invalid', 'new');
        self::assertSame('old', $users->unitFor('synthetic'));
    }

    public function testWriterLockPrecedesCallbackAndIsReleasedAfterCommit(): void
    {
        $this->copiedFixture();
        $factory = new ConnectionFactory($this->directory, 'test');
        $writer = $factory->open();
        $second = $factory->open();
        $second->exec('PRAGMA busy_timeout=1');
        (new TransactionManager($writer))->run(function () use ($second): void {
            try {
                $second->exec("UPDATE uat_case_meta SET stage='concurrent'");
                self::fail('Expected the writer to hold the lock before any callback writes.');
            } catch (\PDOException $e) {
                self::assertSame(5, $e->errorInfo[1]); // SQLITE_BUSY
            }
        });
        self::assertSame(1, $second->exec("UPDATE uat_case_meta SET stage='concurrent'"));
    }

    public function testSubjectUpdatesPreserveStoredIdentityAndRecordOnlyStatusChanges(): void
    {
        $this->copiedFixture();
        $db = (new ConnectionFactory($this->directory, 'test'))->open();
        $subjects = new SubjectRepository($db);
        $fields = ['title_th'=>'', 'first_name_th'=>'Synthetic', 'last_name_th'=>'',
            'position_name'=>'', 'employment_type'=>'', 'national_id'=>'synthetic-id'];
        $id = $subjects->save(1, 0, 3, $fields);
        $fields['national_id'] = '';
        self::assertSame($id, $subjects->save(1, $id, 3, $fields));
        $subjects->recordStatus($id, 'first', '', '');
        $subjects->recordStatus($id, 'first', '', '');
        $subjects->recordStatus($id, 'second', '2020-01-02', 'update');
        $row = $subjects->activeForCase(1)[2];
        self::assertSame('REF-1-3', $row['reference_code']);
        self::assertSame('synthetic-id', $row['national_id']);
        self::assertSame('second', $row['current_status']);
        self::assertSame(['second', 'first'], array_column($subjects->statusHistory($id), 'status_name'));
    }

    public function testCopiedFixtureScopesOwnersAndAuditRollback(): void
    {
        $this->copiedFixture();
        $db = (new ConnectionFactory($this->directory, 'test'))->open();
        $users = new UserRepository($db);
        $assignments = new AccessAssignmentRepository($db);
        $cases = new CaseRepository($db);
        $owner = (string)$users->byUsername('demo-owner')['id'];
        $reader = (string)$users->byUsername('demo-reader')['id'];
        $scope = CaseScope::forActor($owner, assignment: $assignments->activeFor($owner));
        $group = CaseScope::forActor($reader, assignment: $assignments->activeFor($reader));
        $case = $cases->visibleTo($scope)[0];
        $id = $case['id'];
        self::assertSame('DEMO-001', $case['เลขที่สำนวน']);
        self::assertSame([$id], array_column($cases->visibleTo($group), 'id'));
        self::assertTrue($scope->canWrite($cases->access($id)));
        self::assertSame(['REF-DEMO-1','REF-DEMO-2'], array_column((new SubjectRepository($db))->activeForCase($id), 'reference_code'));
        self::assertNull($users->byUsername("' OR 1=1 --"));
        $db->exec('UPDATE uat_case_owners SET ended_at=CURRENT_TIMESTAMP');
        self::assertSame([], $cases->visibleTo($scope));
        self::assertFalse($scope->canWrite($cases->access($id)));
        self::assertSame([], $cases->visibleTo(CaseScope::forActor(null)));

        $withoutMeta = $cases->create('legacy', '', $owner, 'synthetic');
        $emptyGroup = CaseScope::forActor($reader, assignment: new AccessAssignment(
            $reader, AccessRole::GroupHead, '', ''
        ));
        self::assertFalse($emptyGroup->canRead($cases->access($withoutMeta)));
        self::assertSame([], $cases->visibleTo($emptyGroup));

        self::assertSame(['total'=>2, 'closed'=>0, 'discipline'=>0, 'subjects'=>2],
            $cases->dashboardCounts(CaseScope::forActor('admin', true)));
        $head = CaseScope::forActor($reader, assignment: new AccessAssignment(
            $reader, AccessRole::UnitHead, $scope->unit, ''
        ));
        self::assertSame(['total'=>1, 'closed'=>0, 'discipline'=>0, 'subjects'=>2], $cases->dashboardCounts($head));

        $tx = new TransactionManager($db);
        $audit = new AuditRepository($db);
        $write = function () use ($cases, $id, $audit, $owner): int {
            $cases->setStage($id, 'closed', '2024-05-06');
            $audit->record($id, $owner, 'Synthetic owner', 'close', 'synthetic reason');
            return $id;
        };
        try {
            $tx->run(function () use ($write): void {
                $write();
                throw new RuntimeException('synthetic failure');
            });
            self::fail('Expected transaction failure.');
        } catch (RuntimeException $e) {
            self::assertSame('synthetic failure', $e->getMessage());
        }
        self::assertSame('case_info', $cases->find($id)['stage']);
        self::assertSame([], $audit->forCase($id));
        self::assertSame($id, $tx->run($write));
        self::assertSame('closed', $cases->find($id)['stage']);
        self::assertSame('synthetic reason', $audit->forCase($id)[0]['detail']);
    }
}
