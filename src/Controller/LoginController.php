<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Auth\AuthSettings;
use App\Security\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class LoginController extends AbstractController
{
    public function __invoke(Request $request, AuthSettings $settings): Response
    {
        $user = $this->getUser();
        if ($user instanceof User) {
            return $this->redirect($request->getBasePath().($user->scope->canAdminister() ? '/admin.php' : '/index.php'));
        }
        return $this->render('security/login.html.twig', ['demo' => $settings->isDemo(), 'error' => null]);
    }
}
