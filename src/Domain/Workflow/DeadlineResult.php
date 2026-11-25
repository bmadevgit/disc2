<?php

declare(strict_types=1);

namespace App\Domain\Workflow;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class DeadlineResult
{
    public string $startDate;
    public string $dueDate;
    public array $extensions;

    /** A stored schedule supplies date strings. The calculator supplies its immutable dates directly. */
    public function __construct(
        string|DateTimeImmutable $startDate,
        public int $durationDays,
        string|DateTimeImmutable $dueDate,
        public string $startOverrideReason,
        array $extensions,
    ) {
        $calculated = $startDate instanceof DateTimeImmutable;
        $this->startDate = self::resultDate($startDate, $calculated);
        $this->dueDate = self::resultDate($dueDate, $calculated);
        DeadlineInput::validateDays($durationDays);

        foreach ($extensions as $key => $extension) {
            if (!is_array($extension)) {
                throw new InvalidArgumentException('Each extension requires a duration and a due date.');
            }
            DeadlineInput::validateDays($extension['requested_days'] ?? null);
            $extensions[$key]['new_due_date'] = self::resultDate($extension['new_due_date'] ?? null, $calculated);
        }
        $this->extensions = $extensions;
    }

    private static function resultDate(mixed $value, bool $calculated): string
    {
        if ($calculated && $value instanceof DateTimeImmutable) {
            return $value->format('Y-m-d');
        }
        if (!is_string($value) || $calculated) {
            throw new InvalidArgumentException('Expected a date in the same representation as the schedule start.');
        }
        CalendarDate::parse($value);
        return $value;
    }

    public function extendedDueDate(): string
    {
        if ($this->extensions === []) {
            return $this->dueDate;
        }
        return $this->extensions[array_key_last($this->extensions)]['new_due_date'];
    }
}
