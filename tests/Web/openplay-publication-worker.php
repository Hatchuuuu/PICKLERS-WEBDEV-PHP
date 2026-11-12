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
if ($argv[1] === 'join') {
    $result = $container->get(\Picklers\Repositories\OpenPlayRepository::class)->joinMatch('op_publication_old', $argv[2] ?? 'usr_fx_p2', $argv[3] ?? 'GCash');
} else {
    $input = new \Picklers\Web\Http\LegacyInput(\Symfony\Component\HttpFoundation\Request::create('/owner.php', 'POST', ['court_name'=>'Court 2','date'=>date('Y-m-d',strtotime('+3 days')),'start_time'=>'6:00 PM','end_time'=>'9:00 PM','capacity'=>8,'fee'=>150]));
    $response = $container->get(\Picklers\Web\Api\OwnerPortalActions::class)->hostOpenPlay($input, $container->get(\Picklers\Repositories\UserRepository::class)->getUserById('usr_fx_owner1'));
    $result = ['status'=>$response->getStatusCode()];
}
echo json_encode($result);
