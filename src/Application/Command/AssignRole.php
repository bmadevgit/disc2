<?php

declare(strict_types=1);

namespace App\Application\Command;

use App\Application\Auth\CaseAudit;
use App\Domain\Access\AccessAssignment;
use App\Domain\Access\AccessRole;
use App\Infrastructure\Persistence\Repository\AccessAssignmentRepository;
use App\Infrastructure\Persistence\TransactionManager;
use App\Security\User;

final readonly class AssignRole
{
    public function __construct(private WriteAccess $access, private TransactionManager $transaction,
        private AccessAssignmentRepository $assignments, private CaseAudit $audit)
    {
    }

    public function run(User $user, array $fields): void
    {
        $assignment = new AccessAssignment($fields['personal_id'], AccessRole::from($fields['access_role']),
            $fields['unit_level1'], $fields['unit_level2'], $fields['active']);
        $this->transaction->run(function () use ($user, $assignment, $fields) {
            $this->access->administrator($user);
            $this->assignments->save($assignment, $fields['display_name']);
            $this->audit->record(null, 'assign_role', json_encode([
                'personal_id'=>$assignment->actorId, 'role'=>$assignment->role->value, 'active'=>$assignment->active,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        });
    }
}
