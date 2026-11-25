<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Access\CaseScope;
use App\Domain\Workflow\CalendarDate;
use App\Infrastructure\Persistence\Repository\CaseRepository;
use App\Infrastructure\Persistence\Repository\TrackRepository;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class DashboardQuery
{
    public function __construct(private CaseRepository $cases, private TrackRepository $tracks)
    {
    }

    public function fetch(CaseScope $scope): array
    {
        $items = [];
        if ($scope->canAdminister()) {
            $zone = new DateTimeZone('Asia/Bangkok');
            $today = CalendarDate::parse((new DateTimeImmutable('today', $zone))->format('Y-m-d'));
            foreach ($this->tracks->deadlines() as $track) {
                try {
                    $due = CalendarDate::parse($track['effective_due']);
                } catch (InvalidArgumentException) {
                    continue;
                }
                $left = (int)$today->diff($due)->format('%r%a');
                if ($left <= 30) {
                    $items[] = $track + ['left'=>$left];
                }
            }
        }
        return ['stats'=>$this->cases->dashboardCounts($scope), 'items'=>$items];
    }
}
