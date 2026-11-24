<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Domain\Workflow\DeadlineResult;
use App\Domain\Workflow\TrackType;
use DomainException;

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

    /** Read the displayed order and the matching active set in one SQLite snapshot. */
    public function seriousAppointments(int $caseId, ?int $trackId = null): array
    {
        $sql = "SELECT t.*,o.id appointment_id,o.order_number appointment,o.order_date,o.detail appointment_detail,
            MIN(cs.id) appointed_set_id,COUNT(cs.id) appointed_set_count FROM uat_tracks t
            JOIN uat_committee_orders o ON o.id=(SELECT MAX(id) FROM uat_committee_orders
                WHERE track_id=t.id AND event_type='appoint')
            LEFT JOIN uat_committee_sets cs ON cs.track_id=t.id AND cs.active=1
                AND cs.order_number=o.order_number AND cs.order_date=o.order_date
            WHERE t.case_id=? AND t.track_type='discipline_serious'";
        $params = [$caseId];
        if ($trackId !== null) {
            $sql .= ' AND t.id=?';
            $params[] = $trackId;
        }
        $appointments = $this->rows($sql.' GROUP BY t.id ORDER BY t.set_no', $params);
        foreach ($appointments as $i => $row) {
            $appointments[$i]['revision'] = hash('sha256', serialize([$row['case_id'], $row['id'], $row['set_no'],
                $row['appointment_id'], $row['appointment'], $row['order_date'], $row['appointment_detail'],
                $row['appointed_set_id'], $row['appointed_set_count']]));
        }
        return $appointments;
    }

    public function hasActiveDiscipline(int $caseId): bool
    {
        return (bool)$this->execute("SELECT 1 FROM uat_track_subjects ts JOIN uat_tracks t ON t.id=ts.track_id
            JOIN uat_subjects s ON s.id=ts.subject_id AND s.case_id=t.case_id
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
            JOIN uat_subjects s ON s.id=ts.subject_id AND s.case_id=t.case_id
            WHERE ts.subject_id=? AND t.case_id=? ORDER BY t.track_type<>'fact',t.id", [$subjectId, $caseId]);
    }

    public function member(int $trackId, int $subjectId): ?array
    {
        return $this->row('SELECT ts.* FROM uat_track_subjects ts JOIN uat_tracks t ON t.id=ts.track_id
            JOIN uat_subjects s ON s.id=ts.subject_id AND s.case_id=t.case_id WHERE ts.track_id=? AND ts.subject_id=?', [$trackId, $subjectId]);
    }

    public function members(int $trackId): array
    {
        return $this->rows('SELECT ts.* FROM uat_track_subjects ts JOIN uat_tracks t ON t.id=ts.track_id
            JOIN uat_subjects s ON s.id=ts.subject_id AND s.case_id=t.case_id WHERE ts.track_id=?', [$trackId]);
    }

    public function membersWithResults(int $trackId): array
    {
        return $this->rows('SELECT s.*,ts.ended_at,f.finding,f.allegation_detail,f.offense_basis,
            f.committee_opinion,f.appointing_authority_opinion,f.allegation,f.recommend_serious
            FROM uat_track_subjects ts JOIN uat_tracks t ON t.id=ts.track_id
            JOIN uat_subjects s ON s.id=ts.subject_id AND s.case_id=t.case_id
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
        $insert = $this->execute('INSERT INTO uat_track_subjects(track_id,subject_id)
            SELECT t.id,s.id FROM uat_tracks t JOIN uat_subjects s ON s.case_id=t.case_id WHERE t.id=? AND s.id=?'
            .($allowExisting ? ' ON CONFLICT(track_id,subject_id) DO NOTHING' : ''), [$trackId, $subjectId]);
        if ($insert->rowCount() === 1) {
            return;
        }
        if ($allowExisting && $this->row('SELECT 1 FROM uat_track_subjects ts JOIN uat_tracks t ON t.id=ts.track_id
            JOIN uat_subjects s ON s.id=ts.subject_id AND s.case_id=t.case_id WHERE ts.track_id=? AND ts.subject_id=?', [$trackId, $subjectId])) {
            return;
        }
        throw new DomainException('The subject and track must belong to the same case.');
    }

    public function saveDates(int $trackId, string $acknowledged, string $meeting, DeadlineResult $result): void
    {
        $this->saveSchedule($trackId, ['acknowledged_date'=>$acknowledged, 'first_meeting_date'=>$meeting,
            'start_date'=>$result->startDate, 'duration_days'=>$result->durationDays, 'due_date'=>$result->dueDate,
            'start_override_reason'=>$result->startOverrideReason]);
    }

    /** Includes unscheduled drafts with blank start and due dates. */
    public function saveSchedule(int $trackId, array $dates): void
    {
        $this->execute('UPDATE uat_tracks SET acknowledged_date=?,first_meeting_date=?,start_date=?,duration_days=?,due_date=?,start_override_reason=?
            WHERE id=?', [$dates['acknowledged_date'], $dates['first_meeting_date'], $dates['start_date'],
            $dates['duration_days'], $dates['due_date'], $dates['start_override_reason'], $trackId]);
    }
}
