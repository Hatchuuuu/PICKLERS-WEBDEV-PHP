<?php
declare(strict_types=1);

require dirname(__DIR__) . '/Admin/bootstrap.php';
$kernel = new \Picklers\Kernel('test', false);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
$db = $container->get(\Doctrine\DBAL\Connection::class);
$_SESSION['_sf2_attributes'] = ['picklers_user_id' => 'usr_fx_p1', 'picklers_last_activity' => time(), 'picklers_auth_at' => time(), '_csrf/picklers' => 'application-race'];
$files = [];
foreach (['permit_file', 'gov_id_file'] as $key) {
    $path = tempnam(sys_get_temp_dir(), 'pkl');
    file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
    $files[$key] = new class($path, 'race_' . $argv[1] . '_' . $key . '.png', $key === 'gov_id_file', $db) extends \Symfony\Component\HttpFoundation\File\UploadedFile {
        public function __construct(string $path, string $name, private bool $barrier, private \Doctrine\DBAL\Connection $db) { parent::__construct($path, $name, 'image/png', null, true); }
        public function move(string $directory, ?string $name = null): \Symfony\Component\HttpFoundation\File\File {
            $file = parent::move($directory, $name);
            if ($this->barrier) {
                echo json_encode(['connection' => $this->db->fetchOne('SELECT CONNECTION_ID()'), 'session' => session_id()]) . "\n";
                flush();
                if (trim((string)fgets(STDIN)) !== 'go') { throw new \RuntimeException('Missing test release'); }
            }
            return $file;
        }
    };
}
$request = \Symfony\Component\HttpFoundation\Request::create('/owner-application', 'POST', ['facility_name'=>'Race Arena','address'=>'Dumaguete','owner_name'=>'Paula','business_email'=>'paula@fixture.test','phone'=>'09175550199','entity_name'=>'Race Arena Inc','reg_number'=>'DTI-RACE'], [], $files, ['HTTP_X_CSRF_TOKEN'=>'application-race','HTTP_X_REQUESTED_WITH'=>'XMLHttpRequest']);
$response = $kernel->handle($request);
echo json_encode(['status' => $response->getStatusCode(), 'body' => $response->getContent()]);
$kernel->terminate($request, $response);
