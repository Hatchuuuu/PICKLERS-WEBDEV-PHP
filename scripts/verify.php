<?php
declare(strict_types=1);

/**
 * Runs every automated verification gate and prints a pass/fail summary.
 *
 *   php scripts/verify.php            all gates
 *   php scripts/verify.php --quick    skip the slow database suites
 *
 * Uses only disposable databases (picklers_test, picklers_admin_test,
 * picklers_test_boot). The upgrade rehearsal is separate because it reads the
 * live database: php scripts/rehearse-upgrade.php
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}
$root = dirname(__DIR__);
chdir($root);
$quick = in_array('--quick', $argv, true);
$php = escapeshellarg(PHP_BINARY);

$gates = [];
// Syntax of every PHP file in the app (excluding vendor/var).
$gates['php -l (all project PHP files)'] = static function () use ($root, $php): array {
    $bad = [];
    $count = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $p = str_replace('\\', '/', $f->getPathname());
        if (!str_ends_with($p, '.php') || preg_match('#/(vendor|var|\.git|graphify-out|node_modules)/#', $p)) {
            continue;
        }
        $count++;
        exec("{$php} -l " . escapeshellarg($p) . ' 2>&1', $o, $c);
        if ($c !== 0) {
            $bad[] = $p;
        }
    }
    exec("{$php} -l bin/console 2>&1", $o2, $c2);

    return [$bad === [] && $c2 === 0, ($count + 1) . ' files' . ($bad ? '; errors: ' . implode(', ', $bad) : '')];
};
$cmd = static fn(string $c) => static function () use ($c): array {
    exec($c . ' 2>&1', $out, $code);
    $text = preg_replace('/\e\[[0-9;]*m/', '', implode("\n", $out));
    $lines = array_values(array_filter(array_map('trim', explode("\n", (string)$text))));

    return [$code === 0, end($lines) ?: ''];
};
$gates['composer validate --strict'] = $cmd('composer validate --strict');
$gates['composer check-platform-reqs'] = $cmd('composer check-platform-reqs');
$gates['composer audit'] = $cmd('composer audit');
foreach (['dev', 'test', 'prod'] as $env) {
    $gates["lint:container ({$env})"] = $cmd("{$php} bin/console lint:container --env={$env}");
}
$gates['lint:twig templates'] = $cmd("{$php} bin/console lint:twig templates");
$gates['debug:router (admin routes)'] = static function () use ($php): array {
    exec("{$php} bin/console debug:router --format=json 2>&1", $out);
    $routes = json_decode(implode("\n", $out), true) ?: [];
    $paths = array_column($routes, 'path');
    $ok = in_array('/admin', $paths, true) && in_array('/admin/api', $paths, true) && in_array('/api', $paths, true) && in_array('/admin/document', $paths, true);

    return [$ok, count($routes) . ' Symfony routes'];
};
$gates['cache:warmup (prod)'] = $cmd("{$php} bin/console cache:warmup --env=prod");
if (!$quick) {
    $gates['schema bootstrap (fresh + stale stamp)'] = $cmd("{$php} scripts/verify-schema-bootstrap.php");
    $gates['legacy suite: php tests/run.php --fresh'] = $cmd("{$php} tests/run.php --fresh");
    $gates['admin suite: vendor/bin/phpunit'] = $cmd("{$php} vendor/bin/phpunit");
}

$failed = 0;
echo "PICKLERS verification gates\n" . str_repeat('=', 72) . "\n";
foreach ($gates as $name => $gate) {
    [$ok, $detail] = $gate();
    printf("%-44s %s  %s\n", $name, $ok ? 'PASS' : 'FAIL', mb_strimwidth((string)$detail, 0, 90, '…'));
    $failed += $ok ? 0 : 1;
}
echo str_repeat('=', 72) . "\n" . ($failed === 0 ? "All gates passed.\n" : "{$failed} gate(s) failed.\n");
exit($failed === 0 ? 0 : 1);
