<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

final class ExtensionRepository extends Repository
{
    public function forTrack(int $trackId): array
    {
        return $this->rows('SELECT * FROM uat_extensions WHERE track_id=? ORDER BY sequence_no', [$trackId]);
    }

    public function add(int $trackId, int $sequence, int $days, string $work, string $reason, string $dueDate): void
    {
        $this->execute('INSERT INTO uat_extensions(track_id,sequence_no,requested_days,completed_work,reason,new_due_date) VALUES(?,?,?,?,?,?)',
            [$trackId, $sequence, $days, $work, $reason, $dueDate]);
    }

    public function reviseDueDate(int $trackId, int $extensionId, string $dueDate): void
    {
        $this->execute('UPDATE uat_extensions SET new_due_date=? WHERE id=? AND track_id=?', [$dueDate, $extensionId, $trackId]);
    }
}
