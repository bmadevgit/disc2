<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Query\CaseViewQuery;
use App\Security\CaseVoter;
use App\Security\SubjectIdentityVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class CaseViewController extends AbstractController
{
    public function __invoke(Request $request, CaseViewQuery $query): Response
    {
        $id = ReadParameters::integer($request, 'id');
        $this->denyAccessUnlessGranted(CaseVoter::READ, $id);
        $page = max(1, min(4, ReadParameters::integer($request, 'page', 1)));
        $data = $query->fetch($id, $page, $this->isGranted(SubjectIdentityVoter::VIEW, $id));
        if ($data === null) {
            return new Response('ไม่พบสำนวน', 404);
        }
        $data['edit'] = $this->isGranted(CaseVoter::WRITE, $id);
        return $this->render('case/detail.html.twig', $data);
    }
}
