<?php
declare(strict_types=1);

namespace Picklers\Admin\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Append-only administrator audit trail (table `audit_events`).
 *
 * Successful sensitive writes call record() INSIDE the same transaction as the
 * mutation, so the change and its audit row commit or roll back together. Denied and failed attempts are recorded
 * on their own afterwards. Nothing in the application updates or deletes audit
 * rows, and database triggers reject UPDATE/DELETE outright.
 */
final class AuditLog
{
    public const SUCCESS = 'success';
    public const FAILURE = 'failure';
    public const DENIED  = 'denied';

    private const SENSITIVE_KEY = '/pass(word)?|hash|secret|token|csrf|api[_-]?key|session/i';
    private const MAX_STRING = 500;

    public function __construct(
        private readonly Connection $connection,
        private readonly AuditContext $context,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @param array<string,mixed> $changes redacted summary of what changed (before/after, counts…)
     */
    public function record(
        string $action,
        ?string $targetType = null,
        ?string $targetId = null,
        array $changes = [],
        ?string $reason = null,
        string $outcome = self::SUCCESS,
    ): int {
        $this->connection->insert('audit_events', [
            'occurred_at' => (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s.u'),
            'actor_user_id' => $this->context->actorId(),
            'actor_name' => $this->context->actorName() !== null ? mb_substr($this->context->actorName(), 0, 120) : null,
            'effective_user_id' => $this->context->effectiveUserId(),
            'action' => mb_substr($action, 0, 80),
            'target_type' => $targetType,
            'target_id' => $targetId !== null ? mb_substr($targetId, 0, 80) : null,
            'reason' => $reason !== null ? mb_substr($reason, 0, 2000) : null,
            'changes' => $changes === [] ? null : json_encode(self::redact($changes), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            'outcome' => $outcome,
            'correlation_id' => $this->context->correlationId(),
            'ip_address' => $this->context->ip(),
        ]);

        return (int)$this->connection->lastInsertId();
    }

    /**
     * Record a denied/failed attempt without ever letting an audit failure mask the
     * original error the caller is about to report.
     */
    public function recordAttempt(string $action, ?string $targetType, ?string $targetId, string $outcome, string $message, array $changes = []): void
    {
        $entry = [$action, $targetType, $targetId, $changes + ['message' => $message], $outcome];
        // Inside a transaction that is about to roll back, writing now would be
        // undone with it; queue the record and write it once the transaction ends.
        // A legacy caller (impersonation hook) may hold a raw PDO transaction on the handle DBAL wraps.
        $native = $this->connection->getNativeConnection();
        if ($this->connection->isTransactionActive() || ($native instanceof \PDO && $native->inTransaction())) {
            $this->deferred[] = $entry;

            return;
        }
        $this->writeAttempt($entry);
    }

    /** Write queued denied/failed attempts; called after every admin action. */
    public function flushDeferred(): void
    {
        $queue = $this->deferred;
        $this->deferred = [];
        foreach ($queue as $entry) {
            $this->writeAttempt($entry);
        }
    }

    /** @var list<array{0:string,1:?string,2:?string,3:array,4:string}> */
    private array $deferred = [];

    private function writeAttempt(array $entry): void
    {
        try {
            [$action, $targetType, $targetId, $changes, $outcome] = $entry;
            $this->record($action, $targetType, $targetId, $changes, null, $outcome);
        } catch (\Throwable $e) {
            $this->logger->error('[PICKLERS Audit] could not record {outcome} attempt for {action}: {message}', ['outcome' => $entry[4], 'action' => $entry[0], 'message' => $e->getMessage()]);
        }
    }

    /** @param array<string,mixed> $data */
    public static function redact(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::SENSITIVE_KEY, $key) === 1) {
                $out[$key] = '[redacted]';
                continue;
            }
            $out[$key] = match (true) {
                is_array($value) => self::redact($value),
                is_string($value) && mb_strlen($value) > self::MAX_STRING => mb_substr($value, 0, self::MAX_STRING) . '…',
                default => $value,
            };
        }

        return $out;
    }
}
