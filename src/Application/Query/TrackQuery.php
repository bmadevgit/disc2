<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Workflow\{DeadlineCalculator, DeadlineInput, TrackType};
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
        $preview = null;
        if ($edit && $days === 180 && ($tr['first_meeting_date'] !== '' || $tr['start_override_reason'] !== '')) {
            $manual = $tr['start_override_reason'] !== '' ? $tr['start_date'] : '';
            try {
                $preview = $this->calculator->calculate(new DeadlineInput($type, $tr['acknowledged_date'], $tr['first_meeting_date'],
                    manualStart: $manual, startOverrideReason: $tr['start_override_reason'], extensions: $ext));
            } catch (InvalidArgumentException) {
                // Stored schedules can predate the validation rules for new deadlines.
                $preview = null;
            }
        }
        return compact('caseId', 'tr', 'ext', 'days', 'preview', 'edit') + ['type'=>$type->value, 'title'=>WorkflowLabels::trackTitle($tr),
            'revision'=>hash('sha256', serialize([$tr, $ext])), 'orders'=>$this->committees->orders($tr['id'])];
    }
}
