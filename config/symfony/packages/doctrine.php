<?php
declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    // Same .env variables and defaults as config/database.php.
    $container->parameters()
        ->set('env(DB_HOST)', '127.0.0.1')
        ->set('env(DB_PORT)', '3306')
        ->set('env(DB_DATABASE)', 'picklers_db')
        ->set('env(DB_USERNAME)', 'root')
        ->set('env(DB_PASSWORD)', '');

    $container->extension('doctrine', [
        'dbal' => [
            'driver' => 'pdo_mysql',
            'host' => '%env(DB_HOST)%',
            'port' => '%env(int:DB_PORT)%',
            'dbname' => '%env(DB_DATABASE)%',
            'user' => '%env(DB_USERNAME)%',
            'password' => '%env(DB_PASSWORD)%',
            'charset' => 'utf8mb4',
            'server_version' => '10.4.32-MariaDB',
            'options' => [
                PDO::ATTR_TIMEOUT => 2,
                // Strict mode, as the legacy connection sets it: an out-of-type value
                // is an error, never a silent coercion.
                (defined('Pdo\\Mysql::ATTR_INIT_COMMAND') ? constant('Pdo\\Mysql::ATTR_INIT_COMMAND') : PDO::MYSQL_ATTR_INIT_COMMAND)
                    => "SET SESSION sql_mode = CONCAT(@@sql_mode, ',STRICT_TRANS_TABLES,NO_ZERO_DATE')",
            ],
        ],
    ]);

    $container->extension('doctrine_migrations', [
        'migrations_paths' => [
            'Picklers\Migrations' => '%kernel.project_dir%/src/Migrations',
        ],
        'storage' => [
            'table_storage' => ['table_name' => 'doctrine_migration_versions'],
        ],
        // MySQL commits DDL implicitly; each migration is written to be safe to
        // re-run instead of relying on a wrapping transaction.
        'transactional' => false,
        'all_or_nothing' => false,
    ]);
};
