<?php
declare(strict_types=1);

use Picklers\Admin\Asset\FileMtimeVersionStrategy;
use Picklers\Admin\Http\AdminActionException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $legacySession = (require dirname(__DIR__, 2) . '/app.php')['session'];

    $container->extension('framework', [
        'secret' => '%env(APP_SECRET)%',
        'http_method_override' => false,
        'handle_all_throwables' => true,
        // Symfony owns the platform session. Native PHP storage and handler, with the
        // cookie the player/owner pages use (config/app.php), so both sides share one
        // session; legacy code reads Symfony's attribute bag (AuthMiddleware::session()).
        'session' => [
            'handler_id' => null,
            'name' => $legacySession['name'],
            'cookie_lifetime' => $legacySession['lifetime'],
            'cookie_path' => '/',
            'cookie_secure' => 'auto',
            'cookie_httponly' => true,
            'cookie_samesite' => 'lax',
        ],
        'csrf_protection' => true,
        'php_errors' => ['log' => true],
        // Refused admin operations are expected outcomes (validation, conflicts,
        // permissions), already audited — not server errors.
        'exceptions' => [
            AdminActionException::class => ['log_level' => 'info'],
            \Picklers\Web\Http\ApiError::class => ['log_level' => 'info'],
            NotFoundHttpException::class => ['log_level' => 'info'],
        ],
        'router' => ['utf8' => true],
        'validation' => ['enabled' => true],
        'assets' => [
            'enabled' => true,
            // Stable cache busting from file modification time (was time() per request).
            'version_strategy' => FileMtimeVersionStrategy::class,
        ],
    ]);

    if ($container->env() === 'test') {
        $container->extension('framework', [
            'test' => true,
            'session' => ['storage_factory_id' => \Picklers\Tests\Admin\OpenSessionStorageFactory::class],
        ]);
    }
};
