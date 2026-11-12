<?php
declare(strict_types=1);

namespace Picklers\Tests\Web;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Picklers\Migrations\Version20261005000002;
use Psr\Log\NullLogger;

final class AuditMigrationRetryTest extends TestCase
{
    public static function triggerStates(): iterable
    {
        yield 'table retained before either trigger was created' => [true, []];
        yield 'update trigger retained after partial failure' => [true, ['audit_events_no_update']];
        yield 'delete trigger already exists' => [true, ['audit_events_no_delete']];
        yield 'both protections retained' => [true, ['audit_events_no_update', 'audit_events_no_delete']];
        yield 'fresh installation' => [false, []];
    }

    #[DataProvider('triggerStates')]
    public function testOnlyMissingProtectionsAreCreated(bool $tableExists, array $existingTriggers): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('createSchemaManager')->willReturn($this->createStub(AbstractSchemaManager::class));
        $db->method('getDatabasePlatform')->willReturn(new MariaDBPlatform());
        $db->expects(self::exactly(2))->method('fetchOne')->willReturnCallback(
            static function (string $sql, array $params) use ($existingTriggers): int {
                self::assertStringContainsString('TRIGGER_SCHEMA = DATABASE()', $sql);
                self::assertStringContainsString('EVENT_OBJECT_TABLE', $sql);
                self::assertContains($params[0], ['audit_events_no_update', 'audit_events_no_delete']);
                return (int)in_array($params[0], $existingTriggers, true);
            }
        );
        $schema = new Schema();
        if ($tableExists) {
            $schema->createTable('audit_events');
        }
        $schema->createTable('admin_privileges');
        $migration = new Version20261005000002($db, new NullLogger());
        $migration->up($schema);
        $sql = implode("\n", array_map(static fn($query) => $query->getStatement(), $migration->getSql()));

        foreach (['audit_events_no_update' => 'UPDATE', 'audit_events_no_delete' => 'DELETE'] as $name => $operation) {
            if (in_array($name, $existingTriggers, true)) {
                self::assertStringNotContainsString('CREATE TRIGGER ' . $name, $sql);
            } else {
                self::assertStringContainsString("CREATE TRIGGER {$name} BEFORE {$operation} ON audit_events", $sql);
            }
        }
        self::assertStringNotContainsString('DROP TRIGGER', $sql);
    }
}
