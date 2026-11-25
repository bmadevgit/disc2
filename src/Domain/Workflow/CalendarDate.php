<?php

declare(strict_types=1);

namespace App\Domain\Workflow;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class CalendarDate
{
    public static function parse(string $value): DateTimeImmutable
    {
        if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value)) {
            throw new InvalidArgumentException('Expected a date in YYYY-MM-DD format.');
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Invalid calendar date.');
        }

        return $date;
    }
}
