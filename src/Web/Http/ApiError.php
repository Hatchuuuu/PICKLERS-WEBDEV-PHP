<?php
declare(strict_types=1);

namespace Picklers\Web\Http;

/**
 * A user-facing refusal from a migrated endpoint. Rendered by JsonErrorSubscriber
 * as the legacy {success:false, message, errors} body with this HTTP status.
 */
final class ApiError extends \RuntimeException
{
    public function __construct(string $message, int $status = 400)
    {
        parent::__construct($message, $status);
    }

    public function status(): int
    {
        return $this->getCode();
    }
}
