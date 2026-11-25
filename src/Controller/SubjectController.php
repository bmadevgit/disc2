<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Query\SubjectQuery;
use App\Security\CaseVoter;
use App\Security\SubjectIdentityVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class SubjectController extends AbstractController
{
    public function __invoke(Request $request, SubjectQuery $query): Response
    {
        $caseId = ReadParameters::integer($request, 'case_id');
        $this->denyAccessUnlessGranted(CaseVoter::READ, $caseId);
        $subjectId = ReadParameters::integer($request, 'subject_id');
        $data = $query->fetch($caseId, $subjectId, $this->isGranted(SubjectIdentityVoter::VIEW, $caseId));
        if ($data === null) {
            return new Response('ไม่พบสำนวน', 404);
        }
        if ($subjectId && !$data['person']) {
            return new Response('ไม่พบผู้ถูกกล่าวหาในสำนวนนี้', 404);
        }
        return $this->render('subject/detail.html.twig', $data);
    }
}
