<?php

declare(strict_types=1);

namespace App\Application\Command;

use App\Application\Auth\CaseAudit;
use App\Domain\Workflow\TrackType;
use App\Form\WorkflowFields as Fields;
use App\Infrastructure\Persistence\Repository\{CaseRepository, FindingRepository, SubjectRepository, TrackRepository};
use App\Security\User;

final readonly class SaveFinding
{
    public function __construct(private WorkflowWrite $write, private SaveTrack $schedule, private TrackRepository $tracks,
        private SubjectRepository $subjects, private FindingRepository $findings, private CaseRepository $cases, private CaseAudit $audit)
    {
    }

    public function run(User $user, int $caseId, array $input): void
    {
        $this->write->run($user, $caseId, function () use ($caseId, $input) {
            $id = $this->tracks->ensureFirst($caseId, TrackType::Fact);
            $this->schedule->apply($this->tracks->byId($caseId, $id), $input);
            $results = Fields::subjectMap($input, 'finding');
            $severity = Fields::subjectMap($input, 'severity');
            $detail = Fields::subjectMap($input, 'finding_detail');
            $subjects = array_column($this->subjects->activeForCase($caseId), 'id', 'id');
            if (array_diff_key($results + $severity + $detail, $subjects)) {
                throw new WriteFailure('ไม่พบผู้ถูกกล่าวหาในสำนวน', 404);
            }
            foreach ($subjects as $sid) {
                $this->tracks->addMember($id, $sid, true);
                $this->findings->saveFact($id, $sid, Fields::text($results, (string)$sid),
                    Fields::text($severity, (string)$sid), Fields::text($detail, (string)$sid));
            }
            $counts = $this->findings->factCounts($id, $caseId);
            $closed = $counts['total'] > 0 && $counts['total'] === $counts['no_ground'];
            $stage = $this->tracks->hasActiveDiscipline($caseId) ? 'discipline' : ($closed ? 'closed' : 'fact_finding');
            $this->cases->setStage($caseId, $stage, $stage === 'closed' ? date('Y-m-d') : null);
            $this->audit->record($caseId, 'save_fact_finding');
        });
    }

    public function assign(User $user, int $caseId, array $input): void
    {
        $this->write->run($user, $caseId, function () use ($caseId, $input) {
            $choices = Fields::subjectMap($input, 'track_for');
            $assigned = false;
            foreach ($this->subjects->withGrounds($caseId) as $subject) {
                $sid = $subject['id'];
                $type = TrackType::tryFrom(Fields::text($choices, (string)$sid));
                if (!$type || $type === TrackType::Fact || $this->tracks->currentDiscipline($caseId, $sid)) {
                    continue;
                }
                $this->write->enroll($this->tracks->ensureFirst($caseId, $type), $sid);
                $assigned = true;
            }
            if ($assigned) {
                $this->cases->setStage($caseId, 'discipline', null);
                $this->audit->record($caseId, 'assign_discipline');
            }
        });
    }
}
