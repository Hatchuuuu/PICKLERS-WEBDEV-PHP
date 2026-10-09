<?php
declare(strict_types=1);

namespace Picklers\Web\EventSubscriber;

use Picklers\Admin\Http\AdminAreaMatcher;
use Picklers\Web\Http\ApiError;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Error boundary for the player/owner API and XHR calls: the API's JSON error
 * shape, and no internals (SQL, paths) unless APP_DEBUG. Page requests fall
 * through to the HTML error pages (templates/bundles/TwigBundle/Exception/).
 */
final class JsonErrorSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly AdminAreaMatcher $adminArea,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.debug%')] private readonly bool $debug,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onException', -64]];
    }

    public function onException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if ($this->adminArea->matches($request) || !self::wantsJson($request)) {
            return;
        }
        $e = $event->getThrowable();
        if ($e instanceof ApiError) {
            [$status, $message] = [$e->status(), $e->getMessage()];
        } elseif ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $message = match ($status) {
                404 => 'Not found.',
                405 => 'Method not allowed.',
                default => 'The request could not be processed.',
            };
        } else {
            $status = 500;
            $this->logger->error('[PICKLERS API] {class}: {message}', ['class' => $e::class, 'message' => $e->getMessage(), 'exception' => $e]);
            $message = $this->debug ? $e->getMessage() : 'Something went wrong while processing your request. Please try again.';
        }
        $event->setResponse(new JsonResponse(['success' => false, 'message' => $message, 'errors' => []], $status));
    }

    private static function wantsJson(Request $request): bool
    {
        return str_starts_with($request->getPathInfo(), '/api')
            || $request->headers->get('X-Requested-With') === 'XMLHttpRequest'
            || str_contains((string)$request->headers->get('Accept', ''), 'application/json')
            || str_contains((string)$request->headers->get('Content-Type', ''), 'application/json');
    }
}
