<?php
declare(strict_types=1);

namespace Picklers\Admin\Security;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Picklers\Repositories\UserRepository;

/**
 * Typed access to the platform's one session, which Symfony owns
 * (config/symfony/packages/framework.php: native PHP storage, cookie
 * `picklers_session`): identity, idle time, impersonation and CSRF tokens for
 * the whole application. Sign-in is Web\Controller\AuthController (signIn()).
 */
final class PlatformSession
{
    public const USER_KEY          = 'picklers_user_id';
    public const ACTIVITY_KEY      = 'picklers_last_activity';
    public const AUTH_AT_KEY       = 'picklers_auth_at';
    private const PASSWORD_KEY    = 'picklers_password_fingerprint';
    public const IMPERSONATION_KEY = 'picklers_impersonation';

    /** Two hours of inactivity ends a session. */
    public const IDLE_TIMEOUT_SECONDS = 7200;

    public function __construct(private readonly RequestStack $requests, private readonly UserRepository $users)
    {
    }

    private function session(): SessionInterface
    {
        return $this->requests->getSession();
    }

    public function userId(): ?string
    {
        $id = $this->session()->get(self::USER_KEY);

        return is_string($id) && $id !== '' ? $id : null;
    }

    public function setUserId(string $userId): void
    {
        $this->session()->set(self::USER_KEY, $userId);
    }

    public function isIdleExpired(?int $now = null): bool
    {
        $last = (int)$this->session()->get(self::ACTIVITY_KEY, 0);

        return $last > 0 && (($now ?? time()) - $last) > self::IDLE_TIMEOUT_SECONDS;
    }

    /** When this session last proved the password (set at sign-in). */
    public function authenticatedAt(): int
    {
        return (int)$this->session()->get(self::AUTH_AT_KEY, 0);
    }

    /** Hash binding distinguishes password resets within the same timestamp second. */
    public function passwordChanged(string $userId, ?string $changedAt): bool
    {
        $binding = $this->session()->get(self::PASSWORD_KEY);
        if (is_array($binding) && ($binding['user_id'] ?? null) === $userId) {
            $row = $this->users->getUserById($userId);
            return !$row || !hash_equals((string)$binding['fingerprint'], hash('sha256', (string)($row['password_hash'] ?? '')));
        }
        // Sessions created before hash binding existed fail closed at equal timestamps.
        return $changedAt !== null && $changedAt !== '' && $this->authenticatedAt() <= (int)strtotime($changedAt);
    }

    private function bindPassword(string $userId, ?string $passwordHash): void
    {
        if ($passwordHash === null) {
            $row = $this->users->getUserById($userId);
            if (!$row) {
                throw new \RuntimeException('Cannot authenticate a missing account.');
            }
            $passwordHash = (string)($row['password_hash'] ?? '');
        }
        $this->session()->set(self::PASSWORD_KEY, ['user_id' => $userId, 'fingerprint' => hash('sha256', $passwordHash)]);
    }

    public function touch(?int $now = null): void
    {
        $this->session()->set(self::ACTIVITY_KEY, $now ?? time());
    }

    /** @return array<string,mixed>|null */
    public function impersonation(): ?array
    {
        $state = $this->session()->get(self::IMPERSONATION_KEY);

        return is_array($state) ? $state : null;
    }

    /** @param array<string,mixed> $state */
    public function setImpersonation(array $state): void
    {
        $this->session()->set(self::IMPERSONATION_KEY, $state);
    }

    public function clearImpersonation(): void
    {
        $this->session()->remove(self::IMPERSONATION_KEY);
    }

    /**
     * Start an authenticated session for this account after its password was
     * verified: new session id (fixation), fresh activity/auth stamps, and any
     * impersonation this browser was in ends.
     */
    public function signIn(string $userId, ?int $now = null, ?string $passwordHash = null): void
    {
        $session = $this->session();
        // Keep the old file: a fast client redirect may still arrive with the old cookie.
        $session->migrate(false);
        $session->remove(self::IMPERSONATION_KEY);
        $session->set(self::USER_KEY, $userId);
        $session->set(self::ACTIVITY_KEY, $now ?? time());
        $session->set(self::AUTH_AT_KEY, $now ?? time());
        $this->bindPassword($userId, $passwordHash);
    }

    /** This session just proved the password again (a password change re-stamps itself). */
    public function markAuthenticatedNow(?int $now = null, ?string $passwordHash = null): void
    {
        $this->session()->set(self::AUTH_AT_KEY, $now ?? time());
        if (($userId = $this->userId()) !== null) {
            $this->bindPassword($userId, $passwordHash);
        }
    }

    /** New session id, same data: used whenever the acting identity changes. */
    public function regenerateId(): void
    {
        $this->session()->migrate(true);
    }

    /** Sign out completely: identity, CSRF tokens and impersonation state. */
    public function destroy(): void
    {
        $this->session()->invalidate();
    }
}
