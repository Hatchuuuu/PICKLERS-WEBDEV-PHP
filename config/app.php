<?php
declare(strict_types=1);

// ==============================================================================
// PICKLERS — Application Configuration
// Values are loaded from the root .env file via public/index.php
// ==============================================================================

return [
    'name'  => 'PICKLERS',
    'env'   => $_ENV['APP_ENV']   ?? 'production',
    'debug' => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),
    'url'   => $_ENV['APP_URL']   ?? 'http://localhost/PICKLERS%20WEBDEV%20PROJECT',
    
    // Security & Sessions
    'session' => [
        'name'     => 'picklers_session',
        'lifetime' => 86400 * 7, // 7 days
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? null) == 443) || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
        'httponly' => true,
        'samesite' => 'Lax',
    ],
    
    // Paths
    'paths' => [
        'root'   => defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__),
        'public' => defined('PUBLIC_PATH') ? PUBLIC_PATH : (dirname(__DIR__) . '/public'),
        'data'   => defined('DATA_PATH') ? DATA_PATH : (dirname(__DIR__) . '/database'),
        'views'  => defined('VIEWS_PATH') ? VIEWS_PATH : (dirname(__DIR__) . '/views'),
    ]
];
