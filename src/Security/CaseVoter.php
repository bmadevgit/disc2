<?php

declare(strict_types=1);

namespace App\Security;

use App\Domain\Access\CaseScope;
use App\Infrastructure\Persistence\Repository\CaseRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/** @extends Voter<string, mixed> */
final class CaseVoter extends Voter
{
    public const READ = 'CASE_READ';
    public const WRITE = 'CASE_WRITE';
    public const CREATE = 'CASE_CREATE';
    public const ADMIN = 'DISC_ADMIN';
    public const DASHBOARD = 'DASHBOARD_VIEW';
    public const DASHBOARD_UNIT = 'DASHBOARD_UNIT';

    public function __construct(private readonly CaseRepository $cases)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::READ, self::WRITE, self::CREATE, self::ADMIN, self::DASHBOARD, self::DASHBOARD_UNIT], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }
        $scope = $user->scope;
        return match ($attribute) {
            self::ADMIN => $scope->canAdminister(),
            self::CREATE => $scope->canCreate(),
            self::DASHBOARD => $scope->canViewDashboardUnit($scope->unit),
            self::DASHBOARD_UNIT => is_string($subject) && $scope->canViewDashboardUnit($subject),
            self::READ, self::WRITE => $this->caseDecision($scope, $subject, $attribute),
        };
    }

    /** Route attributes arrive as strings until PHP invokes the controller. */
    public static function caseId(mixed $subject): ?int
    {
        if (!is_int($subject) && !is_string($subject)) {
            return null;
        }
        $id = filter_var($subject, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : $id;
    }

    private function caseDecision(CaseScope $scope, mixed $subject, string $attribute): bool
    {
        $id = self::caseId($subject);
        if ($id === null) {
            return false;
        }
        if ($scope->canAdminister()) {
            // Authorized callers can distinguish a missing record (404).
            return true;
        }
        $case = $this->cases->access($id);
        return $case !== null && ($attribute === self::READ ? $scope->canRead($case) : $scope->canWrite($case));
    }
}
