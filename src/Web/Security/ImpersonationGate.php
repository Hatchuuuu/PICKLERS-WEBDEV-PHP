<?php
declare(strict_types=1);

namespace Picklers\Web\Security;

use Picklers\Admin\Security\ImpersonationManager;
use Picklers\Admin\Security\PlatformSession;
use Picklers\Admin\Service\AuditContext;
use Picklers\Admin\Service\AuditLog;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Impersonation on the player/owner side: while an administrator views the app
 * as someone else, sensitive account and money actions are refused, and every
 * change is audited against the original administrator with its HTTP outcome.
 */
final class ImpersonationGate implements EventSubscriberInterface, ResetInterface
{
    /** Actions an administrator may not perform while viewing as someone else. */
    public const RESTRICTED_ACTIONS = [
        'change_password', 'delete_own_account', 'update_profile', 'verify_identity', 'top_up',
        'request_payout', 'save_payout_settings', 'update_payout_settings',
    ];

    private bool $resolved = false;
    private ?array $state = null;
    /** @var list<array{0:string,1:string,2:array}> */
    private array $pending = [];

    public function __construct(
        private readonly ImpersonationManager $manager,
        private readonly PlatformSession $session,
        private readonly AuditLog $audit,
        private readonly AuditContext $context,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::TERMINATE => 'recordActions'];
    }

    /** The current impersonation after expiry/revocation checks, or null (resolved once per request). */
    public function state(): ?array
    {
        if (!$this->resolved) {
            $this->resolved = true;
            try {
                $this->state = $this->manager->resolve();
            } catch (\Throwable $e) {
                // Fail closed: an impersonation that cannot be validated ends.
                $this->logger->error('[PICKLERS Impersonation] could not validate impersonation, ending it: {message}', ['message' => $e->getMessage()]);
                $this->session->destroy();
                $this->state = null;
            }
        }

        return $this->state;
    }

    public function isRestricted(string $action): bool
    {
        return $this->state() !== null && in_array($action, self::RESTRICTED_ACTIONS, true);
    }

    /** Audit this mutating request (if impersonating); written once the response status is known. */
    public function audit(string $area, string $action): void
    {
        if ($action !== '' && ($state = $this->state()) !== null) {
            $this->pending[] = [$area, $action, $state];
        }
    }

    public function recordActions(TerminateEvent $event): void
    {
        $status = $event->getResponse()->getStatusCode();
        foreach ($this->pending as [$area, $action, $state]) {
            try {
                $this->context->set($state['actor_id'], $state['actor_name'], $state['target_id'], $event->getRequest()->getClientIp());
                $this->audit->record('impersonation.action', 'user', $state['target_id'],
                    ['area' => $area, 'action' => $action, 'http_status' => $status], null,
                    $status < 400 ? AuditLog::SUCCESS : AuditLog::FAILURE);
            } catch (\Throwable $e) {
                $this->logger->error('[PICKLERS Impersonation] action audit failed: {message}', ['message' => $e->getMessage()]);
            }
        }
        $this->pending = [];
    }

    public function reset(): void
    {
        $this->resolved = false;
        $this->state = null;
        $this->pending = [];
    }
}
