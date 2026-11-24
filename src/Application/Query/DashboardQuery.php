<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Access\CaseScope;
use App\Infrastructure\Persistence\Repository\CaseRepository;
use App\Infrastructure\Persistence\Repository\TrackRepository;
use DateTimeImmutable;
use DateTimeZone;

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
            $today = new DateTimeImmutable('today', $zone);
            foreach ($this->tracks->deadlines() as $track) {
                $left = (int)$today->diff(new DateTimeImmutable($track['effective_due'], $zone))->format('%r%a');
                if ($left <= 30) {
                    $items[] = $track + ['left'=>$left];
                }
            }
        }
        return ['stats'=>$this->cases->dashboardCounts($scope), 'items'=>$items];
    }
}
