<?php

declare(strict_types=1);

namespace App\Application\Command;

use App\Application\Auth\CaseAudit;
use App\Application\TrackSchedule;
use App\Domain\Workflow\{DeadlineInput, TrackType};
use App\Form\WorkflowFields as Fields;
use App\Infrastructure\Persistence\Repository\{ExtensionRepository, TrackRepository};
use App\Security\User;
use InvalidArgumentException;

final readonly class SaveTrack
{
    public function __construct(private WorkflowWrite $write, private TrackSchedule $schedule,
        private TrackRepository $tracks, private ExtensionRepository $extensions, private CaseAudit $audit)
    {
    }

    public function run(User $user, int $caseId, array $input): array
    {
        return $this->write->run($user, $caseId, function () use ($caseId, $input) {
            $track = $this->write->discipline($caseId, $input);
            $this->apply($track, $input);
            return $track;
        });
    }

    /** Also called inside the transaction that saves the fact findings. */
    public function apply(array $track, array $input): void
    {
        $type = TrackType::from($track['track_type']);
        $legacy = $type === TrackType::Serious && $track['duration_days'] === 180;
        $days = $type === TrackType::Fact ? Fields::days($input, 'duration_days', '30') : ($legacy ? 180 : $type->defaultDays());
        $ack = Fields::date($input, 'acknowledged_date');
        $meeting = Fields::date($input, 'first_meeting_date');
        $manual = ($input['start_mode'] ?? (!empty($input['start_date']) && !empty($input['start_override_reason']) ? 'manual' : 'auto')) === 'manual';
        $start = $manual ? Fields::date($input, 'start_date') : '';
        $reason = $manual ? Fields::text($input, 'start_override_reason') : '';
        if ($manual && ($start === '' || $reason === '')) {
            throw new WriteFailure('กรุณาระบุวันเริ่มนับและเหตุผลที่แก้ไข');
        }
        // A resubmission with the original inputs keeps the published historical dates.
        if ($track['acknowledged_date'] === $ack && $track['first_meeting_date'] === $meeting
            && $track['duration_days'] === $days && $track['start_override_reason'] === $reason
            && (!$manual || $track['start_date'] === $start)) {
            return;
        }
        $extensions = $this->extensions->forTrack($track['id']);
        try {
            $deadline = new DeadlineInput($type, $ack, $meeting, $days, $start, $reason, legacy: $legacy);
            $result = $this->schedule->calculate($deadline);
            if ($result['dueDate'] === $track['due_date'] && $result['dueDate'] !== '') {
                $result['extensions'] = $extensions;
            } elseif ($extensions !== []) {
                $result = $this->schedule->calculate(new DeadlineInput($type, $ack, $meeting, $days, $start, $reason, $extensions, $legacy));
            }
        } catch (InvalidArgumentException) {
            throw new WriteFailure('ไม่สามารถคำนวณกำหนดระยะเวลาและการขยายเวลาได้');
        }
        $this->persist($track, $ack, $meeting, $days, $reason, $extensions, $result,
            $type === TrackType::Fact ? 'save_fact_deadline' : 'save_track');
    }

    public function persist(array $track, string $ack, string $meeting, int $days, string $override,
        array $extensions, array $result, string $action, string $reason = ''): void
    {
        $after = ['acknowledged_date'=>$ack, 'first_meeting_date'=>$meeting, 'start_date'=>$result['startDate'],
            'duration_days'=>$days, 'due_date'=>$result['dueDate'], 'start_override_reason'=>$override];
        $this->tracks->saveSchedule($track['id'], $after);
        foreach ($result['extensions'] as $key => $row) {
            if ($extensions[$key]['new_due_date'] !== $row['new_due_date']) {
                $this->extensions->reviseDueDate($track['id'], $row['id'], $row['new_due_date']);
            }
        }
        $this->audit->record($track['case_id'], $action, json_encode(['track_id'=>$track['id'], 'reason'=>$reason,
            'before'=>array_intersect_key($track, $after), 'after'=>$after, 'extensions_before'=>$extensions,
            'extensions_after'=>$result['extensions']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
