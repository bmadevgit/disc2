<?php

declare(strict_types=1);

namespace App\Application\Command;

use App\Application\TrackSchedule;
use App\Domain\Workflow\TrackType;
use App\Form\WorkflowFields as Fields;
use App\Infrastructure\Persistence\Repository\ExtensionRepository;
use App\Security\User;

final readonly class ConvertSeriousDeadline
{
    public function __construct(private WorkflowWrite $write, private ExtensionRepository $extensions,
        private TrackSchedule $schedule, private SaveTrack $save)
    {
    }

    public function run(User $user, int $caseId, array $input): array
    {
        return $this->write->run($user, $caseId, function () use ($caseId, $input) {
            $track = $this->write->discipline($caseId, $input);
            $extensions = $this->extensions->forTrack($track['id']);
            if ($track['track_type'] !== TrackType::Serious->value || $track['duration_days'] !== 180
                || !hash_equals(TrackSchedule::revision($track, $extensions), Fields::text($input, 'revision'))) {
                throw new WriteFailure('กำหนดเวลามีการเปลี่ยนแปลง กรุณาเปิดหน้าตรวจสอบอีกครั้ง', 409);
            }
            $reason = Fields::text($input, 'reason');
            if ($reason === '') {
                throw new WriteFailure('กรุณาระบุเหตุผลที่ปรับระยะเวลา');
            }
            $result = $this->schedule->conversion($track, $extensions, $reason)
                ?? throw new WriteFailure('ไม่สามารถคำนวณกำหนดเวลาใหม่ได้');
            $this->save->persist($track, $track['acknowledged_date'], $track['first_meeting_date'], 120,
                $track['start_override_reason'], $extensions, $result, 'convert_serious_deadline', $reason);
            return $track;
        });
    }
}
