<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Workflow\{CalendarDate, DeadlineCalculator, DeadlineInput, DeadlineResult, TrackType};
use InvalidArgumentException;

final readonly class TrackSchedule
{
    public function __construct(private DeadlineCalculator $calculator)
    {
    }

    public static function revision(array $track, array $extensions): string
    {
        return hash('sha256', serialize([$track, $extensions]));
    }

    /** Missing automatic inputs are permitted while drafting an extension-free schedule. */
    public function calculate(DeadlineInput $input): array
    {
        $draft = $input->manualStart === '' && $input->startOverrideReason === ''
            && ($input->firstMeetingDate === '' || ($input->type !== TrackType::Serious && $input->acknowledgedDate === ''));
        if ($draft) {
            foreach ([$input->acknowledgedDate, $input->firstMeetingDate] as $date) {
                if ($date !== '') {
                    CalendarDate::parse($date);
                }
            }
            if ($input->extensions !== []) {
                throw new InvalidArgumentException('กรุณาระบุวันครบกำหนดสำหรับชุดที่มีการขยายเวลา');
            }
            return ['startDate'=>'', 'dueDate'=>'', 'extensions'=>[]];
        }
        $result = $this->calculator->calculate($input);
        return ['startDate'=>$result->startDate, 'dueDate'=>$result->dueDate, 'extensions'=>$result->extensions];
    }

    /** A null result suppresses confirmation on GET and rejects it on POST. */
    public function conversion(array $track, array $extensions, ?string $reason = null): ?array
    {
        $manual = $track['start_override_reason'] !== '' ? $track['start_date'] : '';
        try {
            if ($track['first_meeting_date'] === '' && $track['start_override_reason'] === ''
                && $track['start_date'] === '' && $track['due_date'] === '' && $extensions === []) {
                return $this->calculate(new DeadlineInput(TrackType::Serious, $track['acknowledged_date'], ''));
            }
            $published = new DeadlineResult($track['start_date'], $track['duration_days'], $track['due_date'],
                $track['start_override_reason'], $extensions);
            $legacy = DeadlineInput::legacySerious($track['acknowledged_date'], $track['first_meeting_date'], $manual, $track['start_override_reason']);
            $result = $reason === null ? $this->calculator->previewLegacy($legacy, $published)
                : $this->calculator->confirmLegacy($legacy, $reason, $published)->after;
            return ['startDate'=>$result->startDate, 'dueDate'=>$result->dueDate, 'extensions'=>$result->extensions];
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
