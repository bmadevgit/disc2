<?php

declare(strict_types=1);

namespace App\Application\Command;

use App\Infrastructure\Persistence\Repository\CaseRepository;
use App\Security\User;
use App\Security\UserProvider;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;

final readonly class WriteAccess
{
    public function __construct(private UserProvider $users, private CaseRepository $cases)
    {
    }

    /** Recheck the actor and active ownership under the same writer lock as the mutation. */
    public function editor(User $user, ?int $caseId = null): User
    {
        $actor = $this->current($user);
        if (!$actor->scope->canCreate()) {
            throw new AccessDeniedException();
        }
        if ($caseId !== null) {
            $case = $this->cases->access($caseId);
            if ($case === null && $actor->scope->canAdminister()) {
                throw new WriteFailure('ไม่พบสำนวน', 404);
            }
            if ($case === null || !$actor->scope->canWrite($case)) {
                throw new AccessDeniedException();
            }
        }
        return $actor;
    }

    public function administrator(User $user): void
    {
        if (!$this->current($user)->scope->canAdminister()) {
            throw new AccessDeniedException();
        }
    }

    private function current(User $user): User
    {
        try {
            $current = $this->users->refreshUser($user);
            if (!$user->isEqualTo($current)) {
                throw new AccessDeniedException();
            }
            return $current;
        } catch (UserNotFoundException) {
            throw new AccessDeniedException();
        }
    }
}
