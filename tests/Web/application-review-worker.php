<?php
declare(strict_types=1);

require dirname(__DIR__) . '/Admin/bootstrap.php';
$kernel = new \Picklers\Kernel('test', false);
$kernel->boot();
$c = $kernel->getContainer()->get('test.service_container');
$db = $c->get(\Doctrine\DBAL\Connection::class);
$row = $c->get(\Picklers\Admin\Repository\AccountRepository::class)->find('usr_fx_admin');
$actor = new \Picklers\Admin\Security\AdminUser($row, (bool)$row['is_privileged']);
$c->get(\Picklers\Admin\Service\AuditContext::class)->set($actor->id(), 'Application lock regression', null, '127.0.0.1');
echo json_encode(['connection' => $db->fetchOne('SELECT CONNECTION_ID()')]) . "\n";
flush();
if (trim((string)fgets(STDIN)) !== 'go') throw new \RuntimeException('Missing test release');
try {
    $service = $c->get(\Picklers\Admin\Service\ApplicationReviewService::class);
    $result = $argv[1] === 'approve'
        ? $service->approve($actor, 'fx_app_lock_order', 'Documents verified')
        : $service->reject($actor, 'fx_app_lock_order', 'Permit needs correction');
    echo json_encode(['status' => 200, 'result' => $result]);
} catch (\Picklers\Admin\Http\AdminActionException $e) {
    echo json_encode(['status' => $e->status(), 'message' => $e->getMessage()]);
} catch (\Throwable $e) {
    echo json_encode(['status' => 500, 'message' => get_class($e) . ': ' . $e->getMessage()]);
}
