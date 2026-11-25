<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

final class DecisionRepository extends Repository
{
    public function currentForCase(int $caseId): array
    {
        return $this->rows("SELECT s.*,d.*,s.id AS form_subject_id,t.track_type,t.id AS current_track_id FROM uat_subjects s
            JOIN uat_tracks t ON t.id=(SELECT t2.id FROM uat_tracks t2 JOIN uat_track_subjects ts ON ts.track_id=t2.id
                WHERE t2.case_id=s.case_id AND ts.subject_id=s.id AND ts.ended_at IS NULL AND t2.track_type<>'fact'
                ORDER BY t2.track_type='discipline_serious' DESC,t2.id DESC LIMIT 1)
            LEFT JOIN uat_decisions d ON d.subject_id=s.id AND d.track_id=t.id
            WHERE s.case_id=? AND s.active=1 ORDER BY s.sequence_no,s.id", [$caseId]);
    }

    public function forTrack(int $trackId, int $subjectId): array
    {
        return $this->rows('SELECT * FROM uat_decisions WHERE track_id=? AND subject_id=?', [$trackId, $subjectId]);
    }

    public function reports(int $decisionId): array
    {
        return $this->rows('SELECT * FROM uat_kk_reports WHERE decision_id=? ORDER BY sequence_no', [$decisionId]);
    }

    public function forSnapshot(int $caseId, int $trackId, string $route, ?int $subjectId = null): array
    {
        $sql = 'SELECT * FROM uat_decisions WHERE case_id=? AND (track_id=? OR (track_id IS NULL AND route_type=?))';
        $params = [$caseId, $trackId, $route];
        if ($subjectId !== null) {
            $sql .= ' AND subject_id=?';
            $params[] = $subjectId;
        }
        $decisions = $this->rows($sql, $params);
        $bySubject = [];
        foreach ($decisions as $decision) {
            $decision['reports'] = $this->reports($decision['id']);
            $bySubject[$decision['subject_id']][] = $decision;
        }
        return $bySubject;
    }

    public function save(int $caseId, int $trackId, int $subjectId, string $route, array $fields): void
    {
        $this->execute('INSERT INTO uat_decisions(track_id,case_id,subject_id,route_type,report_kk_date,kk_resolution_date,
            kk_resolution,commander_decision,order_number,order_date,finished_date) VALUES(?,?,?,?,?,?,?,?,?,?,?)
            ON CONFLICT(track_id,subject_id) DO UPDATE SET report_kk_date=excluded.report_kk_date,kk_resolution_date=excluded.kk_resolution_date,
            kk_resolution=excluded.kk_resolution,commander_decision=excluded.commander_decision,order_number=excluded.order_number,
            order_date=excluded.order_date,finished_date=excluded.finished_date',
            [$trackId, $caseId, $subjectId, $route, $fields['report_kk_date'], $fields['kk_resolution_date'], $fields['kk_resolution'],
            $fields['commander_decision'], $fields['order_number'], $fields['order_date'], $fields['finished_date']]);
    }
}
