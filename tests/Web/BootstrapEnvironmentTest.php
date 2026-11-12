<?php
declare(strict_types=1);

namespace Picklers\Tests\Web;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BootstrapEnvironmentTest extends TestCase
{
    public static function environmentSources(): iterable
    {
        yield 'inherited deployment overrides file' => [
            ['APP_ENV' => 'production', 'APP_DEBUG' => '0', 'DB_DATABASE' => 'process_database', 'APP_SECRET' => 'process-test-secret'],
            [], 'prod', false, 'process_database', 'process-test-secret',
        ];
        yield 'explicit CLI and test overrides win' => [
            ['APP_ENV' => 'production', 'APP_DEBUG' => '0', 'DB_DATABASE' => 'process_database', 'APP_SECRET' => 'process-test-secret'],
            ['APP_ENV' => 'test', 'APP_DEBUG' => '1', 'DB_DATABASE' => 'explicit_database', 'APP_SECRET' => 'explicit-test-secret'],
            'test', true, 'explicit_database', 'explicit-test-secret',
        ];
        yield 'file provides missing values' => [[], [], 'dev', true, 'file_database', 'file-test-secret'];
    }

    #[DataProvider('environmentSources')]
    public function testEnvironmentPrecedenceWithoutPhpEnvPopulation(
        array $inherited, array $explicit, string $kernelEnv, bool $debug, string $database, string $secret,
    ): void {
        $root = sys_get_temp_dir() . '/picklers_bootstrap_' . bin2hex(random_bytes(6));
        mkdir($root . '/vendor', 0750, true);
        file_put_contents($root . '/vendor/autoload.php', '<?php');
        $dotenv = "APP_ENV=development\nAPP_DEBUG=1\nDB_DATABASE=file_database\nAPP_SECRET=file-test-secret\nPICKLERS_DATA_DIR=file-state\n";
        file_put_contents($root . '/.env', $dotenv);
        $bootstrap = dirname(__DIR__, 2) . '/config/bootstrap.php';
        $script = '<?php define("ROOT_PATH", ' . var_export($root, true) . ');'
            . 'define("DATA_PATH", ROOT_PATH . "/isolated-state");'
            . '$_ENV = ' . var_export($explicit, true) . ';'
            . 'require ' . var_export($bootstrap, true) . ';'
            . 'echo json_encode(['
            . '"kernel" => picklers_kernel_environment(), "debug" => picklers_kernel_debug(),'
            . '"database" => $_ENV["DB_DATABASE"],'
            . '"secret_matches" => $_ENV["APP_SECRET"] === ' . var_export($secret, true) . ','
            . '"state_matches" => $_ENV["PICKLERS_DATA_DIR"] === DATA_PATH && getenv("PICKLERS_DATA_DIR") === DATA_PATH,'
            . '"process_only" => $_ENV["BOOTSTRAP_PROCESS_ONLY"] ?? null]);';
        file_put_contents($root . '/check.php', $script);
        $env = getenv();
        foreach (['APP_ENV', 'APP_DEBUG', 'DB_DATABASE', 'APP_SECRET', 'PICKLERS_DATA_DIR'] as $key) unset($env[$key]);
        $env = array_replace($env, $inherited, ['PICKLERS_DATA_DIR' => 'inherited-state', 'BOOTSTRAP_PROCESS_ONLY' => 'inherited']);
        try {
            $process = proc_open([PHP_BINARY, '-d', 'variables_order=GPCS', $root . '/check.php'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
            self::assertIsResource($process);
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $error);
            self::assertSame('', $error);
            self::assertSame(['kernel' => $kernelEnv, 'debug' => $debug, 'database' => $database,
                'secret_matches' => true, 'state_matches' => true, 'process_only' => 'inherited'],
                json_decode($output, true, 512, JSON_THROW_ON_ERROR));
            self::assertFileDoesNotExist($root . '/var/app_secret');
            self::assertSame($dotenv, file_get_contents($root . '/.env'));
        } finally {
            foreach (['check.php', '.env', 'vendor/autoload.php'] as $file) unlink($root . '/' . $file);
            rmdir($root . '/vendor');
            rmdir($root);
        }
    }
}
