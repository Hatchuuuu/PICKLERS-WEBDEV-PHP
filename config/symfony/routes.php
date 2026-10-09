<?php
declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

// Every controller declares its routes with #[Route] attributes.
return static function (RoutingConfigurator $routes): void {
    $routes->import('../../src/Admin/Controller/', 'attribute');
    $routes->import('../../src/Web/Controller/', 'attribute');
};
