<?php
declare(strict_types=1);

namespace Picklers\Web\Http;

/**
 * Makes the request's base URL the install directory (e.g.
 * /PICKLERS%20WEBDEV%20PROJECT) rather than /public when the app is served from
 * a sub-directory. Apache rewrites /<base>/app to /<base>/public/index.php,
 * which leaves Symfony unable to relate SCRIPT_NAME to REQUEST_URI; presenting
 * the script as /<base>/index.php gives baseUrl=/<base>, pathInfo=/app. URLs
 * that really include /public/ are left untouched.
 */
final class InstallBase
{
    /** @param array<string,mixed> $server */
    public static function server(array $server): array
    {
        $scriptName = str_replace('\\', '/', (string)($server['SCRIPT_NAME'] ?? '/index.php'));
        $scriptDir = \dirname($scriptName);
        $decodedUri = rawurldecode((string)($server['REQUEST_URI'] ?? '/'));
        if (str_ends_with($scriptDir, '/public') && !str_starts_with($decodedUri, $scriptDir . '/') && $decodedUri !== $scriptDir) {
            $server['SCRIPT_NAME'] = substr($scriptDir, 0, -strlen('/public')) . '/index.php';
            $server['PHP_SELF'] = $server['SCRIPT_NAME'];
        }

        return $server;
    }
}
