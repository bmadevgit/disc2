<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Application\TrackSchedule;
use App\Domain\Workflow\TrackType;
use App\Infrastructure\Persistence\Repository\{CommitteeRepository, ExtensionRepository, TrackRepository};

final readonly class TrackQuery
{
    public function __construct(private TrackRepository $tracks, private CommitteeRepository $committees,
        private ExtensionRepository $extensions, private TrackSchedule $schedule)
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
        $preview = $edit && $days === 180 ? $this->schedule->conversion($tr, $ext) : null;
        return compact('caseId', 'tr', 'ext', 'days', 'preview', 'edit') + ['type'=>$type->value, 'title'=>WorkflowLabels::trackTitle($tr),
            'revision'=>TrackSchedule::revision($tr, $ext), 'orders'=>$this->committees->orders($tr['id'])];
    }
}
