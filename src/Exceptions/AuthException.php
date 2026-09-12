<?php
declare(strict_types=1);

namespace Picklers\Exceptions;

use Exception;

class AuthException extends Exception {
    public function __construct(string $message = "Unauthorized access", int $code = 401, ?Exception $previous = null) {
        parent::__construct($message, $code, $previous);
    }
}
