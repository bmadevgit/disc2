<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Command\UpdateCase;
use App\Form\WriteForm;
use App\Security\{SubjectIdentityVoter, User};
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;

/** The public save_case.php dispatcher checks the case voter before dispatching the page-1 form. */
final class SubjectWriteController extends AbstractController
{
    public function __construct(private readonly WriteForm $form, private readonly UpdateCase $update)
    {
    }

    public function savePageOne(User $user, int $id, array $input): Response
    {
        $fields = $this->form->caseFields($input, false);
        $subjects = $this->form->subjectRows($input['subjects'] ?? [], $this->isGranted(SubjectIdentityVoter::VIEW, $id));
        $this->update->run($user, $id, $fields, $subjects);
        return $this->redirectToRoute('case_detail', ['id'=>$id, 'page'=>1, 'saved'=>1]);
    }
}
