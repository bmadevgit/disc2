<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

final class ReferralRepository extends Repository
{
    public function save(int $caseId, int $trackId, int $subjectId, string $ordered, string $received,
        string $due, string $status, string $note): bool
    {
        $updated = $this->execute('INSERT INTO uat_referrals(track_id,subject_id,ordered_date,received_date,due_date,status,note)
            SELECT t.id,s.id,?,?,?,?,? FROM uat_tracks t JOIN uat_subjects s ON s.case_id=t.case_id AND s.active=1
            WHERE t.case_id=? AND t.id=? AND s.id=? ON CONFLICT(track_id,subject_id) DO UPDATE SET
            ordered_date=excluded.ordered_date,received_date=excluded.received_date,due_date=excluded.due_date,
            status=excluded.status,note=excluded.note', [$ordered,$received,$due,$status,$note,$caseId,$trackId,$subjectId]);
        return $updated->rowCount() === 1;
    }
}
