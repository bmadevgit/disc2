<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Domain\Access\AccessAssignment;
use App\Domain\Access\AccessRole;

final class AccessAssignmentRepository extends Repository
{
    public function activeFor(string $actorId): ?AccessAssignment
    {
        $row = $this->row('SELECT access_role,unit_level1,unit_level2 FROM uat_access_assignments WHERE bma_personal_id=? AND active=1', [$actorId]);
        return $row ? new AccessAssignment($actorId, AccessRole::from($row['access_role']), $row['unit_level1'], $row['unit_level2']) : null;
    }

    public function all(): array
    {
        return $this->rows('SELECT * FROM uat_access_assignments ORDER BY updated_at DESC');
    }

    public function save(AccessAssignment $assignment, string $displayName): void
    {
        $this->execute('INSERT INTO uat_access_assignments(bma_personal_id,display_name,access_role,unit_level1,unit_level2,active,updated_at)
            VALUES(?,?,?,?,?,?,CURRENT_TIMESTAMP) ON CONFLICT(bma_personal_id) DO UPDATE SET display_name=excluded.display_name,
            access_role=excluded.access_role,unit_level1=excluded.unit_level1,unit_level2=excluded.unit_level2,active=excluded.active,
            updated_at=CURRENT_TIMESTAMP', [$assignment->actorId, $displayName, $assignment->role->value,
            $assignment->unit, $assignment->group, (int)$assignment->active]);
    }
}
