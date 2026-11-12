<?php
declare(strict_types=1);

// Each worker owns a kernel and PHP session. The database trigger gates only the write,
// so the parent can prove both HTTP requests passed their availability lookups.
$job = json_decode((string)base64_decode($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$root = dirname(__DIR__, 3);
$_ENV['APP_ENV'] = 'test';
$_ENV['APP_DEBUG'] = '0';
$_ENV['DB_DATABASE'] = 'picklers_admin_test';
$_ENV['PRIVATE_DOCUMENT_DIR'] = $root . '/database/.test-state/documents';
define('DATA_PATH', $root . '/database/.test-state/admin');
require $root . '/config/bootstrap.php';
ini_set('session.use_cookies', '0');
ini_set('session.save_path', sys_get_temp_dir());
session_id('phone-race-' . bin2hex(random_bytes(12)));
session_start();
$_SESSION['_sf2_attributes']['_csrf/picklers'] = 'phone-race-token';
if ($job['mode'] === 'profile') {
    $_SESSION['_sf2_attributes']['picklers_user_id'] = $job['user'];
    $_SESSION['_sf2_attributes']['picklers_last_activity'] = time();
    $_SESSION['_sf2_attributes']['picklers_auth_at'] = time();
}
$client = new \Symfony\Bundle\FrameworkBundle\KernelBrowser(new \Picklers\Kernel('test', false));
$fields = ['name' => $job['name'], 'email' => $job['email'], 'phone' => $job['phone']];
if ($job['mode'] === 'signup') {
    $fields += ['auth_action' => 'signup', 'password' => 'PhoneRace!123456'];
} else {
    $fields += ['action' => 'update_profile', 'avatar_url' => 'https://example.test/phone-race.png'];
}
$client->request('POST', $job['mode'] === 'signup' ? '/auth' : '/api.php', $fields, [], [
    'HTTP_X_CSRF_TOKEN' => 'phone-race-token', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
    'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => $job['ip'],
]);
echo json_encode(['status' => $client->getResponse()->getStatusCode(), 'json' => json_decode((string)$client->getResponse()->getContent(), true)], JSON_THROW_ON_ERROR);
