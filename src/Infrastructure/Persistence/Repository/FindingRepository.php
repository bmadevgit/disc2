<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

final class FindingRepository extends Repository
{
    private const CASE_FINDINGS = ' FROM uat_subject_findings f JOIN uat_tracks t ON t.id=f.track_id
        JOIN uat_subjects s ON s.id=f.subject_id AND s.case_id=t.case_id WHERE t.id=?';

    public function factCounts(int $trackId, int $caseId): array
    {
        return $this->row("SELECT COUNT(*) total,SUM(CASE WHEN f.finding='no_ground' THEN 1 ELSE 0 END) no_ground"
            .self::CASE_FINDINGS.' AND t.case_id=? AND s.active=1', [$trackId, $caseId]);
    }

    public function forTrack(int $trackId): array
    {
        return $this->rows('SELECT f.*'.self::CASE_FINDINGS, [$trackId]);
    }

    public function forSubject(int $trackId, int $subjectId): ?array
    {
        return $this->row('SELECT f.*'.self::CASE_FINDINGS.' AND s.id=?', [$trackId, $subjectId]);
    }

    public function saveFact(int $trackId, int $subjectId, string $finding, string $severity, string $detail): void
    {
        $this->execute('INSERT INTO uat_subject_findings(track_id,subject_id,finding,severity,allegation_detail) VALUES(?,?,?,?,?)
            ON CONFLICT(track_id,subject_id) DO UPDATE SET finding=excluded.finding,severity=excluded.severity,allegation_detail=excluded.allegation_detail',
            [$trackId, $subjectId, $finding, $severity, $detail]);
    }

    public function saveDiscipline(int $trackId, int $subjectId, string $severity, array $finding): void
    {
        $this->execute('INSERT INTO uat_subject_findings(track_id,subject_id,finding,severity,committee_opinion,appointing_authority_opinion,
            offense_basis,allegation,recommend_serious) VALUES(?,?,?,?,?,?,?,?,?) ON CONFLICT(track_id,subject_id) DO UPDATE SET
            finding=excluded.finding,severity=excluded.severity,committee_opinion=excluded.committee_opinion,
            appointing_authority_opinion=excluded.appointing_authority_opinion,offense_basis=excluded.offense_basis,
            allegation=excluded.allegation,recommend_serious=excluded.recommend_serious',
            [$trackId, $subjectId, $finding['finding'], $severity, $finding['committee_opinion'], $finding['appointing_authority_opinion'],
            $finding['offense_basis'], $finding['allegation'], $finding['recommend_serious']]);
    }
}
