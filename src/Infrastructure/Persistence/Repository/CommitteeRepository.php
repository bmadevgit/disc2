<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

final class CommitteeRepository extends Repository
{
    public function orders(int $trackId): array
    {
        return $this->rows('SELECT * FROM uat_committee_orders WHERE track_id=? ORDER BY id', [$trackId]);
    }

    public function sets(int $trackId): array
    {
        return $this->rows('SELECT * FROM uat_committee_sets WHERE track_id=?', [$trackId]);
    }

    public function setMembers(int $trackId): array
    {
        return $this->rows('SELECT m.* FROM uat_committee_set_subjects m JOIN uat_committee_sets cs ON cs.id=m.committee_set_id
            JOIN uat_tracks t ON t.id=cs.track_id JOIN uat_subjects s ON s.id=m.subject_id AND s.case_id=t.case_id WHERE t.id=?', [$trackId]);
    }

    public function createSet(int $trackId, string $code, string $date, string $number): int
    {
        $created = $this->execute('INSERT INTO uat_committee_sets(track_id,set_code,order_date,order_number)
            VALUES(?,?,?,?) ON CONFLICT(track_id,set_code) DO NOTHING', [$trackId, $code, $date, $number]);
        return $created->rowCount() === 1 ? (int)$this->db->lastInsertId() : 0;
    }

    public function addSetMember(int $setId, int $subjectId): bool
    {
        $created = $this->execute('INSERT INTO uat_committee_set_subjects(committee_set_id,subject_id)
            SELECT cs.id,s.id FROM uat_committee_sets cs JOIN uat_tracks t ON t.id=cs.track_id
            JOIN uat_subjects s ON s.case_id=t.case_id AND s.active=1 WHERE cs.id=? AND s.id=?
            ON CONFLICT DO NOTHING', [$setId, $subjectId]);
        return $created->rowCount() === 1;
    }

    public function addOrder(int $trackId, string $event, string $date, string $number, string $detail): void
    {
        $this->execute('INSERT INTO uat_committee_orders(track_id,event_type,order_date,order_number,detail) VALUES(?,?,?,?,?)',
            [$trackId, $event, $date, $number, $detail]);
    }
}
