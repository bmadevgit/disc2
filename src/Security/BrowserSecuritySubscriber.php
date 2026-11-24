<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final readonly class BrowserSecuritySubscriber implements EventSubscriberInterface
{
    public function __construct(private CsrfTokenManagerInterface $csrf, private string $projectDir)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // Only the login authenticator consumes its POST inside the firewall.
        return [KernelEvents::REQUEST => [['checkLogin', 10], ['checkProtectedForm', 7]],
            KernelEvents::RESPONSE => ['secureResponse', -1010]];
    }

    public function checkLogin(RequestEvent $event): void
    {
        if ($event->getRequest()->attributes->get('_route') === 'login') {
            $this->checkForm($event);
        }
    }

    public function checkProtectedForm(RequestEvent $event): void
    {
        if ($event->getRequest()->attributes->get('_route') !== 'login') {
            $this->checkForm($event);
        }
    }

    private function checkForm(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || $request->isMethodSafe() || in_array($request->attributes->get('_route'), ['retired_save', 'retired_update_case', 'retired_update_unit_target'], true)) {
            return;
        }
        $value = $request->request->all()['csrf'] ?? null;
        if (!is_string($value) || !$this->csrf->isTokenValid(new CsrfToken('disc2_form', $value))) {
            $event->setResponse(new Response('คำขอหมดอายุ กรุณาลองใหม่', 419));
        }
    }

    public function secureResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $headers = $event->getResponse()->headers;
        $headers->set('Cache-Control', 'private, no-cache, no-store, max-age=0, must-revalidate');
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'SAMEORIGIN');
        $headers->set('Referrer-Policy', 'same-origin');
        $path = $request->getBasePath() ?: '/';
        if ($request->hasSession(true)) {
            $name = $request->getSession()->getName();
            foreach ($headers->getCookies() as $cookie) {
                if ($cookie->getName() === $name) {
                    $headers->removeCookie($name, $cookie->getPath(), $cookie->getDomain());
                    $headers->setCookie($cookie->withPath($path)->withSecure($request->isSecure())->withHttpOnly(true)->withSameSite('lax'));
                }
            }
        }
        $legacyDevName = 'disc2_dev_'.substr(hash('sha256', $this->projectDir), 0, 12);
        foreach ($request->cookies->keys() as $name) {
            if ($name === 'disc2_uat' || $name === $legacyDevName) {
                foreach (array_unique(['/', $path]) as $oldPath) {
                    $headers->clearCookie($name, $oldPath, secure: $request->isSecure(), sameSite: 'lax');
                }
            }
        }
    }
}
