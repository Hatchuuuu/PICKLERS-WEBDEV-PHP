<?php
declare(strict_types=1);

namespace Picklers\Admin\Service;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Who is acting, on whose behalf, and under which request correlation id.
 * Populated once per request (RequestContextSubscriber) or explicitly by legacy
 * callers; reset between requests in long-running test kernels.
 */
final class AuditContext implements ResetInterface
{
    private ?string $actorId = null;
    private ?string $actorName = null;
    private ?string $effectiveUserId = null;
    private ?string $correlationId = null;
    private ?string $ip = null;

    public function set(?string $actorId, ?string $actorName, ?string $effectiveUserId = null, ?string $ip = null): void
    {
        $this->actorId = $actorId;
        $this->actorName = $actorName;
        $this->effectiveUserId = $effectiveUserId ?? $actorId;
        $this->ip = $ip;
    }

    public function useCorrelationId(?string $incoming): string
    {
        $this->correlationId = ($incoming !== null && preg_match('/^[A-Za-z0-9\-]{8,64}$/', $incoming) === 1)
            ? $incoming
            : bin2hex(random_bytes(16));

        return $this->correlationId;
    }

    public function correlationId(): string
    {
        return $this->correlationId ??= bin2hex(random_bytes(16));
    }

    public function actorId(): ?string
    {
        return $this->actorId;
    }

    public function actorName(): ?string
    {
        return $this->actorName;
    }

    public function effectiveUserId(): ?string
    {
        return $this->effectiveUserId;
    }

    public function ip(): ?string
    {
        return $this->ip;
    }

    public function reset(): void
    {
        $this->actorId = $this->actorName = $this->effectiveUserId = $this->correlationId = $this->ip = null;
    }
}
