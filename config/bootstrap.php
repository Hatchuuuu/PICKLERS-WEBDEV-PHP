<?php
declare(strict_types=1);

// ==============================================================================
// PICKLERS — shared process bootstrap
// Used by public/index.php (web), bin/console (CLI) and the test suites so path
// constants, environment loading, the timezone and autoloading are defined once.
// ==============================================================================

if (!defined('ROOT_PATH')) define('ROOT_PATH', dirname(__DIR__));
if (!defined('APP_PATH')) define('APP_PATH', ROOT_PATH . '/src');
if (!defined('CONFIG_PATH')) define('CONFIG_PATH', ROOT_PATH . '/config');
if (!defined('DATA_PATH')) define('DATA_PATH', ROOT_PATH . '/database');
if (!defined('PUBLIC_PATH')) define('PUBLIC_PATH', ROOT_PATH . '/public');

// Storage convention: every DATETIME the platform writes is Asia/Manila wall-clock
// time (MariaDB's SYSTEM zone on the host is Manila too). This used to be set only
// inside the fallback autoloader, so the moment vendor/autoload.php existed PHP
// silently fell back to php.ini's zone and shifted every new timestamp.
date_default_timezone_set('Asia/Manila');

// .env loader — values already present in $_ENV (CLI overrides, the test runner,
// the e2e router) win, exactly like the original front controller.
(static function (): void {
    $envFile = ROOT_PATH . '/.env';
    if (!is_file($envFile)) {
        return;
    }
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);
        if (strlen($value) >= 2 && (($value[0] === '"' && $value[-1] === '"') || ($value[0] === "'" && $value[-1] === "'"))) {
            $value = substr($value, 1, -1);
        }
        if (!isset($_ENV[$key])) {
            $_ENV[$key] = $value;
            putenv("{$key}={$value}");
        }
    }
})();

// Symfony needs a kernel secret. When the deployment does not provide APP_SECRET,
// a random machine-local one is generated once under var/ (never committed).
if (empty($_ENV['APP_SECRET'])) {
    $secretFile = ROOT_PATH . '/var/app_secret';
    if (!is_file($secretFile)) {
        @mkdir(dirname($secretFile), 0750, true);
        @file_put_contents($secretFile, bin2hex(random_bytes(32)));
    }
    $_ENV['APP_SECRET'] = trim((string)@file_get_contents($secretFile)) ?: bin2hex(random_bytes(32));
}

if (!is_file(ROOT_PATH . '/vendor/autoload.php')) {
    if (PHP_SAPI !== 'cli') {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
    }
    exit("PICKLERS needs its Composer dependencies. Run: composer install\n");
}
require_once ROOT_PATH . '/vendor/autoload.php';

if (!function_exists('picklers_kernel_environment')) {
    /** Maps the platform's APP_ENV vocabulary onto Symfony kernel environments. */
    function picklers_kernel_environment(): string {
        $env = strtolower((string)($_ENV['APP_ENV'] ?? 'production'));
        return match (true) {
            in_array($env, ['dev', 'development', 'local'], true) => 'dev',
            $env === 'test' => 'test',
            default => 'prod',
        };
    }
}

if (!function_exists('picklers_kernel_debug')) {
    function picklers_kernel_debug(): bool {
        return picklers_kernel_environment() !== 'prod'
            && filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }
}
