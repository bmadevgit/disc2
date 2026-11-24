<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

final class AuditRepository extends Repository
{
    public function record(int $caseId, string $actorId, string $actorName, string $action, string $detail = ''): void
    {
        $this->execute('INSERT INTO uat_audit_log(case_id,actor_id,actor_name,action,detail) VALUES(?,?,?,?,?)',
            [$caseId, $actorId, $actorName, $action, $detail]);
    }

    public function forCase(int $caseId): array
    {
        return $this->rows('SELECT * FROM uat_audit_log WHERE case_id=? ORDER BY id', [$caseId]);
    }
}
