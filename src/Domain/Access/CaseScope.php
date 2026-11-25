<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Domain\CaseFile\CaseAccess;

final readonly class CaseScope
{
    private function __construct(
        public AccessRole $role,
        public string $actorId = '',
        public string $unit = '',
        public string $group = '',
    ) {
    }

    public static function forActor(
        ?string $actorId,
        bool $adminSession = false,
        bool $configuredSuperAdmin = false,
        ?AccessAssignment $assignment = null,
        string $sessionUnit = '',
    ): self {
        if ($actorId === null || $actorId === '') {
            return new self(AccessRole::Anonymous);
        }
        if ($adminSession || $configuredSuperAdmin) {
            return new self(AccessRole::Admin, $actorId);
        }
        if ($assignment?->active && $assignment->actorId === $actorId) {
            return new self($assignment->role, $actorId, $assignment->unit, $assignment->group);
        }
        return new self($sessionUnit === '' ? AccessRole::None : AccessRole::CaseOwner, $actorId, $sessionUnit);
    }

    /** Both the case list and the detail page use this same row scope. */
    public function canRead(CaseAccess $case): bool
    {
        if ($this->role === AccessRole::GroupHead) {
            return $this->unit === $case->unit && $this->group === $case->group;
        }
        return $this->canWrite($case);
    }

    public function canWrite(CaseAccess $case): bool
    {
        return $this->canAdminister() || ($this->role === AccessRole::CaseOwner && $case->isOwnedBy($this->actorId));
    }

    public function canCreate(): bool
    {
        return $this->canAdminister() || $this->role === AccessRole::CaseOwner;
    }

    public function canAdminister(): bool
    {
        return $this->role === AccessRole::Admin;
    }

    public function canViewDashboardUnit(string $unit): bool
    {
        return match ($this->role) {
            AccessRole::Admin, AccessRole::Executive => true,
            AccessRole::UnitHead => $this->unit === $unit,
            default => false,
        };
    }
}
