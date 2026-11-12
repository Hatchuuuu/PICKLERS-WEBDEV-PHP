<?php
declare(strict_types=1);

// php -S 127.0.0.1:8080 -t public scripts/dev-router.php
if (PHP_SAPI !== 'cli-server') {
    http_response_code(403);
    exit("Development router only runs under the built-in web server.\n");
}

$public = realpath(dirname(__DIR__) . '/public');
$path = str_replace('\\', '/', rawurldecode((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH)));
if (str_contains($path, "\0") || preg_match('#(?:^|/)\.[^/]*(?:/|$)#', $path)) {
    http_response_code(403);
    return true;
}
$file = realpath($public . $path);
$alias = false;
if ($file === false || !is_file($file)) {
    $target = match (true) {
        $path === '/style.css' => '/assets/css/style.css',
        $path === '/favicon.svg' => '/favicon.svg',
        $path === '/favicon.ico' => '/favicon.ico',
        str_starts_with($path, '/brand-logos/') => '/assets' . $path,
        (bool)preg_match('#^/[^/]+\.(svg|png|jpe?g|webp|ico)$#i', $path) => '/assets/images' . $path,
        default => $path,
    };
    $file = realpath($public . $target);
    $alias = $target !== $path;
}
if ($file !== false && strncasecmp($file, $public . DIRECTORY_SEPARATOR, strlen($public) + 1) !== 0 && $file !== $public) {
    http_response_code(403);
    return true;
}
$relative = $file === false ? $path : '/' . str_replace('\\', '/', substr($file, strlen($public) + 1));
// Mirror the public upload directories' .htaccess protections, including resolved symlinks.
if (preg_match('#(?:^|/)\.[^/]*(?:/|$)|^/uploads/permits(?:/|$)#i', $path)
    || preg_match('#(?:^|/)\.[^/]*(?:/|$)|^/uploads/permits(?:/|$)#i', $relative)
    || (str_starts_with(strtolower($relative), '/uploads/') && preg_match('/\.(php\d*|phtml|phar|pht|inc|cgi|pl|py|sh|exe|bat|cmd|ps1)$/i', $relative))) {
    http_response_code(403);
    return true;
}
if ($file !== false && is_file($file) && !preg_match('/\.(php\d*|phtml|phar|pht|inc|cgi|pl|py|sh)$/i', $file)) {
    if (!$alias) {
        return false;
    }
    $types = ['css' => 'text/css', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'ico' => 'image/x-icon'];
    header('Content-Type: ' . ($types[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
    readfile($file);
    return true;
}
$_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $public . '/index.php';
require $public . '/index.php';
return true;
