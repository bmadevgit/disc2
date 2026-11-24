<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Workflow\{CalendarDate, DeadlineCalculator, DeadlineInput, TrackType};
use App\Infrastructure\Persistence\Repository\{CommitteeRepository, ExtensionRepository, TrackRepository};
use InvalidArgumentException;

final readonly class TrackQuery
{
    public function __construct(private TrackRepository $tracks, private CommitteeRepository $committees,
        private ExtensionRepository $extensions, private DeadlineCalculator $calculator)
    {
    }

    public function fetch(int $caseId, TrackType $type, int $trackId, bool $edit): ?array
    {
        $tr = $this->tracks->find($caseId, $type, $trackId);
        if (!$tr) {
            return null;
        }
        $ext = $this->extensions->forTrack($tr['id']);
        $days = $type === TrackType::Serious && $tr['duration_days'] === 180 ? 180 : $type->defaultDays();
        $preview = $edit && $days === 180 ? $this->conversionPreview($tr, $ext) : null;
        return compact('caseId', 'tr', 'ext', 'days', 'preview', 'edit') + ['type'=>$type->value, 'title'=>WorkflowLabels::trackTitle($tr),
            'revision'=>hash('sha256', serialize([$tr, $ext])), 'orders'=>$this->committees->orders($tr['id'])];
    }

    /** A blank date is a valid draft value. A null preview means conversion is unavailable. */
    private function conversionPreview(array $track, array $extensions): ?array
    {
        try {
            if ($track['first_meeting_date'] === '' && $track['start_override_reason'] === ''
                && $track['start_date'] === '' && $track['due_date'] === '' && $extensions === []) {
                if ($track['acknowledged_date'] !== '') {
                    CalendarDate::parse($track['acknowledged_date']);
                }
                return ['startDate'=>'', 'dueDate'=>'', 'extensions'=>[]];
            }
            $manual = $track['start_override_reason'] !== '' ? $track['start_date'] : '';
            $result = $this->calculator->calculate(new DeadlineInput(TrackType::Serious, $track['acknowledged_date'],
                $track['first_meeting_date'], manualStart: $manual, startOverrideReason: $track['start_override_reason'],
                extensions: $extensions));
            return ['startDate'=>$result->startDate, 'dueDate'=>$result->dueDate, 'extensions'=>$result->extensions];
        } catch (InvalidArgumentException) {
            // Stored schedules can predate the validation rules for new deadlines.
            return null;
        }
    }
}
