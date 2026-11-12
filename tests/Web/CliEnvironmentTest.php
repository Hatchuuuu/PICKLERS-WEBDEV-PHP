<?php
declare(strict_types=1);

namespace Picklers\Tests\Web;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CliEnvironmentTest extends TestCase
{
    public static function scripts(): iterable
    {
        yield 'fixtures' => ['scripts/seed-fixtures.php', 'environment_probe_test'];
        yield 'schema verification' => ['scripts/verify-schema-bootstrap.php', ''];
        yield 'upgrade rehearsal' => ['scripts/rehearse-upgrade.php', 'environment_probe_test'];
    }

    #[DataProvider('scripts')]
    public function testInheritedDatabaseConfigurationWinsOverLocalFile(string $script, string $argument): void
    {
        $root = dirname(__DIR__, 2);
        $environment = array_replace(getenv(), [
            'DB_HOST' => 'picklers-environment-probe.invalid',
            'DB_PORT' => '3306',
            'DB_USERNAME' => 'environment_probe',
            'DB_PASSWORD' => '',
        ]);
        $process = proc_open([PHP_BINARY, $root . '/' . $script, $argument],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $environment);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertNotSame(0, proc_close($process), 'The probe must fail before connecting to any database.');
        self::assertStringContainsString('picklers-environment-probe.invalid', $output);
        self::assertStringNotContainsString('file(', $output, 'CLI tools must work without a host .env file.');
    }
}
