<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Domain\Access\AccessAssignment;
use App\Domain\Access\AccessRole;
use App\Domain\Access\CaseScope;
use App\Kernel;
use App\Security\User;
use App\Tests\Integration\SyntheticDatabaseTestCase;
use PDO;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class VoterTest extends SyntheticDatabaseTestCase
{
    public function testSymfonyDecisionsForEveryRoleAndCase(): void
    {
        $this->copiedFixture();
        $db = new PDO('sqlite:'.$this->path, options: [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $db->exec("UPDATE uat_case_meta SET unit_level1='A',unit_level2='G';
            DELETE FROM uat_case_owners;
            INSERT INTO cases(id) VALUES(2),(3);
            INSERT INTO uat_case_meta(case_id,unit_level1,unit_level2) VALUES(2,'A','H'),(3,'B','G');
            INSERT INTO uat_case_owners(case_id,bma_personal_id,owner_name,ended_at) VALUES
            (1,'owner','Owner',NULL),(1,'former','Former','2024-01-01'),(3,'other','Other',NULL)");
        $kernel = new Kernel('test', true);
        $kernel->boot();
        try {
            $services = $kernel->getContainer()->get('test.service_container');
            $services->set(PDO::class, $db);
            $tokens = $services->get('security.token_storage');
            $checker = $services->get('security.authorization_checker');
            // Actor, assignment, session unit, readable cases, writable cases, create, dashboard units.
            $rows = [
                [null, null, '', [], [], false, []],
                ['admin', null, '', [1,2,3], [1,2,3], true, ['A','B']],
                ['super', null, '', [1,2,3], [1,2,3], true, ['A','B']],
                ['exec', 'executive', '', [], [], false, ['A','B']],
                ['head', 'unit_head', '', [], [], false, ['A']],
                ['group', 'group_head', '', [1], [], false, []],
                ['owner', 'case_owner', '', [1], [1], true, []],
                ['former', 'case_owner', '', [], [], true, []],
                ['other', 'case_owner', '', [3], [3], true, []],
                ['unassigned', null, '', [], [], false, []],
                ['owner', null, 'B', [1], [1], true, []],
            ];
            foreach ($rows as [$id, $role, $unit, $readable, $writable, $create, $dashboardUnits]) {
                $assignment = $role ? new AccessAssignment($id, AccessRole::from($role), 'A', 'G') : null;
                $scope = CaseScope::forActor($id, $id === 'admin', $id === 'super', $assignment, $unit);
                $user = new User($scope, 'Synthetic actor', 'demo', true, $unit);
                $tokens->setToken($id ? new UsernamePasswordToken($user, 'main', $user->getRoles()) : null);
                $admin = in_array($id, ['admin', 'super'], true);
                self::assertSame($admin, $checker->isGranted('DISC_ADMIN'), (string)$id);
                self::assertSame($create, $checker->isGranted('CASE_CREATE'), (string)$id);
                self::assertSame($dashboardUnits !== [], $checker->isGranted('DASHBOARD_VIEW'), (string)$id);
                foreach (['A','B'] as $dashboardUnit) {
                    self::assertSame(in_array($dashboardUnit, $dashboardUnits, true), $checker->isGranted('DASHBOARD_UNIT', $dashboardUnit));
                }
                foreach ([1,2,3] as $case) {
                    self::assertSame(in_array($case, $readable, true), $checker->isGranted('CASE_READ', $case), "$id/$case");
                    self::assertSame(in_array($case, $writable, true), $checker->isGranted('CASE_WRITE', $case), "$id/$case");
                    self::assertSame($admin, $checker->isGranted('SUBJECT_IDENTITY_VIEW', $case), "$id/$case");
                }
                self::assertFalse($checker->isGranted('CASE_READ', 0));
                self::assertFalse($checker->isGranted('CASE_WRITE', -1));
                self::assertFalse($checker->isGranted('SUBJECT_IDENTITY_VIEW', null));
            }
        } finally {
            $kernel->shutdown();
        }
    }
}
