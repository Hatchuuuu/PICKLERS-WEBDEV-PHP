<?php
declare(strict_types=1);
require dirname(__DIR__) . '/Admin/bootstrap.php';
$kernel = new \Picklers\Kernel('test', false);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
$db = $container->get(\Doctrine\DBAL\Connection::class);
echo $db->fetchOne('SELECT CONNECTION_ID()') . "\n";
flush();
if (trim((string)fgets(STDIN)) !== 'go') { exit(1); }
$response = $container->get(\Picklers\Web\Api\PlayerActions::class)->sync(
    new \Picklers\Web\Http\LegacyInput(\Symfony\Component\HttpFoundation\Request::create('/api.php?action=sync')),
    ['id' => 'usr_fx_p1']
);
echo $response->getContent();
