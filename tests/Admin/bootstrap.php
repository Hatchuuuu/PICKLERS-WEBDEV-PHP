<?php
declare(strict_types=1);

/**
 * Bootstrap for the Symfony admin suite.
 *
 * Isolation: every run rebuilds the disposable database `picklers_admin_test`
 * from nothing (legacy schema bootstrap → Doctrine migrations → synthetic
 * fixtures). It refuses to touch any database not named *_test. The live
 * `picklers_db`, its data directory and its private documents are never used.
 */

const ADMIN_TEST_DB = 'picklers_admin_test';

$root = dirname(__DIR__, 2);
$_ENV['APP_ENV'] = 'test';
$_ENV['APP_DEBUG'] = '0';
$_ENV['DB_DATABASE'] = ADMIN_TEST_DB;
$_ENV['DB_STRICT_MYSQL'] = 'true';
$_ENV['PRIVATE_DOCUMENT_DIR'] = $root . '/database/.test-state/documents';
putenv('DB_DATABASE=' . ADMIN_TEST_DB);
define('DATA_PATH', $root . '/database/.test-state/admin');
if (!is_dir(DATA_PATH)) {
    mkdir(DATA_PATH, 0750, true);
}
if (!preg_match('/_test$/', ADMIN_TEST_DB)) {
    fwrite(STDERR, "Refusing to run against a non-test database.\n");
    exit(1);
}

require $root . '/config/bootstrap.php';

// One PHP session for the whole run (as public/index.php provides in production),
// so legacy code calling session_start() never replaces the $_SESSION a test set.
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_cookies', '0');
    ini_set('session.save_path', sys_get_temp_dir());
    session_start();
}

if (getenv('PICKLERS_SKIP_DB_REBUILD') !== '1') {
    // The test kernel runs without debug, so its compiled container is never
    // refreshed automatically; start every run from fresh configuration.
    $cacheDir = $root . '/var/cache/test';
    if (is_dir($cacheDir)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($cacheDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($cacheDir);
    }

    $server = new PDO(
        sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $_ENV['DB_HOST'] ?? '127.0.0.1', (int)($_ENV['DB_PORT'] ?? 3306)),
        $_ENV['DB_USERNAME'] ?? 'root', $_ENV['DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $server->exec('DROP DATABASE IF EXISTS `' . ADMIN_TEST_DB . '`');
    foreach (['.schema_version', '.seeded', '.court_names_normalized'] as $stamp) {
        @unlink(DATA_PATH . '/' . $stamp);
    }

    // Legacy schema + migrations + fixtures, each in a child process so this
    // process starts with no singleton state.
    $php = escapeshellarg(PHP_BINARY);
    $console = escapeshellarg($root . '/bin/console');
    $env = ' --env=test --database=' . ADMIN_TEST_DB . ' --data-path=database/.test-state/admin';
    $run = static function (string $cmd) use ($root): void {
        $out = [];
        $code = 0;
        exec('cd ' . escapeshellarg($root) . ' && ' . $cmd . ' 2>&1', $out, $code);
        if ($code !== 0) {
            fwrite(STDERR, "Test database setup failed: {$cmd}\n" . implode("\n", $out) . "\n");
            exit(1);
        }
    };
    // The legacy bootstrap creates the database and its base tables; Doctrine
    // (its own connection) then applies the migrations on top.
    $run("{$php} -r " . escapeshellarg(
        "define('DATA_PATH', getcwd() . '/database/.test-state/admin'); \$_ENV['DB_DATABASE'] = '" . ADMIN_TEST_DB . "'; "
        . "\$_ENV['DB_STRICT_MYSQL'] = 'true'; require 'config/bootstrap.php'; Picklers\\Core\\Database::get();"
    ));
    $run("{$php} {$console} doctrine:migrations:migrate -n{$env}");
    $run("{$php} " . escapeshellarg($root . '/scripts/seed-fixtures.php') . ' ' . ADMIN_TEST_DB);
    $run("{$php} {$console} picklers:admin:privilege grant root@fixture.test --reason=\"Test fixture privileged admin\"{$env}");
    putenv('PICKLERS_SKIP_DB_REBUILD=1'); // child worker processes reuse it
}
