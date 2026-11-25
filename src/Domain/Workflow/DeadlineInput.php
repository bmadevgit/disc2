<?php

declare(strict_types=1);

namespace App\Domain\Workflow;

use InvalidArgumentException;

final readonly class DeadlineInput
{
    /** @param list<array{requested_days: int, ...}> $extensions In sequence order. */
    public function __construct(
        public TrackType $type,
        public string $acknowledgedDate,
        public string $firstMeetingDate,
        public ?int $durationDays = null,
        public string $manualStart = '',
        public string $startOverrideReason = '',
        public array $extensions = [],
        public bool $legacy = false,
    ) {
    }

    public static function validateDays(mixed $days): void
    {
        if (!is_int($days) || $days < 1 || $days > 365000) {
            throw new InvalidArgumentException('Expected a duration from 1 to 365000 whole days.');
        }
    }

    /** Reconstitute an existing 180-day serious round. */
    public static function legacySerious(
        string $acknowledgedDate,
        string $firstMeetingDate,
        string $manualStart = '',
        string $startOverrideReason = '',
        array $extensions = [],
    ): self {
        return new self(TrackType::Serious, $acknowledgedDate, $firstMeetingDate, 180,
            $manualStart, $startOverrideReason, $extensions, legacy: true);
    }
}
