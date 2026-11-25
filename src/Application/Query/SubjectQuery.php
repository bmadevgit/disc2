<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Infrastructure\Persistence\Repository\{CaseRepository, CommitteeRepository, DecisionRepository, EscalationRepository, ExtensionRepository, FindingRepository, SubjectRepository, TrackRepository};

final readonly class SubjectQuery
{
    public function __construct(private CaseRepository $cases, private SubjectRepository $subjects, private TrackRepository $tracks,
        private EscalationRepository $escalations, private CommitteeRepository $committees, private ExtensionRepository $extensions,
        private FindingRepository $findings, private DecisionRepository $decisions)
    {
    }

    public function fetch(int $caseId, int $sid, bool $identity): ?array
    {
        $case = $this->cases->find($caseId);
        if (!$case) {
            return null;
        }
        $people = array_map(static fn ($row) => SubjectPresentation::forReader($row, $identity), $this->subjects->activeForCase($caseId));
        $person = array_column($people, null, 'id')[$sid] ?? null;
        $result = compact('case', 'caseId', 'sid', 'people', 'person');
        if (!$person) {
            return $result;
        }
        $rounds = $this->tracks->history($caseId, $sid);
        $latest = $this->tracks->currentDiscipline($caseId, $sid) ?: end($rounds);
        $escalations = array_column($this->escalations->forSubject($sid), null, 'from_track_id');
        $history = $this->subjects->statusHistory($sid);
        $periods = [];
        foreach (array_reverse($rounds) as $round) {
            $tid = $round['id'];
            $escalation = $escalations[$tid] ?? null;
            $snapshot = $escalation ? json_decode($escalation['snapshot'], true, flags: JSON_THROW_ON_ERROR) : [];
            $decisions = $snapshot['decisions'] ?? $this->decisions->forTrack($tid, $sid);
            foreach ($decisions as &$decision) {
                $decision['reports'] ??= $this->decisions->reports($decision['id']);
            }
            unset($decision);
            $members = $snapshot['members'] ?? $this->tracks->members($tid);
            $periods[] = compact('round','escalation','decisions') + ['dates'=>$snapshot['track'] ?? $round,
                'orders'=>$snapshot['orders'] ?? $this->committees->orders($tid),
                'extensions'=>$snapshot['extensions'] ?? $this->extensions->forTrack($tid),
                'finding'=>$snapshot['finding'] ?? $this->findings->forSubject($tid, $sid) ?? [],
                'memberCount'=>count(array_filter($members, static fn ($member) => empty($member['ended_at'])))];
        }
        return $result + compact('latest', 'history', 'periods');
    }
}
