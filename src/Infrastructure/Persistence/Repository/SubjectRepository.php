<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

final class SubjectRepository extends Repository
{
    public function activeForCase(int $caseId): array
    {
        return $this->rows('SELECT s.*,(SELECT status_name FROM uat_subject_status_history h
            WHERE h.subject_id=s.id ORDER BY h.id DESC LIMIT 1) current_status
            FROM uat_subjects s WHERE case_id=? AND active=1 ORDER BY sequence_no', [$caseId]);
    }

    public function withCurrentFindings(int $caseId): array
    {
        return $this->rows("SELECT s.*, f.finding FROM uat_subjects s
            LEFT JOIN uat_subject_findings f ON f.subject_id=s.id AND f.track_id=(
                SELECT t.id FROM uat_track_subjects ts JOIN uat_tracks t ON t.id=ts.track_id
                WHERE ts.subject_id=s.id AND t.case_id=s.case_id AND ts.ended_at IS NULL
                ORDER BY t.track_type='discipline_serious' DESC,t.track_type='discipline_nonserious' DESC,t.id DESC LIMIT 1)
            WHERE s.case_id=? AND s.active=1 ORDER BY s.sequence_no", [$caseId]);
    }

    public function withGrounds(int $caseId): array
    {
        return $this->rows("SELECT s.*,f.severity FROM uat_subjects s JOIN uat_subject_findings f ON f.subject_id=s.id AND f.finding='ground'
            JOIN uat_tracks t ON t.id=f.track_id AND t.track_type='fact' AND t.case_id=s.case_id
            WHERE s.case_id=? AND s.active=1 ORDER BY s.sequence_no", [$caseId]);
    }

    public function statusHistory(int $subjectId): array
    {
        return $this->rows('SELECT * FROM uat_subject_status_history WHERE subject_id=? ORDER BY id DESC', [$subjectId]);
    }

    public function activeStatus(int $caseId, int $subjectId): ?array
    {
        return $this->row('SELECT (SELECT status_name FROM uat_subject_status_history h
            WHERE h.subject_id=s.id ORDER BY h.id DESC LIMIT 1) status_name
            FROM uat_subjects s WHERE s.id=? AND s.case_id=? AND s.active=1', [$subjectId, $caseId]);
    }

    public function updateEmployment(int $caseId, int $subjectId, string $employment): void
    {
        $this->execute('UPDATE uat_subjects SET employment_type=? WHERE id=? AND case_id=? AND active=1',
            [$employment, $subjectId, $caseId]);
    }

    public function nextSequence(int $caseId): int
    {
        return (int)$this->execute('SELECT COALESCE(MAX(sequence_no),0)+1 FROM uat_subjects WHERE case_id=?', [$caseId])->fetchColumn();
    }

    /**
     * Updates preserve columns whose fields were omitted. New rows receive the bound form defaults.
     * @param array{title_th:?string,first_name_th:?string,last_name_th:?string,position_name:?string,employment_type:?string,national_id:?string} $fields
     */
    public function save(int $caseId, int $subjectId, int $sequence, array $fields): int
    {
        $values = [$fields['title_th'], $fields['first_name_th'], $fields['last_name_th'], $fields['position_name'],
            $fields['employment_type'], $fields['national_id']];
        if ($subjectId > 0) {
            $updated = $this->execute("UPDATE uat_subjects SET title_th=COALESCE(?,title_th),
                first_name_th=COALESCE(?,first_name_th),last_name_th=COALESCE(?,last_name_th),
                position_name=COALESCE(?,position_name),employment_type=COALESCE(?,employment_type),
                national_id=COALESCE(NULLIF(?,''),national_id) WHERE id=? AND case_id=? AND active=1", [...$values, $subjectId, $caseId]);
            if ($updated->rowCount() !== 1) {
                throw new \UnexpectedValueException('Missing active subject in this case.');
            }
            return $subjectId;
        }
        $this->execute('INSERT INTO uat_subjects(case_id,sequence_no,title_th,first_name_th,last_name_th,position_name,employment_type,national_id,reference_code)
            VALUES(?,?,?,?,?,?,?,?,?)', [$caseId, $sequence, ...$values, 'REF-'.$caseId.'-'.$sequence]);
        return (int)$this->db->lastInsertId();
    }

    public function recordStatus(int $subjectId, string $status, string $effectiveDate, string $reason): void
    {
        $this->execute('INSERT INTO uat_subject_status_history(subject_id,status_name,effective_date,reason)
            SELECT ?,?,?,? WHERE ? IS NOT (SELECT status_name FROM uat_subject_status_history WHERE subject_id=? ORDER BY id DESC LIMIT 1)',
            [$subjectId, $status, $effectiveDate, $reason, $status, $subjectId]);
    }
}
