<?php
declare(strict_types=1);

namespace Picklers\Middleware;

use Picklers\Core\Request;
use Picklers\Core\Response;

// ==============================================================================
// PICKLERS — CSRF Protection Middleware
// ==============================================================================

class CsrfMiddleware {
    public static function getToken(): string {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        if (empty($_SESSION['picklers_csrf_token'])) {
            $_SESSION['picklers_csrf_token'] = bin2hex(random_bytes(24));
        }
        return $_SESSION['picklers_csrf_token'];
    }

    public static function validate(Request|string $requestOrToken): bool {
        $token = self::getToken();
        if ($requestOrToken instanceof Request) {
            $submitted = $requestOrToken->header('X-CSRF-Token') 
                ?: $requestOrToken->input('csrf_token', '');

            $isMutatingMethod = in_array(strtoupper($requestOrToken->getMethod()), ['POST', 'PUT', 'PATCH', 'DELETE'], true);
            if ($isMutatingMethod) {
                if (empty($submitted)) {
                    return false;
                }
                return hash_equals($token, (string)$submitted);
            }
            return true;
        }

        // Direct token validation
        return !empty($requestOrToken) && hash_equals($token, (string)$requestOrToken);
    }

    public static function requireValid(Request $request): void {
        if (!self::validate($request)) {
            Response::json(['success' => false, 'message' => 'CSRF security token invalid.'], 403);
        }
    }
}
