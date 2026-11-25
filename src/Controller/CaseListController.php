<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Query\CaseListQuery;
use App\Domain\Access\AccessRole;
use App\Security\User;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class CaseListController extends AbstractController
{
    public function __invoke(Request $request, #[CurrentUser] User $user, CaseListQuery $query): Response
    {
        if (in_array($user->scope->role, [AccessRole::Executive, AccessRole::UnitHead], true)) {
            return $this->redirectToRoute('dashboard');
        }
        if ($user->scope->role === AccessRole::None) {
            return $this->render('case/unassigned.html.twig');
        }
        return $this->render('case/index.html.twig', $query->fetch($user->scope, $request->query->all()));
    }
}
