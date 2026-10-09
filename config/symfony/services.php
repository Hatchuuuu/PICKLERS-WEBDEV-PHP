<?php
declare(strict_types=1);

use Picklers\Core\Database;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
            ->autowire()
            ->autoconfigure();

    // Controllers, services, security, Twig extensions, console commands.
    $services->load('Picklers\\Admin\\', '../../src/Admin/')
        ->exclude([
            '../../src/Admin/Http/AdminActionException.php',
            '../../src/Admin/Http/ActionInput.php',
            '../../src/Admin/Http/AdminArea.php',
            '../../src/Admin/Security/AdminUser.php',
            '../../src/Admin/Service/ListQuery.php',
            '../../src/Admin/Service/Money.php',
            '../../src/Admin/Service/Page.php',
        ]);

    // Migrated player/public sections.
    $services->load('Picklers\\Web\\', '../../src/Web/')
        ->exclude(['../../src/Web/Security/SessionUser.php']);

    // Shared platform rules (booking, wallet, notifications, provisioning), also
    // called by the legacy layer so each rule exists once.
    $services->load('Picklers\\Domain\\', '../../src/Domain/');

    // Legacy services reused as-is by migrated sections (their repositories default themselves).
    $services->load('Picklers\\Services\\', '../../src/Services/');
    $services->load('Picklers\\Repositories\\', '../../src/Repositories/');
    $services->load('Picklers\\Persistence\\', '../../src/Persistence/');

    // The legacy persistence engine (a process-wide singleton), still reused by the
    // migrated player sections. The admin console does not use it.
    $services->set(Database::class)
        ->factory([Database::class, 'get']);

    if ($container->env() === 'test') {
        $services->set(\Picklers\Tests\Admin\OpenSessionStorageFactory::class);
    }

    $container->parameters()
        ->set('picklers.data_dir', defined('DATA_PATH') ? DATA_PATH : '%kernel.project_dir%/database/.data')
        ->set('picklers.document_dir', '%env(default:picklers.default_document_dir:PRIVATE_DOCUMENT_DIR)%')
        ->set('picklers.default_document_dir', '%kernel.project_dir%/storage/permits')
        ->set('picklers.legacy_document_dir', '%kernel.project_dir%/public/uploads/permits')
        ->set('picklers.impersonation_ttl', 1800);
};
