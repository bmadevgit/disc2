<?php

declare(strict_types=1);

namespace App\Security;

use App\Application\Auth\AuthSettings;
use App\Application\Auth\LoginService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;
use Twig\Environment;

final class LoginAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public function __construct(
        private LoginService $logins,
        private AuthSettings $settings,
        private TokenStorageInterface $tokens,
        private Environment $twig,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request->attributes->get('_route') === 'login' && $request->isMethod('POST')
            && !$this->tokens->getToken()?->getUser() instanceof User;
    }

    public function authenticate(Request $request): SelfValidatingPassport
    {
        $form = $request->request->all();
        $user = $this->logins->login(
            is_string($form['username'] ?? null) ? $form['username'] : '',
            is_string($form['password'] ?? null) ? $form['password'] : '',
            is_string($form['login_type'] ?? null) ? $form['login_type'] : 'bma',
            (string)$request->server->get('REMOTE_ADDR'), (string)$request->headers->get('User-Agent')
        );
        return new SelfValidatingPassport(new UserBadge($user->getUserIdentifier(), static fn () => $user));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
        $admin = !$this->settings->isDemo() && $token->getUser()->scope->canAdminister();
        return new RedirectResponse($request->getBasePath().($admin ? '/admin.php' : '/index.php'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $message = $exception instanceof CustomUserMessageAuthenticationException ? $exception->getMessageKey() : 'ชื่อบัญชีหรือรหัสผ่านไม่ถูกต้อง';
        $form = $request->request->all();
        $mode = ($form['login_type'] ?? null) === 'admin' ? 'admin' : 'bma';
        $username = is_string($form['username'] ?? null) ? trim($form['username']) : '';
        if (!$this->settings->isDemo() && $mode === 'bma') {
            $username = preg_replace('/@bangkok\.go\.th$/iD', '', $username);
        }
        return new Response($this->twig->render('security/login.html.twig', [
            'demo' => $this->settings->isDemo(), 'error' => $message, 'mode' => $mode, 'username' => $username,
        ]));
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new RedirectResponse($request->getBasePath().'/login.php');
    }
}
