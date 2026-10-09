<?php
declare(strict_types=1);

namespace Picklers\Admin\EventSubscriber;

use Picklers\Admin\Http\AdminResponder;
use Picklers\Admin\Security\AdminUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;

/**
 * While an administrator is viewing the app as someone else, the console itself
 * is closed: the only admin endpoint that answers is "end impersonation". This
 * prevents nested impersonation and any admin mutation performed under a
 * borrowed identity.
 */
final class ImpersonationGuardSubscriber implements EventSubscriberInterface
{
    private const ALLOWED_ROUTES = ['admin_impersonation_stop'];

    public function __construct(
        private readonly Security $security,
        private readonly AdminResponder $responder,
        private readonly Environment $twig,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['guard', 6]];
    }

    public function guard(RequestEvent $event): void
    {
        $user = $this->security->getUser();
        if (!$event->isMainRequest() || !$user instanceof AdminUser || $user->impersonating() === null) {
            return;
        }
        $request = $event->getRequest();
        if (in_array($request->attributes->get('_route'), self::ALLOWED_ROUTES, true)) {
            return;
        }
        $target = (string)($user->impersonating()['target_name'] ?? 'another user');
        $message = "You are viewing the app as {$target}. Return to your administrator account before using the admin console.";
        if ($this->responder->wantsJson($request)) {
            $event->setResponse($this->responder->error($message, Response::HTTP_FORBIDDEN, ['impersonating' => true]));

            return;
        }
        $event->setResponse(new Response($this->twig->render('admin/impersonating.html.twig', [
            'target_name' => $target,
            'expires_at' => (int)($user->impersonating()['expires_at'] ?? 0),
            'app_url' => $this->responder->legacyUrl($request, 'app'),
        ]), Response::HTTP_FORBIDDEN));
    }
}
