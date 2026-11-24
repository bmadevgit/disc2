<?php

declare(strict_types=1);

namespace App\Application\Command;

use App\Application\Auth\CaseAudit;
use App\Infrastructure\Persistence\Repository\CaseRepository;
use App\Infrastructure\Persistence\TransactionManager;
use App\Security\User;

final readonly class UpdateCase
{
    public function __construct(private WriteAccess $access, private TransactionManager $transaction,
        private CaseRepository $cases, private CaseInformation $information, private SaveSubject $subjects, private CaseAudit $audit)
    {
    }

    public function run(User $user, int $id, array $fields, array $subjects): void
    {
        $this->transaction->run(function () use ($user, $id, $fields, $subjects) {
            $actor = $this->access->editor($user, $id);
            $case = $this->cases->find($id);
            $unit = $this->information->unit($actor, $fields);
            $this->cases->updateHeader($id, $fields['case_number'], $unit);
            $this->information->save($id, $unit, $fields, $case['case_type'] ?? '');
            foreach ($subjects as $index => $subject) {
                $this->subjects->apply($id, $index + 1, $subject, $actor->scope->canAdminister(), count($subjects));
            }
            $this->audit->record($id, 'save_case_info');
        });
    }
}
