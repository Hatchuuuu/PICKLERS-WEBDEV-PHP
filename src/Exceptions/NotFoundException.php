<?php
declare(strict_types=1);

namespace Picklers\Exceptions;

use Exception;

class NotFoundException extends Exception {
    public function __construct(string $message = "Resource not found", int $code = 404, ?Exception $previous = null) {
        parent::__construct($message, $code, $previous);
    }
}
