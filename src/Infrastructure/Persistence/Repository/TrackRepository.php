<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Domain\Workflow\DeadlineResult;
use App\Domain\Workflow\TrackType;

final class TrackRepository extends Repository
{
    public function find(int $caseId, TrackType $type, ?int $trackId = null): ?array
    {
        return $this->row('SELECT * FROM uat_tracks WHERE case_id=? AND track_type=? AND '.($trackId ? 'id=?' : 'set_no=?'),
            [$caseId, $type->value, $trackId ?: 1]);
    }

    public function byId(int $caseId, int $trackId): ?array
    {
        return $this->row('SELECT * FROM uat_tracks WHERE case_id=? AND id=?', [$caseId, $trackId]);
    }

    public function disciplineForCase(int $caseId): array
    {
        return $this->rows("SELECT * FROM uat_tracks WHERE case_id=? AND track_type<>'fact' ORDER BY track_type,set_no", [$caseId]);
    }

    public function seriousAppointments(int $caseId): array
    {
        return $this->rows("SELECT t.*,(SELECT order_number FROM uat_committee_orders o WHERE o.track_id=t.id AND o.event_type='appoint'
            ORDER BY o.id DESC LIMIT 1) appointment FROM uat_tracks t WHERE t.case_id=? AND t.track_type='discipline_serious' ORDER BY t.set_no", [$caseId]);
    }

    public function hasActiveDiscipline(int $caseId): bool
    {
        return (bool)$this->execute("SELECT 1 FROM uat_track_subjects ts JOIN uat_tracks t ON t.id=ts.track_id
            WHERE t.case_id=? AND t.track_type<>'fact' AND ts.ended_at IS NULL LIMIT 1", [$caseId])->fetchColumn();
    }

    /** Admin dashboard deadlines in effective due date order. */
    public function deadlines(): array
    {
        return $this->rows("SELECT t.*,c.เลขที่สำนวน,COALESCE((SELECT new_due_date FROM uat_extensions e WHERE e.track_id=t.id
            ORDER BY sequence_no DESC LIMIT 1),t.due_date) effective_due FROM uat_tracks t JOIN cases c ON c.id=t.case_id
            WHERE t.due_date<>'' ORDER BY effective_due");
    }

    public function currentDiscipline(int $caseId, int $subjectId): ?array
    {
        return $this->row("SELECT t.* FROM uat_tracks t JOIN uat_track_subjects ts ON ts.track_id=t.id
            JOIN uat_subjects s ON s.id=ts.subject_id AND s.case_id=t.case_id
            WHERE t.case_id=? AND s.id=? AND s.active=1 AND t.track_type<>'fact' AND ts.ended_at IS NULL
            ORDER BY t.track_type='discipline_serious' DESC,t.id DESC LIMIT 1", [$caseId, $subjectId]);
    }

    public function history(int $caseId, int $subjectId): array
    {
        return $this->rows("SELECT t.*,ts.ended_at FROM uat_track_subjects ts JOIN uat_tracks t ON t.id=ts.track_id
            WHERE ts.subject_id=? AND t.case_id=? ORDER BY t.track_type<>'fact',t.id", [$subjectId, $caseId]);
    }

    public function members(int $trackId): array
    {
        return $this->rows('SELECT * FROM uat_track_subjects WHERE track_id=?', [$trackId]);
    }

    public function membersWithResults(int $trackId): array
    {
        return $this->rows('SELECT s.*,ts.ended_at,f.finding,f.allegation_detail,f.offense_basis,
            f.committee_opinion,f.appointing_authority_opinion,f.allegation,f.recommend_serious
            FROM uat_track_subjects ts JOIN uat_subjects s ON s.id=ts.subject_id
            LEFT JOIN uat_subject_findings f ON f.track_id=ts.track_id AND f.subject_id=ts.subject_id
            WHERE ts.track_id=? AND s.active=1 ORDER BY s.sequence_no', [$trackId]);
    }

    public function ensureFirst(int $caseId, TrackType $type): int
    {
        $this->execute('INSERT INTO uat_tracks(case_id,track_type,duration_days) VALUES(?,?,?)
            ON CONFLICT(case_id,track_type,set_no) DO NOTHING', [$caseId, $type->value, $type === TrackType::Fact ? 0 : $type->defaultDays()]);
        return $this->find($caseId, $type)['id'];
    }

    public function createSeriousSet(int $caseId): int
    {
        $this->execute("INSERT INTO uat_tracks(case_id,track_type,duration_days,set_no)
            SELECT ?,'discipline_serious',120,COALESCE(MAX(set_no),0)+1 FROM uat_tracks WHERE case_id=? AND track_type='discipline_serious'",
            [$caseId, $caseId]);
        return (int)$this->db->lastInsertId();
    }

    public function addMember(int $trackId, int $subjectId, bool $allowExisting = false): void
    {
        $this->execute('INSERT INTO uat_track_subjects(track_id,subject_id) VALUES(?,?)'
            .($allowExisting ? ' ON CONFLICT(track_id,subject_id) DO NOTHING' : ''), [$trackId, $subjectId]);
    }

    public function saveDates(int $trackId, string $acknowledged, string $meeting, DeadlineResult $result): void
    {
        $this->execute('UPDATE uat_tracks SET acknowledged_date=?,first_meeting_date=?,start_date=?,duration_days=?,due_date=?,start_override_reason=?
            WHERE id=?', [$acknowledged, $meeting, $result->startDate, $result->durationDays, $result->dueDate, $result->startOverrideReason, $trackId]);
    }
}
