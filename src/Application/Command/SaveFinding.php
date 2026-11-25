<?php

declare(strict_types=1);

namespace App\Application\Command;

use App\Application\Auth\CaseAudit;
use App\Domain\Workflow\TrackType;
use App\Form\WorkflowFields as Fields;
use App\Infrastructure\Persistence\Repository\{CaseRepository, FindingRepository, SubjectRepository, TrackRepository};
use App\Security\User;
use Symfony\Component\Clock\ClockInterface;

final readonly class SaveFinding
{
    public function __construct(private WorkflowWrite $write, private SaveTrack $schedule, private TrackRepository $tracks,
        private SubjectRepository $subjects, private FindingRepository $findings, private CaseRepository $cases, private CaseAudit $audit,
        private ClockInterface $clock)
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
            $submitted = $results + $severity + $detail;
            if (array_diff_key($submitted, $subjects)) {
                throw new WriteFailure('ไม่พบผู้ถูกกล่าวหาในสำนวน', 404);
            }
            foreach (array_keys($submitted) as $sid) {
                $this->tracks->addMember($id, $sid, true);
                $this->findings->saveFact($id, $sid, Fields::text($results, (string)$sid),
                    Fields::text($severity, (string)$sid), Fields::text($detail, (string)$sid));
            }
            $counts = $this->findings->factCounts($id, $caseId);
            $closed = $subjects !== [] && count($subjects) === $counts['no_ground'];
            $stage = $this->tracks->hasActiveDiscipline($caseId) ? 'discipline' : ($closed ? 'closed' : 'fact_finding');
            $closedAt = $stage === 'closed' ? $this->clock->withTimeZone('Asia/Bangkok')->now()->format('Y-m-d') : null;
            $this->cases->setStage($caseId, $stage, $closedAt);
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
