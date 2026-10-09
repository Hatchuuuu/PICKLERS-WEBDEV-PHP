<?php
declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->extension('twig', [
        'default_path' => '%kernel.project_dir%/templates',
        'strict_variables' => $container->env() !== 'prod',
        // HTML autoescaping is Twig's default and is deliberately left on.
    ]);
};
