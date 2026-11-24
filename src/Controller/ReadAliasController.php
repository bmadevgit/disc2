<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\CaseVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ReadAliasController extends AbstractController
{
    public function index(): Response
    {
        return $this->redirectToRoute('case_index');
    }

    public function detail(Request $request): Response
    {
        return $this->redirectToRoute('case_detail', ['id'=>ReadParameters::integer($request, 'id')]);
    }

    public function users(): Response
    {
        $this->denyAccessUnlessGranted(CaseVoter::ADMIN);
        return $this->redirectToRoute('access_roles');
    }
}
