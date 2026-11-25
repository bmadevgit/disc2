<?php

declare(strict_types=1);

namespace App\Domain\Workflow;

enum TrackType: string
{
    case Fact = 'fact';
    case NonSerious = 'discipline_nonserious';
    case Serious = 'discipline_serious';

    public function defaultDays(): int
    {
        return match ($this) {
            self::Fact => 30,
            self::NonSerious => 60,
            self::Serious => 120,
        };
    }
}
