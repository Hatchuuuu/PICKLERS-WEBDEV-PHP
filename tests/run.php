<?php
declare(strict_types=1);

/**
 * PICKLERS — test runner (zero dependencies).
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
define('ROOT_PATH', $root);
define('APP_PATH', $root . '/src');
define('CONFIG_PATH', $root . '/config');
define('VIEWS_PATH', $root . '/views');
// The suite gets its OWN schema-stamp / seed-flag / JSON-fallback directory,
// separate from the app's /database. Those stamp files are keyed by path,
// not by MySQL database name — pointing the suite at picklers_test while
// still reading /database/.schema_version would see production's stamp,
// skip schema creation on the empty test database, and every query would
// fail against tables that were never created.
define('DATA_PATH', $root . '/database/.test-state');
define('PUBLIC_PATH', $root . '/public');

// Load .env so the suite exercises the same host/credentials the app does.
$envFile = $root . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k); $v = trim($v);
        if (strlen($v) >= 2 && (($v[0] === '"' && $v[-1] === '"') || ($v[0] === "'" && $v[-1] === "'"))) {
            $v = substr($v, 1, -1);
        }
        $_ENV[$k] = $v;
    }
}

// The suite must never read or write the live database: an admin toggling a
// promo code or cancelling a real booking must not make `php tests/run.php`
// flip red, and a test bug must never touch a real player's data. Everything
// below runs against a dedicated, disposable `picklers_test` database that
// this process creates, schemas and seeds on demand (Database::__construct()
// already does exactly that for any database that doesn't exist yet).
$_ENV['DB_DATABASE'] = 'picklers_test';
putenv('DB_DATABASE=picklers_test');
if (!is_dir(DATA_PATH)) {
    @mkdir(DATA_PATH, 0750, true);
}

require_once APP_PATH . '/Core/Autoloader.php';
Picklers\Core\Autoloader::register('Picklers\\', APP_PATH . '/');

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
    exit($filter !== '' ? "No suites match \"{$filter}\".\n" : "No test suites found.\n");
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
$__db = \Picklers\Core\Database::get();
if (!$__db->isUsingMySQL()) {
    fwrite(STDERR, "MySQL is unreachable — the suite needs the isolated picklers_test database, not the JSON fallback.\n");
    exit(1);
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
