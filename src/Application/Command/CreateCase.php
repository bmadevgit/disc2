<?php

declare(strict_types=1);

namespace App\Application\Command;

use App\Application\Auth\CaseAudit;
use App\Infrastructure\Persistence\Repository\CaseRepository;
use App\Infrastructure\Persistence\TransactionManager;
use App\Security\User;

final readonly class CreateCase
{
    public function __construct(private WriteAccess $access, private TransactionManager $transaction,
        private CaseRepository $cases, private CaseInformation $information, private CaseAudit $audit)
    {
    }

    public function run(User $user, array $fields): int
    {
        return $this->transaction->run(function () use ($user, $fields) {
            $actor = $this->access->editor($user);
            $unit = $this->information->unit($actor, $fields);
            $id = $this->cases->create($fields['case_number'], $unit, $actor->scope->actorId, $actor->name);
            $this->information->save($id, $unit, $fields, '');
            $this->cases->addOwner($id, $actor->scope->actorId, $actor->name);
            $this->audit->record($id, 'create_case');
            return $id;
        });
    }
}
