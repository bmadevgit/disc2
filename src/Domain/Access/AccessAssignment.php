<?php

declare(strict_types=1);

namespace App\Domain\Access;

use InvalidArgumentException;

final readonly class AccessAssignment
{
    public function __construct(
        public string $actorId,
        public AccessRole $role,
        public string $unit,
        public string $group,
        public bool $active = true,
    ) {
        if (!in_array($role, [AccessRole::Executive, AccessRole::UnitHead, AccessRole::GroupHead, AccessRole::CaseOwner], true)) {
            throw new InvalidArgumentException('Unsupported assignment role.');
        }
    }
}
