<?php
declare(strict_types=1);

namespace Picklers\Admin\EventSubscriber;

use Picklers\Admin\Security\AdminUser;
use Picklers\Admin\Service\AuditContext;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Correlation id + acting administrator for audit records, and baseline security
 * headers on every admin response (mirrors the legacy front controller's set,
 * plus no-store caching for authenticated admin data).
 */
final class RequestContextSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly AuditContext $context,
        private readonly Security $security,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => [
                ['assignCorrelationId', 256],
                ['assignActor', 7], // after the firewall (priority 8)
            ],
            KernelEvents::RESPONSE => ['addHeaders', -10],
        ];
    }

    public function assignCorrelationId(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $id = $this->context->useCorrelationId($event->getRequest()->headers->get('X-Request-Id'));
            $event->getRequest()->attributes->set('_correlation_id', $id);
        }
    }

    public function assignActor(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $user = $this->security->getUser();
        if ($user instanceof AdminUser) {
            $this->context->set(
                $user->id(),
                $user->name(),
                $user->impersonating()['target_id'] ?? $user->id(),
                $event->getRequest()->getClientIp()
            );
        }
    }

    public function addHeaders(ResponseEvent $event): void
    {
        $headers = $event->getResponse()->headers;
        $headers->set('X-Request-Id', $this->context->correlationId());
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'SAMEORIGIN');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        if (!$headers->has('Cache-Control') || !str_contains((string)$headers->get('Cache-Control'), 'no-store')) {
            $headers->set('Cache-Control', 'no-store, private');
        }
    }
}
