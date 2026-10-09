<?php
declare(strict_types=1);

namespace Picklers\Admin\Security;

use Picklers\Admin\Http\AdminResponder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Authenticates every admin request from the platform session (Symfony-owned).
 *
 * Passwords are checked once, at sign-in (password_verify against the existing
 * hashes, unchanged). This authenticator then trusts only the session's user id,
 * enforces the same two-hour idle timeout as the player/owner pages, applies
 * impersonation expiry/revocation, and loads the account fresh from the database
 * so roles, deactivation and privilege changes apply on the next request.
 */
final class AdminSessionAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public function __construct(
        private readonly PlatformSession $session,
        private readonly ImpersonationManager $impersonation,
        private readonly AdminUserProvider $users,
        private readonly AdminResponder $responder,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return true;
    }

    public function authenticate(Request $request): Passport
    {
        if ($this->session->userId() === null) {
            throw new CustomUserMessageAuthenticationException('Sign in with an administrator account to continue.');
        }
        if ($this->session->isIdleExpired()) {
            $this->session->destroy();
            throw new CustomUserMessageAuthenticationException('Your session expired after two hours of inactivity. Sign in again.');
        }

        $state = $this->impersonation->resolve();
        // While impersonating, the console authenticates the ORIGINAL administrator
        // (the session user is the target); ImpersonationGuardSubscriber then limits
        // that administrator to ending the impersonation.
        $identity = $state['actor_id'] ?? $this->session->userId();
        if ($identity === null) {
            throw new CustomUserMessageAuthenticationException('Sign in with an administrator account to continue.');
        }
        $this->session->touch();

        return new SelfValidatingPassport(new UserBadge($identity, function (string $id) use ($state): AdminUser {
            try {
                $user = $this->users->load($id, $state);
            } catch (UserNotFoundException $e) {
                // A deactivated or deleted account must not keep a live session.
                $this->session->destroy();
                throw $e;
            }
            // A password reset signs out every session that authenticated before it.
            $changedAt = $user->row()['password_changed_at'] ?? null;
            if ($state === null && $changedAt && $this->session->authenticatedAt() < (int)strtotime((string)$changedAt)) {
                $this->session->destroy();
                throw new CustomUserMessageAuthenticationException('Your password was changed. Sign in again.');
            }

            return $user;
        }));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $message = $exception instanceof CustomUserMessageAuthenticationException
            ? $exception->getMessageKey()
            : 'Your account is no longer active. Sign in with an administrator account.';

        return $this->responder->unauthenticated($request, $message);
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return $this->responder->unauthenticated($request);
    }
}
