<?php
declare(strict_types=1);

namespace Picklers\Tests\Web;

use PHPUnit\Framework\TestCase;

final class MaintenanceExitTest extends TestCase
{
    public function testUnknownCommandFailsBeforeConnecting(): void
    {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/scripts/maintenance.php') . ' unknown-command 2>&1', $output, $code);
        self::assertSame(2, $code, implode("\n", $output));
        self::assertStringContainsString('Unknown command', implode("\n", $output));
    }

    public function testConstraintFailuresAndPostRunVerificationHaveNonzeroExitCodes(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/scripts/maintenance.php');
        $source = preg_replace('/declare\(strict_types=1\);/', '', $source);
        // Execute the actual dispatch/functions against PDO substitutes; no database connection or DDL occurs.
        $shim = <<<'PHP'
namespace MaintenanceHarness;
class Statement extends \PDOStatement {
    public function __construct(private array $rows) {}
    public function fetchAll(int $mode = \PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
    public function fetch(int $mode = \PDO::FETCH_DEFAULT, int $cursorOrientation = \PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed { return $this->rows[0] ?? false; }
}
class PDO extends \PDO {
    public function __construct() {}
    public function exec(string $statement): int|false {
        if (str_starts_with($statement, 'ALTER TABLE')) throw new \RuntimeException('Injected DDL failure');
        return 0;
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$args): \PDOStatement|false {
        if (str_contains($query, 'TABLE_CONSTRAINTS')) return new Statement([]);
        if (str_contains($query, 'SELECT COUNT(*)')) return new Statement([['c' => 0]]);
        return new Statement([]);
    }
}
PHP;
        $source = preg_replace('/require dirname\(__DIR__\).*?\$pdo->setAttribute\(.*?;/s', '$pdo = new PDO();', $source, 1, $replaced);
        self::assertSame(1, $replaced);
        $file = tempnam(sys_get_temp_dir(), 'pkl_maintenance');
        file_put_contents($file, '<?php ' . $shim . substr($source, 5));
        try {
            foreach (['add-constraints', 'all'] as $command) {
                $output = [];
                exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' ' . $command . ' 2>&1', $output, $code);
                self::assertStringContainsString('Injected DDL failure', implode("\n", $output));
                self::assertSame(1, $code, implode("\n", $output));
                if ($command === 'all') self::assertStringContainsString('Post-run verification', implode("\n", $output));
            }
        } finally {
            unlink($file);
        }
    }
}
