<?php
declare(strict_types=1);

require dirname(__DIR__) . '/Admin/bootstrap.php';
$kernel = new \Picklers\Kernel('test', false);
$kernel->boot();
$owners = $kernel->getContainer()->get('test.service_container')->get(\Picklers\Repositories\OwnerRepository::class);
$db = $kernel->getContainer()->get('test.service_container')->get(\Doctrine\DBAL\Connection::class);
echo $db->fetchOne('SELECT CONNECTION_ID()') . "\n";
flush();
if (trim((string)fgets(STDIN)) !== 'grant') {
    exit(1);
}
echo json_encode($owners->insertStaff(['facility_id' => $argv[1], 'user_id' => 'usr_fx_p1', 'name' => 'Player', 'email' => 'player@fixture.test']));
