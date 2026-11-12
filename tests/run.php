<?php
declare(strict_types=1);

/**
 * PICKLERS — service test runner.
 *
 *   php tests/run.php            run every suite
 *   php tests/run.php Pricing    run suites whose name contains "Pricing"
 *
 * Exits non-zero when anything fails, so it can gate CI.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Tests are CLI-only.\n");
}

$root = dirname(__DIR__);
// Force isolation before shared bootstrap loads environment defaults.
define('DATA_PATH', $root . '/database/.test-state');
$_ENV['DB_DATABASE'] = 'picklers_test';
$_ENV['APP_ENV'] = 'test';
putenv('DB_DATABASE=picklers_test');
putenv('APP_ENV=test');
require $root . '/config/bootstrap.php';

// `--fresh` rebuilds the disposable test database from nothing: it drops ONLY
// `picklers_test` and clears ONLY the suite's own stamp directory, so the run
// starts from the same state on every machine.
$argvFiltered = array_values(array_filter($argv, fn($a) => $a !== '--fresh'));
if (count($argvFiltered) !== count($argv)) {
    $argv = $argvFiltered;
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $_ENV['DB_HOST'] ?? '127.0.0.1', (int)($_ENV['DB_PORT'] ?? 3306)),
        $_ENV['DB_USERNAME'] ?? 'root', $_ENV['DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $pdo->exec('DROP DATABASE IF EXISTS `picklers_test`');
    foreach (['.schema_version', '.seeded', '.court_names_normalized'] as $stampFile) {
        @unlink(DATA_PATH . '/' . $stampFile);
    }
    echo "Rebuilt isolated database picklers_test from scratch.\n";
}

require_once __DIR__ . '/TestCase.php';

$filter  = $argv[1] ?? '';
$suites  = [];
foreach (glob(__DIR__ . '/*Test.php') ?: [] as $file) {
    require_once $file;
    $class = 'Picklers\\Tests\\' . basename($file, '.php');
    if (!class_exists($class)) continue;
    if ($filter !== '' && stripos($class, $filter) === false) continue;
    $suites[] = new $class();
}

if (!$suites) {
    fwrite(STDERR, $filter !== '' ? "No suites match \"{$filter}\".\n" : "No test suites found.\n");
    exit(1);
}

$totalPassed = 0;
$totalFailed = 0;
$started     = microtime(true);

// Belt-and-suspenders: if a future edit ever lets $_ENV['DB_DATABASE'] resolve
// back to the production name, refuse to run rather than mutate real data.
if (($_ENV['DB_DATABASE'] ?? '') !== 'picklers_test') {
    fwrite(STDERR, "Refusing to run: DB_DATABASE is \"{$_ENV['DB_DATABASE']}\", not the isolated test database.\n");
    exit(1);
}
// Install the shared base schema and apply the application's Doctrine migrations.
foreach (['picklers:schema:install', 'doctrine:migrations:migrate -n'] as $command) {
    $output = [];
    $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/console')
        . ' ' . $command . ' --env=test --database=picklers_test --data-path=database/.test-state 2>&1', $output, $code);
    if ($code !== 0) {
        fwrite(STDERR, "Could not prepare picklers_test:\n" . implode("\n", $output) . "\n");
        exit(1);
    }
}

echo "\nPICKLERS test suite (database: picklers_test)\n";
echo str_repeat('=', 58) . "\n";

foreach ($suites as $suite) {
    $label = $suite->name();
    try {
        $suite->run();
    } catch (\Throwable $e) {
        echo "  {$label}\n";
        echo "    ERROR  " . get_class($e) . ': ' . $e->getMessage() . "\n";
        echo "           " . $e->getFile() . ':' . $e->getLine() . "\n\n";
        $totalFailed++;
        continue;
    }

    $passed   = $suite->passedCount();
    $failures = $suite->failureMessages();
    $totalPassed += $passed;
    $totalFailed += count($failures);

    printf("  %-28s %2d passed%s\n", $label, $passed,
        $failures ? sprintf(', %d FAILED', count($failures)) : '');

    foreach ($failures as $f) {
        echo "    FAIL   {$f}\n";
    }
}

$elapsed = round((microtime(true) - $started) * 1000);
echo str_repeat('=', 58) . "\n";

if ($totalFailed === 0) {
    echo "OK — {$totalPassed} assertions passed in {$elapsed}ms\n\n";
    exit(0);
}
echo "FAILED — {$totalPassed} passed, {$totalFailed} failed in {$elapsed}ms\n\n";
exit(1);
