<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class LogoutController extends AbstractController
{
    public function __invoke(Request $request, TokenStorageInterface $tokens): Response
    {
        if ($this->getUser() && $request->isMethod('GET')) {
            return $this->render('security/logout.html.twig');
        }
        if ($request->isMethod('POST')) {
            $tokens->setToken(null);
            $request->getSession()->invalidate();
        }
        return $this->redirectToRoute('login');
    }
}
