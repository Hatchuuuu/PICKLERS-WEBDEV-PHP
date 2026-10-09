<?php
declare(strict_types=1);

namespace Picklers\Admin\EventSubscriber;

use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\DBAL\Exception\InvalidFieldNameException;
use Picklers\Admin\Http\AdminActionException;
use Picklers\Admin\Http\AdminAreaMatcher;
use Picklers\Admin\Http\AdminResponder;
use Picklers\Admin\Service\AuditContext;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Twig\Environment;

/**
 * Error boundary for the admin console.
 *
 * - Operator-safe AdminActionException messages pass through with their status.
 * - Database outages and an un-migrated schema become 503s that say so.
 * - Anything else is logged with the correlation id and reported WITHOUT
 *   internals, and without claiming nothing changed: an unexpected failure part
 *   way through a non-transactional step cannot promise that.
 * Security exceptions are left to the firewall.
 */
final class ExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly AdminResponder $responder,
        private readonly AdminAreaMatcher $adminArea,
        private readonly AuditContext $context,
        private readonly Environment $twig,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.debug%')] private readonly bool $debug,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Above the default error listener (-128), below the security exception listener (1).
        return [KernelEvents::EXCEPTION => ['onException', -64]];
    }

    public function onException(ExceptionEvent $event): void
    {
        if (!$this->adminArea->matches($event->getRequest())) {
            return; // player/public endpoints: Web\EventSubscriber\JsonErrorSubscriber
        }
        $e = $event->getThrowable();
        if ($e instanceof AccessDeniedException || $e instanceof AuthenticationException) {
            return;
        }
        // Errors raised while rendering arrive wrapped by Twig; classify the cause.
        while ($e instanceof \Twig\Error\RuntimeError && $e->getPrevious() !== null) {
            $e = $e->getPrevious();
        }
        $request = $event->getRequest();
        $ref = $this->context->correlationId();

        [$status, $message, $extra] = match (true) {
            $e instanceof AdminActionException => [$e->status(), $e->getMessage(), $e->extra() + ['changed' => false]],
            $e instanceof ConnectionException =>[503, 'The platform database is unavailable. No admin changes can be made until it is back. Reference ' . $ref . '.', ['changed' => false]],
            $e instanceof TableNotFoundException, $e instanceof InvalidFieldNameException => [503, 'The database schema is out of date for this version of the admin console. Run `php bin/console doctrine:migrations:migrate`. Reference ' . $ref . '.', ['changed' => false]],
            $e instanceof HttpExceptionInterface => [$e->getStatusCode(), $this->httpMessage($e->getStatusCode()), []],
            default => [500, 'Something went wrong while processing this request. Refresh to check the record\'s current state before trying again. Reference ' . $ref . '.', []],
        };

        if ($status >= 500) {
            $this->logger->error('[PICKLERS Admin] {class}: {message} ({ref})', [
                'class' => $e::class, 'message' => $e->getMessage(), 'ref' => $ref, 'exception' => $e,
            ]);
        }
        if ($this->debug && $status >= 500) {
            $extra['debug'] = $e::class . ': ' . $e->getMessage();
        }

        if ($this->responder->wantsJson($request)) {
            $response = $this->responder->error($message, $status, $extra + ['reference' => $ref]);
        } else {
            $response = new Response($this->twig->render('admin/error.html.twig', [
                'status' => $status,
                'title' => $status === 404 ? 'Page not found' : ($status === 503 ? 'Temporarily unavailable' : 'Something went wrong'),
                'message' => $message,
                'reference' => $ref,
                'app_url' => $this->responder->legacyUrl($request, 'app'),
            ]), $status);
        }
        if ($e instanceof HttpExceptionInterface) {
            $response->headers->add($e->getHeaders());
        }
        $event->setResponse($response);
        $event->allowCustomResponseCode();
    }

    private function httpMessage(int $status): string
    {
        return match ($status) {
            404 => 'That admin page or action does not exist.',
            405 => 'That action must be sent with a different HTTP method.',
            default => 'The request could not be processed.',
        };
    }
}
