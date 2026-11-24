<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Command\{CreateCase, WriteFailure};
use App\Form\WriteForm;
use App\Security\{CaseVoter, User};
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class CaseWriteController extends AbstractController
{
    public function __invoke(Request $request, #[CurrentUser] User $user, WriteForm $form, CreateCase $create, SubjectWriteController $subjects): Response
    {
        if (!$request->isMethod('POST')) {
            return new Response('ต้องส่งแบบฟอร์ม', 405, ['Allow'=>'POST']);
        }
        $input = $request->request->all();
        $action = $input['action'] ?? '';
        try {
            if ($action === 'create_case') {
                $this->denyAccessUnlessGranted(CaseVoter::CREATE);
                $id = $create->run($user, $form->caseFields($input, true));
                return $this->redirectToRoute('case_detail', ['id'=>$id, 'page'=>1, 'saved'=>1]);
            }
            $id = CaseVoter::caseId($input['case_id'] ?? null);
            if ($id === null) {
                return new Response('ไม่พบสำนวน', 404);
            }
            $this->denyAccessUnlessGranted(CaseVoter::WRITE, $id);
            if ($action === 'save_page1') {
                return $subjects->savePageOne($user, $id, $input);
            }
            return new Response('ไม่พบรายการที่ต้องการบันทึก', 400);
        } catch (WriteFailure $error) {
            return new Response($error->getMessage(), $error->getCode());
        }
    }
}
