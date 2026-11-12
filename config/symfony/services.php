<?php
declare(strict_types=1);

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

    // Shared platform rules (booking, wallet, notifications, provisioning).
    $services->load('Picklers\\Domain\\', '../../src/Domain/');

    // Application services and repositories share Doctrine's injected connection.
    $services->load('Picklers\\Services\\', '../../src/Services/');
    $services->load('Picklers\\Repositories\\', '../../src/Repositories/');
    $services->load('Picklers\\Persistence\\', '../../src/Persistence/');

    if ($container->env() === 'test') {
        $services->set(\Picklers\Tests\Admin\OpenSessionStorageFactory::class);
    }

    $container->parameters()
        ->set('picklers.data_dir', '%env(PICKLERS_DATA_DIR)%')
        ->set('picklers.document_dir', '%env(default:picklers.default_document_dir:PRIVATE_DOCUMENT_DIR)%')
        ->set('picklers.default_document_dir', '%kernel.project_dir%/storage/permits')
        ->set('picklers.legacy_document_dir', '%kernel.project_dir%/public/uploads/permits')
        ->set('picklers.impersonation_ttl', 1800);
};
