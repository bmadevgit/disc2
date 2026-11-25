<?php

declare(strict_types=1);

namespace App\Application\Command;

use App\Domain\CaseFile\CaseType;
use App\Infrastructure\Persistence\Repository\CaseRepository;
use App\Security\User;

final readonly class CaseInformation
{
    public function __construct(private CaseRepository $cases)
    {
    }

    public function unit(User $actor, array $fields): string
    {
        return $actor->scope->canAdminister() ? $fields['responsible_unit'] : ($actor->scope->unit ?: $actor->sessionUnit);
    }

    public function save(int $id, string $unit, array $fields, string $storedType): void
    {
        $this->cases->saveMetadata($id, $unit, $fields['unit_level2'], $fields['source_type'] ?? '',
            $fields['source_other'] ?? '', $fields['received_date'], $fields['preliminary_allegation']);
        if (isset($fields['case_type'])) {
            $type = $fields['case_type'];
            if ($type !== '' && $type !== $storedType && !in_array($type, CaseType::CHOICES, true)) {
                throw new WriteFailure('ประเภทสำนวนไม่ถูกต้อง');
            }
            $this->cases->setType($id, $type, $type === 'อื่นๆ' ? $fields['case_type_other'] : '');
        }
    }
}
