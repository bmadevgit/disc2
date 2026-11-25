<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Query\DisciplineResultsQuery;
use App\Domain\Workflow\TrackType;
use App\Security\CaseVoter;
use App\Security\SubjectIdentityVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class DisciplineResultsController extends AbstractController
{
    public function __invoke(Request $request, DisciplineResultsQuery $query): Response
    {
        $caseId = ReadParameters::integer($request, 'case_id');
        $this->denyAccessUnlessGranted(CaseVoter::READ, $caseId);
        $value = $request->query->all()['type'] ?? '';
        $type = is_string($value) ? TrackType::tryFrom($value) : null;
        if (!$type || $type === TrackType::Fact) {
            throw $this->createAccessDeniedException();
        }
        $data = $query->fetch($caseId, $type, ReadParameters::integer($request, 'track_id'), $this->isGranted(CaseVoter::WRITE, $caseId),
            $this->isGranted(SubjectIdentityVoter::VIEW, $caseId));
        if ($data === null) {
            return new Response('ไม่พบกระบวนการสอบสวน', 404);
        }
        return $this->render('discipline/results.html.twig', $data);
    }
}
