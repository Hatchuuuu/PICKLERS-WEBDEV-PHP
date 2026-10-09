<?php
declare(strict_types=1);

namespace Picklers\Admin\Security;

use Picklers\Admin\Repository\AccountRepository;
use Picklers\Admin\Service\AuditContext;
use Picklers\Admin\Service\AuditLog;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Time-limited "view as user" support sessions.
 *
 * The session's user id becomes the target (so player/owner pages render
 * as that person) while the original administrator is kept alongside it. Every
 * request — legacy or Symfony — goes through resolve(), which ends the session
 * when it expires or when the original administrator is no longer an active
 * privileged admin. Start, end and every mutating request made while
 * impersonating are audited against the original actor.
 */
final class ImpersonationManager
{
    public const DEFAULT_TTL = 1800;

    public function __construct(
        private readonly PlatformSession $session,
        private readonly AccountRepository $accounts,
        private readonly AuditLog $audit,
        private readonly AuditContext $context,
        private readonly RequestStack $requests,
        private readonly int $ttlSeconds = self::DEFAULT_TTL,
    ) {
    }

    /**
     * Validated current state, or null when not impersonating. Expired or
     * no-longer-authorised sessions are closed here.
     *
     * @return array{actor_id:string,actor_name:string,target_id:string,target_name:string,started_at:int,expires_at:int,reason:string}|null
     */
    public function resolve(?int $now = null): ?array
    {
        $state = $this->session->impersonation();
        if ($state === null) {
            return null;
        }
        $now ??= time();
        $actorId = (string)($state['actor_id'] ?? '');
        $actor = $actorId !== '' ? $this->accounts->find($actorId) : null;
        if ($this->context->actorId() === null) {
            $this->context->set($actorId ?: null, $actor['name'] ?? null, (string)($state['target_id'] ?? '') ?: null, $this->requests->getCurrentRequest()?->getClientIp());
        }

        if ($actor === null || !RoleMapper::isAdmin($actor) || !$actor['is_privileged']) {
            // The administrator lost the right to impersonate mid-session: end it
            // and sign the browser out rather than leave it acting as the target.
            $this->audit->recordAttempt('impersonation.revoked', 'user', (string)($state['target_id'] ?? ''), AuditLog::DENIED,
                'Impersonation ended: the original administrator is no longer an active privileged admin.');
            $this->session->destroy();

            return null;
        }

        if ((int)($state['expires_at'] ?? 0) <= $now) {
            $this->audit->record('impersonation.expired', 'user', (string)$state['target_id'], [
                'started_at' => date('c', (int)$state['started_at']),
            ]);
            $this->restoreActor($actorId);

            return null;
        }

        return [
            'actor_id' => $actorId,
            'actor_name' => (string)($actor['name'] ?? ''),
            'target_id' => (string)$state['target_id'],
            'target_name' => (string)($state['target_name'] ?? ''),
            'started_at' => (int)$state['started_at'],
            'expires_at' => (int)$state['expires_at'],
            'reason' => (string)($state['reason'] ?? ''),
        ];
    }

    /** Caller has already authorised the actor and validated the target and reason. */
    public function start(AdminUser $actor, array $target, string $reason, ?int $now = null): array
    {
        $now ??= time();
        $state = [
            'actor_id' => $actor->id(),
            'target_id' => (string)$target['id'],
            'target_name' => (string)($target['name'] ?? ''),
            'started_at' => $now,
            'expires_at' => $now + $this->ttlSeconds,
            'reason' => $reason,
        ];
        $this->audit->record('impersonation.start', 'user', (string)$target['id'], [
            'target_name' => $state['target_name'],
            'expires_at' => date('c', $state['expires_at']),
        ], $reason);

        $this->session->regenerateId();
        $this->session->setImpersonation($state);
        $this->session->setUserId((string)$target['id']);
        $this->session->touch($now);

        return $state;
    }

    /** End impersonation and become the original administrator again. */
    public function stop(): ?string
    {
        $state = $this->session->impersonation();
        if ($state === null) {
            return null;
        }
        $this->audit->record('impersonation.end', 'user', (string)($state['target_id'] ?? ''), [
            'duration_seconds' => max(0, time() - (int)($state['started_at'] ?? time())),
        ]);
        $this->restoreActor((string)$state['actor_id']);

        return (string)$state['actor_id'];
    }

    private function restoreActor(string $actorId): void
    {
        $this->session->clearImpersonation();
        $this->session->regenerateId();
        $this->session->setUserId($actorId);
        $this->session->touch();
    }
}
