<?php
declare(strict_types=1);

/** HTTP acceptance checks against the real container web server; no database writes. */
if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8080', '/');
$checks = [
    ['GET', '/', 200, 'PICKLERS'],
    ['GET', '/index.php', 200, 'PICKLERS'],
    ['GET', '/auth', 200, 'PICKLERS'],
    ['GET', '/auth.php', 200, 'PICKLERS'],
    ['GET', '/app', 302, null],
    ['GET', '/app.php', 302, null],
    ['GET', '/owner', 302, null],
    ['GET', '/admin', 302, null],
    ['GET', '/admin/api', 401, null],
    ['GET', '/api?action=bookings', 200, '"success":false,"bookings":[]'],
    ['POST', '/auth', 403, null],
    ['GET', '/assets/css/app.css', 200, null],
    ['GET', '/assets/js/app.js', 200, null],
    ['GET', '/style.css', 200, null],
    ['GET', '/favicon.svg', 200, '<svg'],
    ['GET', '/.env', 403, null],
    ['GET', '/config/bootstrap.php', 404, null],
    ['GET', '/storage/permits/private.pdf', 404, null],
    ['GET', '/vendor/autoload.php', 404, null],
    ['GET', '/uploads/probe.php', 403, null],
    ['GET', '/uploads/permits/probe.pdf', 403, null],
];

$failed = 0;
foreach ($checks as [$method, $path, $expected, $needle]) {
    $context = stream_context_create(['http' => [
        'method' => $method, 'ignore_errors' => true, 'follow_location' => 0,
        'timeout' => 10, 'header' => "Connection: close\r\n" . ($method === 'POST' ? "Accept: application/json\r\n" : ''),
    ]]);
    $http_response_header = [];
    $body = @file_get_contents($base . $path, false, $context);
    preg_match('/^HTTP\/\S+ (\d+)/', $http_response_header[0] ?? '', $match);
    $status = (int)($match[1] ?? 0);
    $ok = $status === $expected && ($needle === null || str_contains((string)$body, $needle));
    printf("%s %s %s (HTTP %d, expected %d)\n", $ok ? 'PASS' : 'FAIL', $method, $path, $status, $expected);
    $failed += $ok ? 0 : 1;
}
printf("%d/%d HTTP checks passed.\n", count($checks) - $failed, count($checks));
exit($failed === 0 ? 0 : 1);
