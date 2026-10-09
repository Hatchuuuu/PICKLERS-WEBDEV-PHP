<?php
declare(strict_types=1);

namespace Picklers\Admin\Http;

use RuntimeException;

/**
 * A refused or failed admin operation whose message is safe to show the operator.
 *
 * Thrown by services after they have rolled back (or before they wrote anything),
 * so `changed: false` in the response is truthful.
 */
final class AdminActionException extends RuntimeException
{
    /** @param array<string,mixed> $extra */
    public function __construct(
        string $message,
        private readonly int $status = 400,
        private readonly array $extra = [],
        private readonly ?string $field = null,
    ) {
        parent::__construct($message);
    }

    public static function notFound(string $what): self
    {
        return new self("{$what} not found.", 404);
    }

    public static function conflict(string $message, array $extra = []): self
    {
        return new self($message, 409, $extra);
    }

    public static function invalid(string $message, ?string $field = null): self
    {
        return new self($message, 422, [], $field);
    }

    public static function forbidden(string $message): self
    {
        return new self($message, 403);
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string,mixed> */
    public function extra(): array
    {
        return $this->extra + ($this->field !== null ? ['field' => $this->field] : []);
    }
}
