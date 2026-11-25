<?php

declare(strict_types=1);

namespace App\Twig;

use App\Application\Query\WorkflowLabels;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class WorkflowExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [new TwigFilter('track_title', WorkflowLabels::trackTitle(...))];
    }
}
