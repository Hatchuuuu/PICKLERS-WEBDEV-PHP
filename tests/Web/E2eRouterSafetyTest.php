<?php
declare(strict_types=1);

namespace Picklers\Tests\Web;

use PHPUnit\Framework\TestCase;

final class E2eRouterSafetyTest extends TestCase
{
    public function testBothDeploymentShapesContainStaticFilesAndRoutePhpThroughFixtureFrontController(): void
    {
        $root = sys_get_temp_dir() . '/picklers-e2e-router-' . bin2hex(random_bytes(8));
        foreach (['scripts', 'public/assets/css', 'public/assets/images', 'public/assets/brand-logos', 'public/uploads/permits', 'public/uploads/facilities'] as $directory) {
            mkdir($root . '/' . $directory, 0750, true);
        }
        copy(dirname(__DIR__, 2) . '/scripts/e2e-router.php', $root . '/scripts/e2e-router.php');
        file_put_contents($root . '/public/index.php', '<?php header("Content-Type: application/json"); echo json_encode(["script" => $_SERVER["SCRIPT_NAME"], "uri" => $_SERVER["REQUEST_URI"], "fixture_database" => $_ENV["DB_DATABASE"] === "picklers_e2e" && getenv("DB_DATABASE") === "picklers_e2e", "fixture_state" => DATA_PATH === dirname(__DIR__) . "/database/.e2e-state", "fixture_documents" => $_ENV["PRIVATE_DOCUMENT_DIR"] === DATA_PATH . "/documents"]);');
        foreach (['css/style.css' => 'body{}', 'images/favicon.svg' => '<svg/>', 'images/logo.png' => 'image', 'brand-logos/brand.svg' => 'brand'] as $path => $content) {
            file_put_contents($root . '/public/assets/' . $path, $content);
        }
        file_put_contents($root . '/private.txt', 'outside-secret-marker');
        file_put_contents($root . '/public/.env', 'hidden-secret-marker');
        file_put_contents($root . '/public/uploads/permits/private.png', 'permit-secret-marker');
        file_put_contents($root . '/public/uploads/facilities/logo.png', 'facility-logo');
        file_put_contents($root . '/public/uploads/facilities/script.py', 'script-secret-marker');
        file_put_contents($root . '/public/uploads/facilities/script.php', '<?php echo "execution-secret-marker";');
        file_put_contents($root . '/public/other.php', '<?php echo "php-secret-marker";');
        $symlinked = @symlink($root . '/private.txt', $root . '/public/outside.txt');
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($socket);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $process = proc_open([PHP_BINARY, '-S', $address, '-t', $root . '/public', $root . '/scripts/e2e-router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', $root . '/server.log', 'a'], 2 => ['file', $root . '/server.log', 'a']], $pipes, $root);
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
            foreach (['' => '/index.php', '/PICKLERS%20WEBDEV%20PROJECT' => '/PICKLERS WEBDEV PROJECT/public/index.php', '/PICKLERS%20WEBDEV%20PROJECT/public' => '/PICKLERS WEBDEV PROJECT/public/index.php'] as $prefix => $script) {
                foreach (['/auth.php?next=owner', '/api.php', '/index.php', '/other.php', '/admin/users'] as $path) {
                    $uri = $prefix . $path;
                    $body = file_get_contents('http://' . $address . $uri, false, $context);
                    self::assertSame(['script' => $script, 'uri' => $uri, 'fixture_database' => true, 'fixture_state' => true, 'fixture_documents' => true], json_decode($body, true), $uri);
                }
                foreach (['/style.css' => 'body{}', '/favicon.svg' => '<svg/>', '/logo.png' => 'image', '/brand-logos/brand.svg' => 'brand', '/assets/css/style.css' => 'body{}', '/uploads/facilities/logo.png' => 'facility-logo'] as $path => $content) {
                    self::assertSame($content, file_get_contents('http://' . $address . $prefix . $path, false, $context));
                }
                $blocked = ['/.env', '/assets/%2e%2e/%2e%2e/.env', '/..%2fprivate.txt', '/..%5cprivate.txt', '/%00hidden', '/uploads/permits/private.png', '/uploads/facilities/script.py', '/uploads/facilities/script.php'];
                if ($symlinked) $blocked[] = '/outside.txt';
                foreach ($blocked as $path) {
                    $uri = $prefix . $path;
                    $body = file_get_contents('http://' . $address . $uri, false, $context);
                    self::assertStringNotContainsString('secret-marker', $body, $uri);
                    self::assertMatchesRegularExpression('/HTTP\/1\.1 (400|403|404)/', $http_response_header[0], $uri);
                }
            }
        } finally {
            proc_terminate($process);
            proc_close($process);
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($root);
        }
    }
}
