<?php
declare(strict_types=1);

namespace Picklers\Tests\Web;

use PHPUnit\Framework\TestCase;

final class TestSuiteIsolationTest extends TestCase
{
    public function testAnotherSuiteCannotRebuildTheActiveTestDatabase(): void
    {
        $root = dirname(__DIR__, 2);
        $environment = getenv();
        $environment['PICKLERS_SKIP_DB_REBUILD'] = '0';
        $command = [PHP_BINARY, '-r', 'require ' . var_export($root . '/tests/Admin/bootstrap.php', true) . ';'];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $environment);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(75, proc_close($process), $output);
        self::assertStringContainsString('Another test suite is already using', $output);
    }
}
