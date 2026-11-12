<?php
declare(strict_types=1);

require dirname(__DIR__) . '/Admin/bootstrap.php';
$kernel = new \Picklers\Kernel('test', false);
$kernel->boot();
$db = $kernel->getContainer()->get('test.service_container')->get(\Doctrine\DBAL\Connection::class);
$job = json_decode(base64_decode($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$_SESSION['_sf2_attributes'] = ['picklers_user_id' => $job['user'] ?? 'usr_fx_owner1', 'picklers_last_activity' => time(), 'picklers_auth_at' => time(), '_csrf/picklers' => 'court-race'];
echo json_encode(['connection' => $db->fetchOne('SELECT CONNECTION_ID()'), 'session' => session_id()]) . "\n";
flush();
if (trim((string) fgets(STDIN)) !== 'go') {
    throw new \RuntimeException('Missing test release');
}
$request = \Symfony\Component\HttpFoundation\Request::create('/owner.php', 'POST', ['action' => $job['action']] + $job['params'], [], [], ['HTTP_X_CSRF_TOKEN' => 'court-race', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
$response = $kernel->handle($request);
echo json_encode(['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true)]) . "\n";
$kernel->terminate($request, $response);
