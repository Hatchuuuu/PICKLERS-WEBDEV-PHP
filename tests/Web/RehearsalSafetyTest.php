<?php
declare(strict_types=1);

namespace Picklers\Tests\Web;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RehearsalSafetyTest extends TestCase
{
    /** @return iterable<string, array{string,bool}> */
    public static function failedRuns(): iterable
    {
        yield 'dump failure' => ['dump_failure', false];
        yield 'import failure' => ['import_failure', false];
        yield 'unexpected exception' => ['query_failure', false];
        yield 'cleanup exception still deletes dump' => ['cleanup_failure', false];
        yield 'explicitly retained failed dump' => ['dump_failure', true];
    }

    #[DataProvider('failedRuns')]
    public function testFailureStopsAndCleansUp(string $mode, bool $keepDump): void
    {
        $root = sys_get_temp_dir() . '/picklers_rehearsal_' . bin2hex(random_bytes(6));
        mkdir($root . '/scripts', 0750, true);
        mkdir($root . '/vendor');
        mkdir($root . '/config');
        copy(dirname(__DIR__, 2) . '/config/bootstrap.php', $root . '/config/bootstrap.php');
        file_put_contents($root . '/vendor/autoload.php', '<?php');
        file_put_contents($root . '/.env', "DB_HOST=unused\n");
        // Run the actual script with namespace substitutes: no socket or shell command can execute.
        $shim = <<<'PHP'

namespace PicklersRehearsalHarness;
use RuntimeException;
use Throwable;
class PDO extends \PDO {
    public function __construct(...$args) {}
    public function exec(string $statement): int|false {
        file_put_contents($GLOBALS['root'] . '/events', $statement . "\n", FILE_APPEND);
        if (getenv('REHEARSAL_FAILURE') === 'cleanup_failure' && str_starts_with($statement, 'DROP')
            && substr_count((string)file_get_contents($GLOBALS['root'] . '/events'), 'DROP') > 1) {
            throw new \RuntimeException('simulated cleanup failure');
        }
        return 0;
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false {
        throw new \RuntimeException('simulated snapshot failure');
    }
}
function exec(string $command, ?array &$output = null, ?int &$result = null): string|false {
    $dump = str_contains($command, 'mysqldump');
    file_put_contents($GLOBALS['root'] . '/events', ($dump ? 'dump' : 'import') . "\n", FILE_APPEND);
    if ($dump) file_put_contents($GLOBALS['dumpFile'], 'partial sensitive dump');
    $result = getenv('REHEARSAL_FAILURE') === ($dump ? 'dump_failure' : 'import_failure') ? 1 : 0;
    $output = $result ? ['simulated command failure'] : [];
    return '';
}
PHP;
        $source = file_get_contents(dirname(__DIR__, 2) . '/scripts/rehearse-upgrade.php');
        file_put_contents($root . '/scripts/rehearse-upgrade.php', str_replace('declare(strict_types=1);', 'declare(strict_types=1);' . $shim, $source));
        try {
            $command = [PHP_BINARY, $root . '/scripts/rehearse-upgrade.php', 'safe_source'];
            if ($keepDump) $command[] = '--keep-dump';
            $env = getenv();
            $env['REHEARSAL_FAILURE'] = $mode;
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
            self::assertIsResource($process);
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(1, proc_close($process), $output . $error);
            self::assertStringContainsString('Rehearsal stopped:', $error);
            $expectedFailure = match ($mode) {
                'dump_failure' => 'read-only dump of source failed.',
                'import_failure' => 'import into disposable copy failed.',
                default => 'simulated snapshot failure',
            };
            self::assertStringContainsString($expectedFailure, $error);
            self::assertFileExists($root . '/events', $output . $error);
            self::assertStringNotContainsString('OK —', $output);
            self::assertCount($keepDump ? 1 : 0, glob($root . '/var/rehearsal/*.sql'));
            self::assertSame([], glob($root . '/var/rehearsal/state/*'));
            $events = file_get_contents($root . '/events');
            if ($mode === 'dump_failure') {
                self::assertSame("dump\n", $events, 'A failed dump must not touch the target or attempt import.');
            } else {
                self::assertSame(1, substr_count($events, "import\n"));
                self::assertSame(2, substr_count($events, 'DROP DATABASE IF EXISTS `picklers_upgrade_rehearsal`'));
                if ($mode === 'cleanup_failure') self::assertStringContainsString('Could not remove disposable database:', $error);
            }
        } finally {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($root);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeSources(): iterable
    {
        yield 'target cannot be source' => ['picklers_upgrade_rehearsal'];
        yield 'SQL identifier cannot contain quoting' => ['unsafe`database'];
        yield 'source cannot escape dump directory' => ['../unsafe'];
    }

    #[DataProvider('unsafeSources')]
    public function testUnsafeSourceRejectedBeforeDatabaseConnection(string $source): void
    {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/scripts/rehearse-upgrade.php', $source],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes
        );
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(1, proc_close($process));
        self::assertStringContainsString('different from the disposable rehearsal target', $error);
        self::assertSame('', $output);
    }
}
