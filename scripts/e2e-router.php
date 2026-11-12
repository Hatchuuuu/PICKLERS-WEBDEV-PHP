<?php
declare(strict_types=1);

/**
 * PICKLERS — isolated browser-test router for PHP's built-in web server.
 *
 *   php -S 127.0.0.1:8099 -t public scripts/e2e-router.php
 *
 * Every request served through this router uses the disposable `picklers_e2e`
 * database and its own schema-stamp/seed directory (database/.e2e-state), so
 * end-to-end checks and screenshots never read or write the live database or
 * a real person's account. The router refuses to start for any other database.
 *
 * Two deployment shapes are emulated:
 *   http://127.0.0.1:8099/admin                          public/ as document root
 *   http://127.0.0.1:8099/PICKLERS%20WEBDEV%20PROJECT/admin  XAMPP sub-directory install
 */

if (PHP_SAPI !== 'cli-server') {
    http_response_code(403);
    exit("e2e router only runs under the built-in web server.\n");
}

$root = dirname(__DIR__);
$_ENV['DB_DATABASE'] = 'picklers_e2e';
putenv('DB_DATABASE=picklers_e2e');
// Keep the e2e run in the development profile regardless of the local .env.
$_ENV['APP_ENV'] = $_ENV['APP_ENV'] ?? 'development';
if (!defined('DATA_PATH')) {
    define('DATA_PATH', $root . '/database/.e2e-state');
}
// Synthetic application documents live in the isolated fixture store, never in
// the real private storage/permits directory.
$_ENV['PRIVATE_DOCUMENT_DIR'] = $root . '/database/.e2e-state/documents';
if (!is_dir(DATA_PATH)) {
    @mkdir(DATA_PATH, 0750, true);
}

$subdir = '/PICKLERS WEBDEV PROJECT';
$uri    = (string)($_SERVER['REQUEST_URI'] ?? '/');
$path   = str_replace('\\', '/', rawurldecode((string)parse_url($uri, PHP_URL_PATH)));
if (str_contains($path, "\0") || preg_match('#(?:^|/)\.[^/]*(?:/|$)#', $path)) {
    http_response_code(403);
    return true;
}

/** Mirrors the legacy asset aliases declared in public/.htaccess. */
$legacyAlias = static function (string $inner) use ($root): ?string {
    if ($inner === '/style.css') return $root . '/public/assets/css/style.css';
    if ($inner === '/favicon.svg') return $root . '/public/assets/images/favicon.svg';
    if (str_starts_with($inner, '/brand-logos/')) return $root . '/public/assets' . $inner;
    if (preg_match('#^/[^/]+\.(svg|png|jpe?g|webp)$#i', $inner) && is_file($root . '/public/assets/images' . $inner)) {
        return $root . '/public/assets/images' . $inner;
    }
    return null;
};

// Resolve both deployment shapes before applying the same public-file boundary.
$subdirectoryInstall = str_starts_with($path, $subdir . '/') || $path === $subdir;
$inner = $subdirectoryInstall ? (substr($path, strlen($subdir)) ?: '/') : $path;
if ($subdirectoryInstall) {
    if (str_starts_with($inner, '/public/')) {
        $inner = substr($inner, 7);
    }
}
$public = realpath($root . '/public');
$file = realpath($public . $inner);
if ($file === false || !is_file($file)) {
    if (($alias = $legacyAlias($inner)) !== null && is_file($alias)) {
        $file = realpath($alias);
    }
}
$boundary = $public . DIRECTORY_SEPARATOR;
if ($file !== false && $file !== $public && (PHP_OS_FAMILY === 'Windows'
    ? strncasecmp($file, $boundary, strlen($boundary)) !== 0
    : !str_starts_with($file, $boundary))) {
    http_response_code(403);
    return true;
}
$relative = $file === false ? $inner : '/' . str_replace('\\', '/', substr($file, strlen($public) + 1));
$uploadExecutable = '/\.(php\d*|phtml|phar|pht|inc|cgi|pl|py|sh|exe|bat|cmd|ps1)$/i';
// PHP's server ignores .htaccess: enforce hidden-file and upload protections here.
if (preg_match('#(?:^|/)\.[^/]*(?:/|$)|^/uploads/permits(?:/|$)#i', $inner)
    || preg_match('#(?:^|/)\.[^/]*(?:/|$)|^/uploads/permits(?:/|$)#i', $relative)
    || (str_starts_with(strtolower($inner), '/uploads/') && preg_match($uploadExecutable, $inner))
    || (str_starts_with(strtolower($relative), '/uploads/') && preg_match($uploadExecutable, $relative))) {
    http_response_code(403);
    return true;
}
if ($file !== false && is_file($file) && !preg_match('/\.(php\d*|phtml|phar|pht|inc|cgi|pl|py|sh)$/i', $file)) {
    return serveStatic($file);
}
$_SERVER['SCRIPT_NAME']     = $subdirectoryInstall ? $subdir . '/public/index.php' : '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root . '/public/index.php';
$_SERVER['PHP_SELF']        = $_SERVER['SCRIPT_NAME'];
require $root . '/public/index.php';
return true;

function serveStatic(string $file): bool {
    $types = [
        'css' => 'text/css', 'js' => 'application/javascript', 'svg' => 'image/svg+xml',
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp',
        'woff2' => 'font/woff2', 'json' => 'application/json',
    ];
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    readfile($file);
    return true;
}
