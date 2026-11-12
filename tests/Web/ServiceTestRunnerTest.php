<?php
declare(strict_types=1);

namespace Picklers\Tests\Web;

use PHPUnit\Framework\TestCase;

final class ServiceTestRunnerTest extends TestCase
{
    public function testUnmatchedSuiteFilterFailsBeforeDatabasePreparation(): void
    {
        $root = dirname(__DIR__, 2);
        $environment = array_replace(getenv(), ['DB_HOST' => 'picklers-no-suite.invalid']);
        $process = proc_open([PHP_BINARY, $root . '/tests/run.php', '__no_matching_suite__'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $environment);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(1, proc_close($process), $output);
        self::assertStringContainsString('No suites match "__no_matching_suite__".', $output);
        self::assertStringNotContainsString('Could not prepare', $output);
    }
}
