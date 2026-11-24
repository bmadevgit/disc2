<?php

declare(strict_types=1);

namespace App\Domain\CaseFile;

use App\Domain\Workflow\TrackType;
use DomainException;
use InvalidArgumentException;

final readonly class DisciplineRound
{
    public TrackType $type;

    /**
     * Keep complete historical rows for the escalation snapshot, including all committee sets.
     * Findings and decisions are keyed by subject ID. Each decision includes its reports.
     */
    public function __construct(
        public array $track,
        public array $orders = [],
        public array $extensions = [],
        public array $members = [],
        public array $committeeSets = [],
        public array $committeeMembers = [],
        public array $findings = [],
        public array $decisions = [],
    ) {
        $type = TrackType::tryFrom($track['track_type'] ?? '');
        if (!in_array($type, [TrackType::NonSerious, TrackType::Serious], true)
            || !is_int($track['id'] ?? null) || $track['id'] < 1
            || !is_int($track['case_id'] ?? null) || $track['case_id'] < 1) {
            throw new InvalidArgumentException('Expected a disciplinary round with a track ID and case ID.');
        }
        $this->type = $type;
    }

    public function hasMember(int $subjectId): bool
    {
        foreach ($this->members as $member) {
            if ($member['subject_id'] === $subjectId && $member['ended_at'] === null) {
                return true;
            }
        }
        return false;
    }

    public function withFinding(int $subjectId, array $finding): self
    {
        if (!$this->hasMember($subjectId)) {
            throw new DomainException('Findings can only be changed in the current round.');
        }
        if (!in_array($finding['finding'] ?? null, ['', 'guilty', 'not_guilty'], true)) {
            throw new InvalidArgumentException('Unknown disciplinary finding.');
        }
        $recommendation = $finding['recommend_serious'] ?? 0;
        if (!in_array($recommendation, [0, 1], true)) {
            throw new InvalidArgumentException('Expected a recommendation flag of 0 or 1.');
        }
        if ($recommendation === 1
            && ($this->type === TrackType::Serious || trim($finding['committee_opinion'] ?? '') === '')) {
            throw new InvalidArgumentException('A serious recommendation requires a non-serious round and a committee opinion.');
        }
        $findings = $this->findings;
        $findings[$subjectId] = $finding;
        return $this->copy(findings: $findings);
    }

    public function withoutActiveMember(int $subjectId, string $endedAt): self
    {
        return $this->copy(
            members: self::endMemberships($this->members, $subjectId, $endedAt),
            committeeMembers: self::endMemberships($this->committeeMembers, $subjectId, $endedAt),
        );
    }

    public function withNewMember(int $subjectId): self
    {
        foreach ($this->members as $member) {
            if ($member['subject_id'] === $subjectId) {
                throw new DomainException('The subject already has a membership in the selected round.');
            }
        }
        $members = $this->members;
        $members[] = ['track_id' => $this->track['id'], 'subject_id' => $subjectId, 'ended_at' => null];
        return $this->copy(members: $members);
    }

    private static function endMemberships(array $rows, int $subjectId, string $endedAt): array
    {
        foreach ($rows as $i => $row) {
            if ($row['subject_id'] === $subjectId && $row['ended_at'] === null) {
                $rows[$i]['ended_at'] = $endedAt;
            }
        }
        return $rows;
    }

    private function copy(?array $members = null, ?array $committeeMembers = null, ?array $findings = null): self
    {
        return new self($this->track, $this->orders, $this->extensions, $members ?? $this->members,
            $this->committeeSets, $committeeMembers ?? $this->committeeMembers, $findings ?? $this->findings, $this->decisions);
    }
}
