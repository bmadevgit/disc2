<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Command\{SaveDisciplineResult, WriteFailure};
use App\Security\{CaseVoter, User};
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class DisciplineWriteController extends AbstractController
{
    public function __invoke(Request $request, #[CurrentUser] User $user, SaveDisciplineResult $save): Response
    {
        if (!$request->isMethod('POST')) {
            return new Response('ต้องส่งแบบฟอร์ม', 405, ['Allow'=>'POST']);
        }
        $input = $request->request->all();
        $id = CaseVoter::caseId($input['case_id'] ?? null);
        $this->denyAccessUnlessGranted(CaseVoter::WRITE, $id);
        try {
            $track = $save->run($user, $id, $input);
            return $this->redirectToRoute('discipline_results', ['case_id'=>$id,'type'=>$track['track_type'],'track_id'=>$track['id'],'saved'=>1]);
        } catch (WriteFailure $error) {
            return WriteErrorResponse::from($error);
        }
    }
}
