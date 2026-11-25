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
        return $subject->displayLabel($scope->canAdminister());
    }

    public function matches(CaseScope $scope, CaseAccess $case, SubjectIdentity $subject, string $term): bool
    {
        $label = $this->label($scope, $case, $subject);
        return $label !== null && (mb_stripos($label, $term) !== false || mb_stripos($subject->reference, $term) !== false);
    }
}
