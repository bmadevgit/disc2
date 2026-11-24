<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Domain\Access\AccessRole;
use App\Domain\Access\CaseScope;
use App\Domain\CaseFile\CaseAccess;

final class CaseRepository extends Repository
{
    public function visibleTo(CaseScope $scope): array
    {
        [$where, $values] = match ($scope->role) {
            AccessRole::Admin => ['', []],
            AccessRole::CaseOwner => ['WHERE EXISTS (SELECT 1 FROM uat_case_owners o
                WHERE o.case_id=c.id AND o.bma_personal_id=? AND o.ended_at IS NULL)', [$scope->actorId]],
            AccessRole::GroupHead => ['WHERE m.unit_level1=? AND m.unit_level2=?', [$scope->unit, $scope->group]],
            default => ['WHERE 0', []],
        };
        return $this->rows("SELECT c.id,c.เลขที่สำนวน,c.created_at,
            COALESCE(NULLIF(m.responsible_unit,''),c.หน่วยงานต้นเรื่อง,'') AS unit,
            COALESCE(NULLIF(m.stage,''),'legacy') AS stage,COALESCE(m.case_type,'') AS case_type
            FROM cases c LEFT JOIN uat_case_meta m ON m.case_id=c.id $where ORDER BY c.id DESC", $values);
    }

    public function dashboardCounts(CaseScope $scope): array
    {
        [$where, $values] = match ($scope->role) {
            AccessRole::Admin, AccessRole::Executive => ['', []],
            AccessRole::UnitHead => ['WHERE m.unit_level1=?', [$scope->unit]],
            default => ['WHERE 0', []],
        };
        $counts = $this->row("SELECT COUNT(*) total,SUM(CASE WHEN m.stage='closed' THEN 1 ELSE 0 END) closed,
            SUM(CASE WHEN m.stage='discipline' THEN 1 ELSE 0 END) discipline
            FROM cases c LEFT JOIN uat_case_meta m ON m.case_id=c.id $where", $values);
        $counts['subjects'] = (int)$this->execute("SELECT COUNT(DISTINCT s.id) FROM uat_subjects s
            JOIN uat_case_meta m ON m.case_id=s.case_id $where", $values)->fetchColumn();
        return $counts;
    }

    public function find(int $id): ?array
    {
        return $this->row('SELECT c.*,m.* FROM cases c LEFT JOIN uat_case_meta m ON m.case_id=c.id WHERE c.id=?', [$id]);
    }

    public function access(int $id): ?CaseAccess
    {
        $case = $this->row('SELECT c.id,m.unit_level1,m.unit_level2 FROM cases c LEFT JOIN uat_case_meta m ON m.case_id=c.id WHERE c.id=?', [$id]);
        if (!$case) {
            return null;
        }
        $owners = $this->rows('SELECT bma_personal_id,ended_at FROM uat_case_owners WHERE case_id=?', [$id]);
        return new CaseAccess($id, $case['unit_level1'], $case['unit_level2'], $owners);
    }

    public function create(string $number, string $unit, string $actorId, string $actorName): int
    {
        $this->execute('INSERT INTO cases(เลขที่สำนวน,หน่วยงานต้นเรื่อง,unit_level1,เจ้าหน้าที่รับผิดชอบ,submitted_by_id,submitted_by_name)
            VALUES(?,?,?,?,?,?)', [$number, $unit, $unit, $actorName, $actorId, $actorName]);
        return (int)$this->db->lastInsertId();
    }

    public function updateHeader(int $id, string $number, string $unit): void
    {
        $this->execute('UPDATE cases SET เลขที่สำนวน=?,หน่วยงานต้นเรื่อง=?,unit_level1=? WHERE id=?', [$number, $unit, $unit, $id]);
    }

    public function saveMetadata(int $id, string $unit, string $group, string $source, string $sourceOther, string $received, string $allegation): void
    {
        $this->execute('INSERT INTO uat_case_meta(case_id,responsible_unit,unit_level1,unit_level2,source_type,source_other,received_date,preliminary_allegation)
            VALUES(?,?,?,?,?,?,?,?) ON CONFLICT(case_id) DO UPDATE SET responsible_unit=excluded.responsible_unit,
            unit_level1=excluded.unit_level1,unit_level2=excluded.unit_level2,source_type=excluded.source_type,
            source_other=excluded.source_other,received_date=excluded.received_date,preliminary_allegation=excluded.preliminary_allegation,
            updated_at=CURRENT_TIMESTAMP', [$id, $unit, $unit, $group, $source, $sourceOther, $received, $allegation]);
    }

    public function setType(int $id, string $type, string $other): void
    {
        $this->execute('UPDATE uat_case_meta SET case_type=?,case_type_other=? WHERE case_id=?', [$type, $other, $id]);
    }

    public function setStage(int $id, string $stage, ?string $closedAt): void
    {
        $this->execute('UPDATE uat_case_meta SET stage=?,closed_at=? WHERE case_id=?', [$stage, $closedAt, $id]);
    }

    public function addOwner(int $id, string $actorId, string $name): void
    {
        $this->execute('INSERT INTO uat_case_owners(case_id,bma_personal_id,owner_name) VALUES(?,?,?)', [$id, $actorId, $name]);
    }
}
