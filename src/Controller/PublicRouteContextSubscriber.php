<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RequestContext;

/** index.php is itself a bookmarked page at the application mount. */
final readonly class PublicRouteContextSubscriber implements EventSubscriberInterface
{
    public function __construct(private RequestContext $context)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // Routing has matched the request. Generate outgoing links before the firewall redirects.
        return [KernelEvents::REQUEST => ['useApplicationMount', 16]];
    }

    public function useApplicationMount(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $mount = $request->getBasePath();
        if ($request->getBaseUrl() === $mount.'/index.php') {
            $this->context->setBaseUrl($mount);
        }
    }
}
