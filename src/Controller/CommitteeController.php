<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Command\{CreateCommittee, SaveReferral, WriteFailure};
use App\Security\{CaseVoter, User};
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class CommitteeController extends AbstractController
{
    public function __invoke(Request $request, #[CurrentUser] User $user, CreateCommittee $committee, SaveReferral $referral): Response
    {
        $input = $request->request->all();
        $id = CaseVoter::caseId($input['case_id'] ?? null);
        $this->denyAccessUnlessGranted(CaseVoter::WRITE, $id);
        try {
            switch ($input['action'] ?? '') {
                case 'committee':
                    $committee->run($user, $id, $input);
                    $tab = 3;
                    break;
                case 'referral':
                    $referral->run($user, $id, $input);
                    $tab = 2;
                    break;
                default:
                    return new Response('ไม่พบคำสั่งบันทึก', 422);
            }
            return $this->redirectToRoute('case_detail', ['id'=>$id,'page'=>$tab,'saved'=>1]);
        } catch (WriteFailure $error) {
            return WriteErrorResponse::from($error);
        }
    }
}
