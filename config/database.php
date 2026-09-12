<?php
declare(strict_types=1);

// ==============================================================================
// PICKLERS — Database & Persistence Configuration
// Values are loaded from the root .env file via public/index.php
// ==============================================================================

return [
    'default' => 'mysql',
    
    'connections' => [
        'mysql' => [
            'host'      => $_ENV['DB_HOST']     ?? '127.0.0.1',
            'port'      => (int)($_ENV['DB_PORT'] ?? 3306),
            'database'  => $_ENV['DB_DATABASE']  ?? 'picklers_db',
            'username'  => $_ENV['DB_USERNAME']  ?? 'root',
            'password'  => $_ENV['DB_PASSWORD']  ?? '',
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'options'   => [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 2,
            ]
        ]
    ],
    
    // Failover JSON flat-file storage configuration
    'json' => [
        'enabled' => true,
        'path'    => defined('DATA_PATH') ? DATA_PATH : (dirname(__DIR__) . '/database'),
    ]
];
