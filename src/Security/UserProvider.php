<?php

declare(strict_types=1);

namespace App\Security;

use App\Application\Auth\AuthSettings;
use App\Domain\Access\CaseScope;
use App\Infrastructure\Persistence\Repository\AccessAssignmentRepository;
use App\Infrastructure\Persistence\Repository\UserRepository;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/** @implements UserProviderInterface<User> */
final readonly class UserProvider implements UserProviderInterface
{
    public function __construct(private UserRepository $users, private AccessAssignmentRepository $assignments, private AuthSettings $settings)
    {
    }

    public function fromLocal(array $row): User
    {
        $id = (string)$row['id'];
        return new User($this->scope($id, $row['role'] === 'admin', ''), $row['name'], $row['username'], true, '', hash('sha256', $row['password_hash']));
    }

    public function fromBma(string $id, string $name, string $email, string $unit): User
    {
        return new User($this->scope($id, false, $unit), $name, $email, false, $unit);
    }

    private function scope(string $id, bool $admin, string $unit): CaseScope
    {
        return CaseScope::forActor($id, $admin, $this->settings->isSuperAdmin($id), $this->assignments->activeFor($id), $unit);
    }

    public function refreshUser(UserInterface $user): User
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException();
        }
        if ($user->local) {
            $row = $this->users->byUsername($user->email, !$this->settings->isDemo());
            if ($row === null || (string)$row['id'] !== $user->scope->actorId) {
                throw new UserNotFoundException();
            }
            return $this->fromLocal($row);
        }
        $unit = $this->users->unitFor($user->scope->actorId);
        if ($unit === null || $unit === '' || $this->settings->isDemo()) {
            throw new UserNotFoundException();
        }
        return $this->fromBma($user->scope->actorId, $user->name, $user->email, $unit);
    }

    public function loadUserByIdentifier(string $identifier): User
    {
        if (str_starts_with($identifier, 'local:')) {
            $row = $this->users->byId(substr($identifier, 6), !$this->settings->isDemo());
            if ($row !== null) {
                return $this->fromLocal($row);
            }
        } elseif (str_starts_with($identifier, 'bma:') && !$this->settings->isDemo()) {
            $id = substr($identifier, 4);
            $account = $this->users->bmaAccount($id);
            if ($account !== null) {
                return $this->fromBma($id, $account['name'] ?: $account['bma_email'], $account['bma_email'], $account['unit_level1']);
            }
        }
        throw new UserNotFoundException();
    }

    public function supportsClass(string $class): bool
    {
        return $class === User::class;
    }
}
