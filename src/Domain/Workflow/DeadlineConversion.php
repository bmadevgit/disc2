<?php

declare(strict_types=1);

namespace App\Domain\Workflow;

final readonly class DeadlineConversion
{
    public function __construct(
        public DeadlineResult $before,
        public DeadlineResult $after,
        public string $reason,
    ) {
    }
}
