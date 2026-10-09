<?php
declare(strict_types=1);

namespace Picklers\Tests;

use Picklers\Core\Database;

final class DatabaseConnectionTest extends TestCase
{
    public function run(): void
    {
        $connection = Database::get()->pdo();
        $this->assertSame((int)($_ENV['DB_PORT'] ?? 3306), (int)$connection->query('SELECT @@port')->fetchColumn(), 'Standalone persistence connects to the configured server port');
        $this->assertSame('picklers_test', $connection->query('SELECT DATABASE()')->fetchColumn(), 'Standalone persistence connects to the isolated test database');
    }
}
