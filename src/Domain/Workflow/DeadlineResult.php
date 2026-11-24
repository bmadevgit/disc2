<?php

declare(strict_types=1);

namespace App\Domain\Workflow;

final readonly class DeadlineResult
{
    public function __construct(
        public string $startDate,
        public int $durationDays,
        public string $dueDate,
        public string $startOverrideReason,
        public array $extensions,
    ) {
    }

    public function extendedDueDate(): string
    {
        if ($this->extensions === []) {
            return $this->dueDate;
        }
        return $this->extensions[array_key_last($this->extensions)]['new_due_date'];
    }
}
