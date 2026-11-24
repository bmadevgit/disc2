<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Query\TrackQuery;
use App\Domain\Workflow\TrackType;
use App\Security\CaseVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class TrackController extends AbstractController
{
    public function __invoke(Request $request, TrackQuery $query): Response
    {
        $caseId = ReadParameters::integer($request, 'case_id');
        $this->denyAccessUnlessGranted(CaseVoter::READ, $caseId);
        $value = $request->query->all()['type'] ?? '';
        $type = is_string($value) ? TrackType::tryFrom($value) : null;
        if (!$type || $type === TrackType::Fact) {
            throw $this->createAccessDeniedException();
        }
        $data = $query->fetch($caseId, $type, ReadParameters::integer($request, 'track_id'), $this->isGranted(CaseVoter::WRITE, $caseId));
        if ($data === null) {
            return new Response('ไม่พบกระบวนการสอบสวน', 404);
        }
        return $this->render('track/detail.html.twig', $data);
    }
}
