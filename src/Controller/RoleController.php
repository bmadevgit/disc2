<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Command\{AssignRole, WriteFailure};
use App\Form\WriteForm;
use App\Security\{CaseVoter, User};
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class RoleController extends AbstractController
{
    public function __invoke(Request $request, #[CurrentUser] User $user, WriteForm $form, AssignRole $assign): Response
    {
        $this->denyAccessUnlessGranted(CaseVoter::ADMIN);
        try {
            $fields = $form->assignment($request->request->all());
        } catch (WriteFailure) {
            return $this->redirectToRoute('access_roles', ['saved'=>1]);
        }
        $assign->run($user, $fields);
        return $this->redirectToRoute('access_roles', ['saved'=>1]);
    }
}
