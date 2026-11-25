<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Query\DashboardAssistant;
use App\Security\User;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[AsController]
final class DashboardAiController
{
    public function __invoke(#[CurrentUser] User $user, DashboardAssistant $assistant): JsonResponse
    {
        $result = $assistant->summarize($user->scope);
        return new JsonResponse($result, $result['ok'] ? 200 : 503);
    }
}
