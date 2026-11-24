<?php

declare(strict_types=1);

namespace App\Domain\Workflow;

use InvalidArgumentException;

final readonly class DeadlineResult
{
    public function __construct(
        public string $startDate,
        public int $durationDays,
        public string $dueDate,
        public string $startOverrideReason,
        public array $extensions,
    ) {
        CalendarDate::parse($startDate);
        CalendarDate::parse($dueDate);
        DeadlineInput::validateDays($durationDays);

        foreach ($extensions as $extension) {
            if (!is_array($extension) || !is_string($extension['new_due_date'] ?? null)) {
                throw new InvalidArgumentException('Each published extension requires a recorded due date.');
            }
            DeadlineInput::validateDays($extension['requested_days'] ?? null);
            CalendarDate::parse($extension['new_due_date']);
        }
    }

    public function extendedDueDate(): string
    {
        if ($this->extensions === []) {
            return $this->dueDate;
        }
        return $this->extensions[array_key_last($this->extensions)]['new_due_date'];
    }
}
