<?php
declare(strict_types=1);

namespace Picklers\Tests\Web;

use PHPUnit\Framework\TestCase;

final class DevelopmentRouterTest extends TestCase
{
    public function testBuiltInServerRoutesLegacyPagesAndContainsStaticFiles(): void
    {
        $router = dirname(__DIR__, 2) . '/scripts/dev-router.php';
        self::assertFileExists($router);
        $root = sys_get_temp_dir() . '/picklers-router-' . bin2hex(random_bytes(8));
        foreach (['scripts', 'public/assets/css', 'public/assets/images', 'public/assets/brand-logos', 'public/uploads/permits', 'public/uploads/facilities'] as $directory) {
            mkdir($root . '/' . $directory, 0750, true);
        }
        copy($router, $root . '/scripts/dev-router.php');
        file_put_contents($root . '/public/index.php', '<?php header("Content-Type: application/json"); echo json_encode(["script" => $_SERVER["SCRIPT_NAME"], "uri" => $_SERVER["REQUEST_URI"]]);');
        foreach (['css/style.css' => 'body{}', 'images/favicon.svg' => '<svg/>', 'images/logo.png' => 'image', 'brand-logos/brand.svg' => 'brand'] as $path => $content) {
            file_put_contents($root . '/public/assets/' . $path, $content);
        }
        file_put_contents($root . '/private.txt', 'private-secret');
        file_put_contents($root . '/public/.env', 'hidden-secret');
        file_put_contents($root . '/public/uploads/permits/private.png', 'permit-secret');
        file_put_contents($root . '/public/uploads/facilities/logo.png', 'facility-logo');
        file_put_contents($root . '/public/uploads/facilities/script.py', 'script-secret');
        file_put_contents($root . '/public/uploads/script.cmd', 'script-secret');
        file_put_contents($root . '/public/other.php', '<?php echo "unexpected-script";');
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($socket);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $process = proc_open([PHP_BINARY, '-S', $address, '-t', $root . '/public', $root . '/scripts/dev-router.php'], [0 => ['pipe', 'r'], 1 => ['file', $root . '/server.log', 'a'], 2 => ['file', $root . '/server.log', 'a']], $pipes, $root);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 2]]);
        try {
            for ($attempt = 0; $attempt < 50; ++$attempt) {
                if (($connection = @stream_socket_client('tcp://' . $address, $errorCode, $errorMessage, 0.1)) !== false) {
                    fclose($connection);
                    break;
                }
                usleep(100000);
            }
            foreach (['/auth.php?next=owner' => '/auth.php?next=owner', '/api.php' => '/api.php', '/other.php' => '/other.php', '/admin/users' => '/admin/users'] as $path => $uri) {
                $body = file_get_contents('http://' . $address . $path, false, $context);
                self::assertSame(['script' => '/index.php', 'uri' => $uri], json_decode($body, true));
            }
            foreach (['/style.css' => 'body{}', '/favicon.svg' => '<svg/>', '/logo.png' => 'image', '/brand-logos/brand.svg' => 'brand', '/assets/css/style.css' => 'body{}', '/uploads/facilities/logo.png' => 'facility-logo'] as $path => $content) {
                self::assertSame($content, file_get_contents('http://' . $address . $path, false, $context));
            }
            foreach (['/.env', '/assets/%2e%2e/%2e%2e/.env', '/%2e%2e/private.txt', '/uploads/permits/private.png', '/uploads/facilities/script.py', '/uploads/script.cmd'] as $path) {
                $body = file_get_contents('http://' . $address . $path, false, $context);
                self::assertStringNotContainsString('secret', $body);
                self::assertMatchesRegularExpression('/HTTP\/1\.1 (403|404)/', $http_response_header[0]);
            }
        } finally {
            proc_terminate($process);
            proc_close($process);
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($root);
        }
    }
}
