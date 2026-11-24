<?php

declare(strict_types=1);

namespace App\Security;

use App\Domain\Access\CaseScope;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final readonly class User implements UserInterface, EquatableInterface
{
    public function __construct(
        public CaseScope $scope,
        public string $name,
        public string $email,
        public bool $local,
        public string $sessionUnit,
        public string $credentialVersion = '',
    ) {
    }

    public function getRoles(): array
    {
        return $this->scope->canAdminister() ? ['ROLE_USER', 'ROLE_ADMIN'] : ['ROLE_USER'];
    }

    public function getUserIdentifier(): string
    {
        return ($this->local ? 'local:' : 'bma:').$this->scope->actorId;
    }

    public function eraseCredentials(): void
    {
    }

    public function isEqualTo(UserInterface $user): bool
    {
        return $user instanceof self && $this->getUserIdentifier() === $user->getUserIdentifier()
            && $this->credentialVersion === $user->credentialVersion && $this->getRoles() === $user->getRoles();
    }
}
