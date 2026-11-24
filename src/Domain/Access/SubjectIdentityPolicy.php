<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Domain\CaseFile\CaseAccess;
use App\Domain\CaseFile\SubjectIdentity;

final class SubjectIdentityPolicy
{
    public function label(CaseScope $scope, CaseAccess $case, SubjectIdentity $subject): ?string
    {
        if ($case->id !== $subject->caseId || !$scope->canRead($case)) {
            return null;
        }
        if ($scope->canAdminister()) {
            $name = trim("$subject->title $subject->firstName $subject->lastName");
            if ($name !== '') {
                return $name;
            }
        }
        return $subject->reference !== '' ? $subject->reference : 'ผู้ถูกกล่าวหารายที่ '.($subject->sequence ?? $subject->subjectId);
    }

    public function matches(CaseScope $scope, CaseAccess $case, SubjectIdentity $subject, string $term): bool
    {
        $label = $this->label($scope, $case, $subject);
        return $label !== null && (mb_stripos($label, $term) !== false || mb_stripos($subject->reference, $term) !== false);
    }
}
