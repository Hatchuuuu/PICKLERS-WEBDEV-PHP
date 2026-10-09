<?php
declare(strict_types=1);

namespace Picklers\Admin\Controller;

use Picklers\Admin\Http\AdminActionException;
use Picklers\Admin\Security\AdminUser;
use Picklers\Admin\Service\AuditLog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Contracts\Service\Attribute\Required;

/** Shared helpers for admin controllers: the current admin and audited capability checks. */
abstract class AbstractAdminController extends AbstractController
{
    protected AuditLog $auditLog;

    #[Required]
    public function setAuditLog(AuditLog $auditLog): void
    {
        $this->auditLog = $auditLog;
    }

    protected function admin(): AdminUser
    {
        $user = $this->getUser();
        if (!$user instanceof AdminUser) {
            throw AdminActionException::forbidden('Administrator access is required.');
        }

        return $user;
    }

    /** Deny (and audit the denial) unless the current admin holds the capability. */
    protected function requireCapability(string $capability, string $action, ?string $targetType = null, ?string $targetId = null): void
    {
        if (!$this->isGranted($capability)) {
            $message = 'Your administrator account does not have permission for this action.';
            $this->auditLog->recordAttempt($action, $targetType, $targetId, AuditLog::DENIED, $message, ['capability' => $capability]);
            throw AdminActionException::forbidden($message);
        }
    }
}
