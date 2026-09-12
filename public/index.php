<?php
declare(strict_types=1);

// ==============================================================================
// PICKLERS — Front Controller
// Pure PHP 8.x Web Application
// ==============================================================================

// 1. Filesystem Path Anchors (Absolute Paths)
if (!defined('ROOT_PATH')) define('ROOT_PATH', dirname(__DIR__));
if (!defined('APP_PATH')) define('APP_PATH', ROOT_PATH . '/src');
if (!defined('CONFIG_PATH')) define('CONFIG_PATH', ROOT_PATH . '/config');
if (!defined('VIEWS_PATH')) define('VIEWS_PATH', ROOT_PATH . '/views');
if (!defined('DATA_PATH')) define('DATA_PATH', ROOT_PATH . '/database');
if (!defined('PUBLIC_PATH')) define('PUBLIC_PATH', __DIR__);

// 2. Load .env — Pure PHP env loader (no dependencies required)
(function () {
    $envFile = ROOT_PATH . '/.env';
    if (!file_exists($envFile)) return;
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        // Skip comments and lines without '='
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);
        // Strip optional surrounding quotes
        if (strlen($value) >= 2 && (
            ($value[0] === '"'  && $value[-1] === '"')  ||
            ($value[0] === "'"  && $value[-1] === "'")
        )) {
            $value = substr($value, 1, -1);
        }
        if (!isset($_ENV[$key])) {
            $_ENV[$key] = $value;
            putenv("{$key}={$value}");
        }
    }
})();

// 3. PSR-4 Compliant Autoloader (Composer & Fallback)
if (file_exists(ROOT_PATH . '/vendor/autoload.php')) {
    require_once ROOT_PATH . '/vendor/autoload.php';
} else {
    require_once APP_PATH . '/Core/Autoloader.php';
    \Picklers\Core\Autoloader::register('Picklers\\', APP_PATH . '/');
}

// 4. Application Configuration & Secure Session Handling
$appConfig = file_exists(CONFIG_PATH . '/app.php') ? require CONFIG_PATH . '/app.php' : [];

if (session_status() === PHP_SESSION_NONE) {
    $sessionParams = $appConfig['session'] ?? [];
    if (!empty($sessionParams['name'])) {
        session_name($sessionParams['name']);
    }
    session_set_cookie_params([
        'lifetime' => $sessionParams['lifetime'] ?? 604800,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $sessionParams['secure'] ?? false,
        'httponly' => $sessionParams['httponly'] ?? true,
        'samesite' => $sessionParams['samesite'] ?? 'Lax'
    ]);
    @session_start();
}

// 5. Pre-boot Database Connection
require_once APP_PATH . '/Core/Database.php';

use Picklers\Core\Request;
use Picklers\Core\Router;

// 6. Initialize Router & Load Route Registry
$router = new Router();
if (file_exists(CONFIG_PATH . '/routes.php')) {
    require CONFIG_PATH . '/routes.php';
}

// 7. Apply Essential HTTP Security Headers
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-XSS-Protection: 1; mode=block');
}

// 8. Dispatch Incoming HTTP Request with Graceful Error Boundary
$request = Request::createFromGlobals();
try {
    $router->dispatch($request);
} catch (\Throwable $e) {
    $debug = filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN);
    if ($debug) {
        throw $e;
    }
    http_response_code(500);
    error_log(sprintf("[PICKLERS CRITICAL] %s in %s:%d", $e->getMessage(), $e->getFile(), $e->getLine()));
    if ($request->header('X-Requested-With') === 'XMLHttpRequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Internal Server Error. Please contact support.']);
        exit;
    }
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>System Notice — PICKLERS</title>'
       . '<style>body{background:#0A121F;color:#fff;font-family:sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:20px;text-align:center;}'
       . 'h1{font-size:28px;margin-bottom:12px;color:#55C39E;}p{color:#94A3B8;max-width:500px;line-height:1.6;}'
       . 'a{display:inline-block;margin-top:20px;padding:10px 24px;background:#55C39E;color:#0A121F;font-weight:700;border-radius:99px;text-decoration:none;}</style>'
       . '</head><body><div><h1>Something went wrong</h1><p>We encountered an unexpected condition. The engineering team has been notified.</p><a href="javascript:history.back()">Go Back</a></div></body></html>';
    exit;
}
