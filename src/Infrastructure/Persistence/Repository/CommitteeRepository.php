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
        return $this->rows('SELECT m.* FROM uat_committee_set_subjects m JOIN uat_committee_sets t ON t.id=m.committee_set_id WHERE t.track_id=?', [$trackId]);
    }

    public function addOrder(int $trackId, string $event, string $date, string $number, string $detail): void
    {
        $this->execute('INSERT INTO uat_committee_orders(track_id,event_type,order_date,order_number,detail) VALUES(?,?,?,?,?)',
            [$trackId, $event, $date, $number, $detail]);
    }
}
