<?php
declare(strict_types=1);

namespace Picklers\Core;

// ==============================================================================
// PICKLERS — PSR-4 Compliant Autoloader
// ==============================================================================

class Autoloader {
    public static function register(string $prefix = 'Picklers\\', ?string $baseDir = null): void {
        date_default_timezone_set('Asia/Manila');
        if ($baseDir === null) {
            $baseDir = defined('APP_PATH') ? APP_PATH : (defined('SRC_PATH') ? SRC_PATH : dirname(__DIR__));
        }
        $baseDir = rtrim($baseDir, '/\\') . '/';
        
        spl_autoload_register(function (string $class) use ($prefix, $baseDir): void {
            $len = strlen($prefix);
            if (strncmp($prefix, $class, $len) !== 0) {
                return;
            }
            $relativeClass = substr($class, $len);
            $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
            if (file_exists($file)) {
                require_once $file;
            }
        });
    }
}
