<?php
declare(strict_types=1);

namespace Picklers\Tests\Web;

use PHPUnit\Framework\TestCase;

final class DataPathTest extends TestCase
{
    public function testWarmContainerUsesEachProcessesOwnStateDirectory(): void
    {
        $script = tempnam(sys_get_temp_dir(), 'picklers_path_');
        $root = dirname(__DIR__, 2);
        file_put_contents($script, '<?php define("DATA_PATH", $argv[1]); require '
            . var_export($root . '/config/bootstrap.php', true)
            . '; $kernel = new Picklers\\Kernel("test", false); $kernel->boot(); '
            . 'echo $kernel->getContainer()->getParameter("picklers.data_dir");');
        try {
            foreach (['first', 'second'] as $name) {
                $path = $root . '/database/.test-state/' . $name;
                $output = [];
                exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($path) . ' 2>&1', $output, $status);
                self::assertSame(0, $status, implode("\n", $output));
                self::assertSame($path, implode("\n", $output));
            }
        } finally {
            unlink($script);
        }
    }
}
