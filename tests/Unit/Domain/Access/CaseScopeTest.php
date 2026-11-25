<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Access;

use App\Domain\Access\AccessAssignment;
use App\Domain\Access\AccessRole;
use App\Domain\Access\CaseScope;
use App\Domain\Access\SubjectIdentityPolicy;
use App\Domain\CaseFile\CaseAccess;
use App\Domain\CaseFile\SubjectIdentity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CaseScopeTest extends TestCase
{
    public static function actors(): iterable
    {
        // Role, allowed detail/list cases, dashboard units, create, edit DEMO-001, administrator.
        yield 'anonymous' => [null, null, false, false, '', 'anonymous', [], [], false, false, false];
        yield 'admin session overrides assignment' => ['admin', 'group_head', true, false, '', 'admin', [1,2,3], ['A','B'], true, true, true];
        yield 'configured super-admin' => ['chief', null, false, true, '', 'admin', [1,2,3], ['A','B'], true, true, true];
        yield 'executive' => ['exec', 'executive', false, false, '', 'executive', [], ['A','B'], false, false, false];
        yield 'unit head' => ['head', 'unit_head', false, false, '', 'unit_head', [], ['A'], false, false, false];
        yield 'group head' => ['group', 'group_head', false, false, '', 'group_head', [1], [], false, false, false];
        yield 'case owner' => ['owner', 'case_owner', false, false, '', 'case_owner', [1], [], true, true, false];
        yield 'ended owner' => ['former', 'case_owner', false, false, '', 'case_owner', [], [], true, false, false];
        yield 'unrelated owner' => ['peer', 'case_owner', false, false, '', 'case_owner', [3], [], true, false, false];
        yield 'unassigned' => ['unused', null, false, false, '', 'none', [], [], false, false, false];
        yield 'unassigned with session unit' => ['owner', null, false, false, 'B', 'case_owner', [1], [], true, true, false];
    }

    #[DataProvider('actors')]
    public function testRoleMatrix(?string $actor, ?string $role, bool $adminSession, bool $superAdmin,
        string $sessionUnit, string $effectiveRole, array $readable, array $dashboardUnits,
        bool $create, bool $edit, bool $admin): void
    {
        $assignment = $role ? new AccessAssignment($actor, AccessRole::from($role), 'A', 'G') : null;
        $scope = CaseScope::forActor($actor, $adminSession, $superAdmin, $assignment, $sessionUnit);
        $cases = [
            new CaseAccess(1, 'A', 'G', [
                ['bma_personal_id' => 'owner', 'ended_at' => null],
                ['bma_personal_id' => 'former', 'ended_at' => '2024-01-01'],
            ]),
            new CaseAccess(2, 'A', 'H', []),
            new CaseAccess(3, 'B', 'G', [['bma_personal_id' => 'peer', 'ended_at' => null]]),
        ];
        self::assertSame($effectiveRole, $scope->role->value);
        self::assertSame($create, $scope->canCreate());
        self::assertSame($edit, $scope->canWrite($cases[0]));
        self::assertSame($admin, $scope->canAdminister());
        foreach ($cases as $case) {
            self::assertSame(in_array($case->id, $readable, true), $scope->canRead($case));
            self::assertSame(in_array($case->unit, $dashboardUnits, true), $scope->canViewDashboardUnit($case->unit));
        }

        $identity = new SubjectIdentity(1, 1, 2, 'REF-DEMO-1', '', 'บุคคลสมมติ', 'ก');
        $policy = new SubjectIdentityPolicy();
        $read = in_array(1, $readable, true);
        self::assertSame($admin ? 'บุคคลสมมติ ก' : ($read ? 'REF-DEMO-1' : null), $policy->label($scope, $cases[0], $identity));
        self::assertSame($admin, $policy->matches($scope, $cases[0], $identity, 'บุคคลสมมติ ก'));
        self::assertSame($read, $policy->matches($scope, $cases[0], $identity, 'ref-demo-1'));

        $unnamed = new SubjectIdentity(1, 1, 2, '', '', '', '');
        self::assertSame($read ? 'ผู้ถูกกล่าวหารายที่ 2' : null, $policy->label($scope, $cases[0], $unnamed));
        $noSequence = new SubjectIdentity(1, 11, null, 'REF', '', '', '');
        self::assertSame($read ? 'REF' : null, $policy->label($scope, $cases[0], $noSequence));
        $noRef = new SubjectIdentity(1, 11, null, '', '', '', '');
        self::assertSame($read ? 'ผู้ถูกกล่าวหารายที่ 11' : null, $policy->label($scope, $cases[0], $noRef));
        self::assertNull($policy->label($scope, $cases[1], $identity));
        self::assertFalse($policy->matches($scope, $cases[1], $identity, 'REF-DEMO-1'));
    }

    public function testInactiveAndMismatchedAssignmentsAreExcluded(): void
    {
        $inactive = new AccessAssignment('actor', AccessRole::Executive, '', '', active: false);
        self::assertSame(AccessRole::None, CaseScope::forActor('actor', assignment: $inactive)->role);
        self::assertSame(AccessRole::CaseOwner, CaseScope::forActor('actor', assignment: $inactive, sessionUnit: 'A')->role);
        $other = new AccessAssignment('other', AccessRole::Executive, '', '');
        self::assertSame(AccessRole::None, CaseScope::forActor('actor', assignment: $other)->role);
        self::assertSame(AccessRole::Anonymous, CaseScope::forActor(null, true, true, $other, 'A')->role);
    }

    public function testOwnershipMustExplicitlyBeActive(): void
    {
        $scope = CaseScope::forActor('owner', sessionUnit: 'A');
        $case = new CaseAccess(1, 'A', 'G', [['bma_personal_id' => 'owner']]);
        self::assertFalse($scope->canRead($case));
        self::assertFalse($scope->canWrite($case));
    }

    public function testAssignmentCannotConferAdministrativeIdentityAccess(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AccessAssignment('owner', AccessRole::Admin, '', '');
    }
}
