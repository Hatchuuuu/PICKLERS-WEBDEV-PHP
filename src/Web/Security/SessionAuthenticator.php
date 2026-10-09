<?php
declare(strict_types=1);

namespace Picklers\Web\Security;

use Picklers\Admin\Security\PlatformSession;
use Picklers\Core\Database;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Turns the platform session into a SessionUser for the player/owner area, on
 * every request (stateless firewall): the two-hour idle timeout, impersonation
 * expiry/revocation, deactivated accounts and sign-out after a password change
 * all end the session. A rejected session continues as a guest.
 */
final class SessionAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private readonly PlatformSession $session,
        private readonly ImpersonationGate $impersonation,
        private readonly Database $db,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return $this->session->userId() !== null;
    }

    public function authenticate(Request $request): SelfValidatingPassport
    {
        if ($this->session->isIdleExpired()) {
            return $this->end();
        }
        // Expires the impersonation on time, and ends it (and the session) if the
        // original administrator may no longer impersonate.
        $impersonating = $this->impersonation->state() !== null;
        $id = $this->session->userId();
        if ($id === null) {
            return $this->end(false);
        }
        $this->session->touch();

        $row = $this->db->getUserById($id);
        // A deactivated or removed account must not keep acting through an old session.
        if (!$row || ($row['role'] ?? '') === 'deleted') {
            return $this->end();
        }
        // A password change signs out every session that authenticated before it.
        if (!empty($row['password_changed_at']) && !$impersonating
            && $this->session->authenticatedAt() < (int)strtotime((string)$row['password_changed_at'])) {
            return $this->end();
        }

        return new SelfValidatingPassport(new UserBadge((string)$row['id'], static fn (): SessionUser => new SessionUser($row)));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return null; // continue as a guest
    }

    private function end(bool $destroy = true): never
    {
        if ($destroy) {
            $this->session->destroy();
        }
        throw new CustomUserMessageAuthenticationException('Session ended.');
    }
}
