<?php

declare(strict_types=1);

namespace App\Domain\Workflow;

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
