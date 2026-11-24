<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Workflow\TrackType;
use App\Infrastructure\Persistence\Repository\TrackRepository;

final readonly class DisciplineResultsQuery
{
    public function __construct(private TrackRepository $tracks)
    {
    }

    public function fetch(int $caseId, TrackType $type, int $trackId, bool $edit, bool $identity): ?array
    {
        $track = $this->tracks->find($caseId, $type, $trackId);
        if (!$track) {
            return null;
        }
        $persons = array_map(static fn ($row) => SubjectPresentation::forReader($row, $identity), $this->tracks->membersWithResults($track['id']));
        return compact('caseId', 'track', 'persons', 'edit') + ['type'=>$type->value,
            'seriousTracks'=>$edit && $type === TrackType::NonSerious ? $this->tracks->seriousAppointments($caseId) : []];
    }
}
