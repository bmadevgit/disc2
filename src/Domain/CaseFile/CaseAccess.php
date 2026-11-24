<?php

declare(strict_types=1);

namespace App\Domain\CaseFile;

final readonly class CaseAccess
{
    /** @param list<array{bma_personal_id: string, ended_at: ?string}> $ownerships Ownerships of this case. */
    public function __construct(
        public int $id,
        public string $unit,
        public string $group,
        private array $ownerships,
    ) {
    }

    public function isOwnedBy(string $actorId): bool
    {
        foreach ($this->ownerships as $ownership) {
            if (($ownership['bma_personal_id'] ?? null) === $actorId
                && array_key_exists('ended_at', $ownership) && $ownership['ended_at'] === null) {
                return true;
            }
        }
        return false;
    }
}
