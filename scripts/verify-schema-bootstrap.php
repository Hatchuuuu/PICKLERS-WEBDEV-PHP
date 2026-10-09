<?php
declare(strict_types=1);

/**
 * Verifies the legacy schema bootstrap in a disposable database:
 *
 *   1. fresh       — no database, no stamp: every table and the dev seed appear;
 *   2. stale stamp — empty database but a directory stamp claiming the current
 *                    version plus a stale `.seeded` flag (the A01 failure mode):
 *                    tables and seed are still created;
 *   3. trusted     — a second boot with a valid stamp performs no re-init.
 *
 * Only ever touches `picklers_test_boot`.  Usage: php scripts/verify-schema-bootstrap.php
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$root   = dirname(__DIR__);
$dbName = 'picklers_test_boot';
$state  = $root . '/database/.test-state/boot-probe';

$env = [];
foreach (file($root . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
    [$k, $v] = array_map('trim', explode('=', $line, 2));
    $env[$k] = trim($v, "\"'");
}
$server = new PDO(
    sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $env['DB_HOST'] ?? '127.0.0.1', (int)($env['DB_PORT'] ?? 3306)),
    $env['DB_USERNAME'] ?? 'root', $env['DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$resetState = static function () use ($state): void {
    if (is_dir($state)) {
        foreach (glob($state . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) unlink($f);
        }
    } else {
        mkdir($state, 0750, true);
    }
};

/** Boot the legacy Database in a child process and report what it sees. */
$probe = static function () use ($root, $state, $dbName): array {
    $code = '<?php' . "\n" . sprintf(
        'define("ROOT_PATH", %s); define("APP_PATH", ROOT_PATH . "/src"); define("CONFIG_PATH", ROOT_PATH . "/config"); define("DATA_PATH", %s);' . "\n"
        . '$_ENV["APP_ENV"] = "development"; $_ENV["DB_DATABASE"] = %s;' . "\n"
        . 'foreach (file(ROOT_PATH . "/.env", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) { $l = trim($l); if ($l === "" || $l[0] === "#" || !str_contains($l, "=")) continue; [$k, $v] = array_map("trim", explode("=", $l, 2)); if (!isset($_ENV[$k])) $_ENV[$k] = trim($v, "\"\'"); }' . "\n"
        . 'require ROOT_PATH . "/vendor/autoload.php";' . "\n"
        . '$db = Picklers\Core\Database::get(); $pdo = (new ReflectionProperty($db, "pdo"))->getValue($db);' . "\n"
        . '$t = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchColumn();' . "\n"
        . '$f = $pdo->query("SELECT COUNT(*) FROM facilities")->fetchColumn(); $u = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();' . "\n"
        . 'echo json_encode(["mysql" => $db->isUsingMySQL(), "tables" => (int)$t, "facilities" => (int)$f, "users" => (int)$u]);' . "\n",
        var_export($root, true), var_export($state, true), var_export($dbName, true)
    );
    $script = sys_get_temp_dir() . '/picklers_boot_probe_' . getmypid() . '.php';
    file_put_contents($script, $code);
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1');
    @unlink($script);
    $json = json_decode((string)substr((string)$out, (int)strrpos((string)$out, '{')), true);
    return is_array($json) ? $json : ['error' => trim((string)$out)];
};

$required = 23;
$failures = 0;
$check = static function (string $label, bool $ok, array $seen) use (&$failures): void {
    printf("  %-58s %s  %s\n", $label, $ok ? 'PASS' : 'FAIL', json_encode($seen));
    if (!$ok) $failures++;
};

echo "Schema bootstrap verification (database: {$dbName})\n";

// 1. Fresh
$server->exec("DROP DATABASE IF EXISTS `{$dbName}`");
$resetState();
$seen = $probe();
$check('fresh: all required tables created', ($seen['tables'] ?? 0) >= $required, $seen);
$check('fresh: dev seed populated facilities and users', ($seen['facilities'] ?? 0) > 0 && ($seen['users'] ?? 0) > 1, $seen);

// 2. Stale stamp + stale seed flag over an empty database
$server->exec("DROP DATABASE IF EXISTS `{$dbName}`");
$server->exec("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
file_put_contents($state . '/.schema_version', '8');
file_put_contents($state . '/.seeded', date('c'));
$seen = $probe();
$check('stale stamp: tables recreated despite version-8 stamp', ($seen['tables'] ?? 0) >= $required, $seen);
$check('stale stamp: seed re-applied despite stale .seeded flag', ($seen['facilities'] ?? 0) > 0, $seen);

// 3. Trusted stamp: idempotent second boot
$before = $seen;
$seen = $probe();
$check('trusted stamp: second boot keeps the same data', ($seen['facilities'] ?? -1) === ($before['facilities'] ?? -2), $seen);

$server->exec("DROP DATABASE IF EXISTS `{$dbName}`");
$resetState();
echo $failures === 0 ? "OK — bootstrap scenarios verified\n" : "FAILED — {$failures} scenario check(s)\n";
exit($failures === 0 ? 0 : 1);
