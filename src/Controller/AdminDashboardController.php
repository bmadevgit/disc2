<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Query\DashboardQuery;
use App\Infrastructure\Persistence\Repository\AccessAssignmentRepository;
use App\Security\CaseVoter;
use App\Security\User;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;

final class AdminDashboardController extends AbstractController
{
    public function __invoke(#[CurrentUser] User $user, DashboardQuery $query): Response
    {
        if (!$this->isGranted(CaseVoter::DASHBOARD)) {
            return $this->redirectToRoute('case_index');
        }
        return $this->render('admin/dashboard.html.twig', $query->fetch($user->scope));
    }

    public function roles(AccessAssignmentRepository $assignments): Response
    {
        $this->denyAccessUnlessGranted(CaseVoter::ADMIN);
        return $this->render('admin/roles.html.twig', ['rows'=>$assignments->all()]);
    }
}
