<?php

declare(strict_types=1);

namespace App\Application\Command;

use App\Application\Auth\CaseAudit;
use App\Domain\CaseFile\{DisciplineRound, Escalation};
use App\Domain\Workflow\TrackType;
use App\Form\WorkflowFields as Fields;
use App\Infrastructure\Persistence\Repository\{CommitteeRepository, EscalationRepository, TrackRepository};
use App\Security\User;
use DateTimeImmutable;
use DomainException;

final readonly class EscalateDiscipline
{
    public function __construct(private WorkflowWrite $write, private TrackRepository $tracks, private CommitteeRepository $committees,
        private EscalationRepository $escalations, private CaseAudit $audit)
    {
    }

    public function run(User $user, int $caseId, array $input): array
    {
        return $this->write->run($user, $caseId, function (User $actor) use ($caseId, $input) {
            $sid = Fields::id($input, 'subject_id');
            $from = Fields::id($input, 'track_id');
            $previous = $this->escalations->previousTarget($caseId, $sid, $from);
            if ($previous) {
                return $previous;
            }
            $date = Fields::date($input, 'effective_date');
            $reference = Fields::text($input, 'reference');
            if ($date === '' || $reference === '') {
                throw new WriteFailure('กรุณาระบุวันที่และหลักฐานคำสั่ง');
            }
            $current = $this->write->current($caseId, $sid, $from);
            if ($current['track_type'] !== TrackType::NonSerious->value) {
                throw new WriteFailure('รอบสอบสวนของบุคคลนี้เปลี่ยนไปแล้ว', 409);
            }
            $source = $this->escalations->round($caseId, TrackType::NonSerious, $from, $sid);
            $targetId = Fields::id($input, 'target_track');
            $setId = null;
            if (($input['target_track'] ?? '') === 'new') {
                $targetId = $this->tracks->createSeriousSet($caseId);
                $this->committees->addOrder($targetId, 'appoint', $date, $reference, '');
            } else {
                if (!$targetId) {
                    throw new WriteFailure('กรุณาเลือกชุด คกก. ร้ายแรง');
                }
                $appointment = $this->tracks->seriousAppointments($caseId, $targetId)[0] ?? null;
                if (!$appointment && !$this->tracks->find($caseId, TrackType::Serious, $targetId)) {
                    throw new WriteFailure('ไม่พบกระบวนการสอบสวน', 404);
                }
                $revisions = $input['target_revision'] ?? [];
                $revision = is_array($revisions) ? ($revisions[$targetId] ?? null) : null;
                if (!$appointment || !is_string($revision) || !hash_equals($appointment['revision'], $revision)
                    || $appointment['appointed_set_count'] > 1) {
                    throw new WriteFailure('คำสั่งแต่งตั้งมีการเปลี่ยนแปลง กรุณาเปิดหน้าเลือกชุด คกก. อีกครั้ง', 409);
                }
                $setId = $appointment['appointed_set_id'];
            }
            $targetTrack = $this->tracks->find($caseId, TrackType::Serious, $targetId);
            $target = new DisciplineRound($targetTrack, members: array_filter([$this->tracks->member($targetId, $sid)]));
            try {
                $transfer = Escalation::transfer($sid, $source, $target, $date, $reference, new DateTimeImmutable());
            } catch (DomainException) {
                throw new WriteFailure('โปรดตรวจสอบสมาชิกและความเห็น คกก. ที่เสนอให้สอบสวนร้ายแรง');
            }
            $this->escalations->record($transfer, $actor->scope->actorId, $actor->name);
            if ($setId !== null && !$this->committees->addSetMember($setId, $sid)) {
                throw new WriteFailure('กรุณาตรวจสอบสมาชิกชุด คกก. ตามคำสั่งแต่งตั้ง', 409);
            }
            $this->audit->record($caseId, 'escalate_discipline', json_encode(['subject_id'=>$sid, 'before'=>$from,
                'after'=>$targetId, 'date'=>$date, 'reference'=>$reference], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            return $target->track;
        });
    }
}
