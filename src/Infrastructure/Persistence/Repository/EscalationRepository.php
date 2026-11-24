<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Domain\CaseFile\DisciplineRound;
use App\Domain\CaseFile\Escalation;
use App\Domain\Workflow\TrackType;
use DomainException;
use LogicException;
use PDO;

final class EscalationRepository extends Repository
{
    public function __construct(
        PDO $db,
        private readonly TrackRepository $tracks,
        private readonly CommitteeRepository $committees,
        private readonly ExtensionRepository $extensions,
        private readonly FindingRepository $findings,
        private readonly DecisionRepository $decisions,
    ) {
        parent::__construct($db);
    }

    public function round(int $caseId, TrackType $type, int $trackId): ?DisciplineRound
    {
        $track = $this->tracks->find($caseId, $type, $trackId);
        if (!$track) {
            return null;
        }
        $trackId = $track['id'];
        return new DisciplineRound($track, $this->committees->orders($trackId), $this->extensions->forTrack($trackId),
            $this->tracks->members($trackId), $this->committees->sets($trackId), $this->committees->setMembers($trackId),
            array_column($this->findings->forTrack($trackId), null, 'subject_id'),
            $this->decisions->forSnapshot($caseId, $trackId, $type === TrackType::NonSerious ? 'nonserious' : 'serious'));
    }

    public function forSubject(int $subjectId): array
    {
        return $this->rows('SELECT * FROM uat_escalations WHERE subject_id=? ORDER BY id', [$subjectId]);
    }

    /** The caller loads the rounds, records the transfer and audits it in the same write transaction. */
    public function record(Escalation $transfer, string $actorId, string $actorName): void
    {
        if (!$this->db->inTransaction()) {
            throw new LogicException('Recording an escalation requires a write transaction.');
        }
        $from = $transfer->source->track['id'];
        $to = $transfer->target->track['id'];
        $subject = $transfer->subjectId;
        $endedAt = array_column($transfer->source->members, 'ended_at', 'subject_id')[$subject];
        $updated = $this->execute('UPDATE uat_track_subjects SET ended_at=? WHERE track_id=? AND subject_id=? AND ended_at IS NULL',
            [$endedAt, $from, $subject]);
        if ($updated->rowCount() !== 1) {
            throw new DomainException('The source membership has changed.');
        }
        $this->execute('UPDATE uat_committee_set_subjects SET ended_at=? WHERE subject_id=? AND ended_at IS NULL
            AND committee_set_id IN (SELECT id FROM uat_committee_sets WHERE track_id=?)', [$endedAt, $subject, $from]);
        $this->tracks->addMember($to, $subject);
        $this->execute('INSERT INTO uat_escalations(subject_id,from_track_id,to_track_id,effective_date,reference,snapshot,actor_id,actor_name)
            VALUES(?,?,?,?,?,?,?,?)', [$subject, $from, $to, $transfer->effectiveDate, $transfer->reference,
            json_encode($transfer->snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $actorId, $actorName]);
    }
}
