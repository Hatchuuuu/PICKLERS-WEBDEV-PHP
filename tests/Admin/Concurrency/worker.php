<?php
declare(strict_types=1);

/**
 * Concurrency worker: boots the test kernel in its own process (own MySQL
 * connection), waits for the shared start instant, runs ONE operation against
 * picklers_admin_test and prints a JSON result line.
 */

use Picklers\Admin\Http\AdminActionException;
use Picklers\Admin\Repository\AccountRepository;
use Picklers\Admin\Security\AdminUser;
use Picklers\Admin\Service\ApplicationReviewService;
use Picklers\Admin\Service\AuditContext;
use Picklers\Admin\Service\BookingAdminService;
use Picklers\Admin\Service\WalletAdjustmentService;
use Picklers\Repositories\BookingRepository;
use Picklers\Kernel;

$job = json_decode((string)base64_decode($argv[1] ?? ''), true);
if (!is_array($job)) {
    exit("bad job\n");
}

$root = dirname(__DIR__, 3);
$_ENV['APP_ENV'] = 'test';
$_ENV['APP_DEBUG'] = '0';
$_ENV['DB_DATABASE'] = 'picklers_admin_test';
$_ENV['PRIVATE_DOCUMENT_DIR'] = $root . '/database/.test-state/documents';
define('DATA_PATH', $root . '/database/.test-state/admin');
require $root . '/config/bootstrap.php';

$kernel = new Kernel('test', false);
$kernel->boot();
$c = $kernel->getContainer()->get('test.service_container');
$admin = static function (string $id) use ($c): AdminUser {
    $row = $c->get(AccountRepository::class)->find($id);

    return new AdminUser($row, (bool)$row['is_privileged']);
};
$c->get(AuditContext::class)->set($job['args']['actor'] ?? 'usr_fx_root', 'Concurrency worker ' . $job['worker'], null, '127.0.0.1');

// Every worker waits for the same instant so their transactions overlap.
while (microtime(true) < (float)$job['go']) {
    usleep(2000);
}

try {
    $a = $job['args'];
    $result = match ($job['op']) {
        'approve' => $c->get(ApplicationReviewService::class)->approve($admin('usr_fx_admin'), $a['application_id'], 'concurrent approval'),
        'cancel' => $c->get(BookingAdminService::class)->cancel($admin('usr_fx_admin'), $a['booking_id'], '', 'Concurrent cancellation test'),
        'confirm' => $c->get(BookingAdminService::class)->changeStatus($admin('usr_fx_admin'), $a['booking_id'], 'confirmed', '', ''),
        'wallet' => $c->get(WalletAdjustmentService::class)->adjust($admin('usr_fx_root'), $a['user_id'], $a['type'], $a['amount'], 'Concurrency test adjustment', $a['key'] ?? ('k' . $job['worker'] . str_repeat('0', 20))),
        'book_promo' => $c->get(BookingRepository::class)->createBooking(
            $a['user_id'], $a['facility_id'], 'Court ' . ($job['worker'] + 1), $a['date'], sprintf('%d:00 AM - %d:00 AM', 6 + $job['worker'], 7 + $job['worker']),
            1, $a['price'], 'GCash', 'crt_' . $a['facility_id'] . '_' . (($job['worker'] % 2) + 1), $a['promo'], (float)$a['discount']
        ),
        'player_cancel' => $c->get(BookingRepository::class)->cancelBooking($a['booking_id'], $a['user_id']),
        'revoke_privilege', 'bootstrap_admin' => (static function () use ($c, $a, $job): array {
            $tester = new \Symfony\Component\Console\Tester\CommandTester($c->get(\Picklers\Admin\Command\AdminPrivilegeCommand::class));
            $code = $tester->execute(['operation' => $job['op'] === 'bootstrap_admin' ? 'bootstrap' : 'revoke', 'user' => $a['users'][$job['worker']], '--reason' => 'Concurrent privilege regression']);
            return ['code' => $code, 'output' => $tester->getDisplay()];
        })(),
        'like' => $c->get(\Picklers\Repositories\CommunityRepository::class)->toggleLikePost($a['post_id'], $a['user_id']),
        'demote_admin' => (static function () use ($c, $a, $job, $admin): array {
            $actor = $admin($a['actors'][$job['worker']]);
            $c->get('security.token_storage')->setToken(new \Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken($actor, 'admin', $actor->getRoles()));
            $c->get(AuditContext::class)->set($actor->id(), 'Concurrent role regression', null, '127.0.0.1');
            return $c->get(\Picklers\Admin\Service\UserAdminService::class)->changeRole($actor, $a['users'][$job['worker']], 'player');
        })(),
        'delete_vs_book' => (static function () use ($c, $a, $job): array {
            if ($job['worker'] === 0) {
                $request = \Symfony\Component\HttpFoundation\Request::create('/');
                $request->setSession(new \Symfony\Component\HttpFoundation\Session\Session(new \Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage()));
                $c->get(\Symfony\Component\HttpFoundation\RequestStack::class)->push($request);
                $c->get(\Picklers\Web\Service\AccountService::class)->deleteOwnAccount($c->get(AccountRepository::class)->find($a['user_id']), 'DELETE');
                return ['deleted' => true];
            }
            return $c->get(BookingRepository::class)->createBooking($a['user_id'], $a['facility_id'], 'Court 1', $a['date'], '9:00 AM - 10:00 AM', 1, 330, 'GCash', $a['court_id']);
        })(),
        default => throw new RuntimeException('unknown op'),
    };
    echo "\n" . json_encode(['ok' => true, 'result' => $result]) . "\n";
} catch (AdminActionException $e) {
    echo "\n" . json_encode(['ok' => false, 'status' => $e->status(), 'message' => $e->getMessage()]) . "\n";
} catch (Throwable $e) {
    echo "\n" . json_encode(['ok' => false, 'status' => 500, 'message' => get_class($e) . ': ' . $e->getMessage()]) . "\n";
}
