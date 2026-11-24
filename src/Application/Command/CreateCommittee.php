<?php

declare(strict_types=1);

namespace App\Application\Command;

use App\Application\Auth\CaseAudit;
use App\Domain\Workflow\TrackType;
use App\Form\WorkflowFields as Fields;
use App\Infrastructure\Persistence\Repository\{CaseRepository, CommitteeRepository, SubjectRepository, TrackRepository};
use App\Security\User;

final readonly class CreateCommittee
{
    public function __construct(private WorkflowWrite $write, private CommitteeRepository $committees,
        private TrackRepository $tracks, private SubjectRepository $subjects, private CaseRepository $cases, private CaseAudit $audit)
    {
    }

    public function order(User $user, int $caseId, array $input): array
    {
        return $this->write->run($user, $caseId, function () use ($caseId, $input) {
            $track = $this->write->track($caseId, $input);
            $event = Fields::text($input, 'event_type');
            if (!in_array($event, ['appoint','change_committee','amend_order'], true)) {
                throw new WriteFailure('ประเภทรายการไม่ถูกต้อง');
            }
            $this->committees->addOrder($track['id'], $event, Fields::date($input, 'order_date'),
                Fields::text($input, 'order_number'), Fields::text($input, 'detail'));
            $this->audit->record($caseId, 'add_order', (string)$track['id']);
            return $track;
        });
    }

    public function run(User $user, int $caseId, array $input): void
    {
        $this->write->run($user, $caseId, function () use ($caseId, $input) {
            $type = match ($input['track_type'] ?? null) {
                'nonserious' => TrackType::NonSerious,
                'serious' => TrackType::Serious,
                default => throw new WriteFailure('ประเภทการสอบสวนไม่ถูกต้อง'),
            };
            $code = Fields::text($input, 'set_name');
            if ($code === '') {
                throw new WriteFailure('กรุณาระบุชื่อชุดคณะกรรมการ');
            }
            $date = Fields::date($input, 'appointed_date');
            $number = Fields::text($input, 'order_no');
            $id = $type === TrackType::Serious ? $this->tracks->createSeriousSet($caseId) : $this->tracks->ensureFirst($caseId, $type);
            $set = $this->committees->createSet($id, $code, $date, $number);
            if (!$set) {
                throw new WriteFailure('มีชุดคณะกรรมการนี้แล้ว', 409);
            }
            $members = Fields::subjectMap($input, 'members');
            $eligible = array_column($this->subjects->withGrounds($caseId), 'id', 'id');
            foreach ($members as $member) {
                $sid = Fields::id(['subject_id'=>$member], 'subject_id');
                if (!$this->subjects->activeStatus($caseId, $sid)) {
                    throw new WriteFailure('ไม่พบผู้ถูกกล่าวหาในสำนวน', 404);
                }
                $current = $this->tracks->currentDiscipline($caseId, $sid);
                if (!$current && !isset($eligible[$sid])) {
                    throw new WriteFailure('ต้องบันทึกผลสืบสวนว่ามีมูลก่อนกำหนดชุดคณะกรรมการ');
                }
                if (($current && $current['id'] !== $id) || !$this->committees->addSetMember($set, $sid)) {
                    throw new WriteFailure('รอบสอบสวนของบุคคลนี้เปลี่ยนไปแล้ว', 409);
                }
                $this->write->enroll($id, $sid);
            }
            $this->committees->addOrder($id, 'appoint', $date, $number, $code);
            if ($members !== []) {
                $this->cases->setStage($caseId, 'discipline', null);
            }
            $this->audit->record($caseId, 'add_committee', 'UAT committee');
        });
    }
}
