<?php
declare(strict_types=1);

namespace Picklers\Tests\Web;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Exception\AbortMigration;
use PHPUnit\Framework\TestCase;
use Picklers\Migrations\Version20261012000001;
use Psr\Log\NullLogger;

final class PhoneMigrationTest extends TestCase
{
    private function migration(array $rows): Version20261012000001
    {
        $db = $this->createMock(Connection::class);
        $db->method('createSchemaManager')->willReturn($this->createStub(AbstractSchemaManager::class));
        $db->method('getDatabasePlatform')->willReturn(new MariaDBPlatform());
        $db->method('fetchAllAssociative')->willReturn($rows);
        $db->expects(self::never())->method('executeStatement');
        return new Version20261012000001($db, new NullLogger());
    }

    private function schema(bool $indexed = false): Schema
    {
        $schema = new Schema();
        $users = $schema->createTable('users');
        $users->addColumn('phone', 'string', ['notnull' => false]);
        if ($indexed) {
            $users->addUniqueIndex(['phone'], 'uniq_users_phone');
        }
        return $schema;
    }

    public function testUnsafeContactsAbortBeforeAnyMutation(): void
    {
        foreach ([
            [['id' => 'a', 'phone' => '+63 917 123 4567'], ['id' => 'deleted', 'phone' => '09171234567']],
            [['id' => 'a', 'phone' => '09171234567']],
            [['id' => 'blank', 'phone' => " \t\n"], ['id' => 'a', 'phone' => '09171234567']],
            [['id' => 'a', 'phone' => '+63 917 123 4567'], ['id' => 'b', 'phone' => '+63 917 123 4567']],
        ] as $rows) {
            $migration = $this->migration($rows);
            try {
                $migration->up($this->schema());
                self::fail('Unsafe contacts must abort');
            } catch (AbortMigration $e) {
                self::assertStringContainsString('nothing changed', $e->getMessage());
                self::assertSame([], $migration->getSql());
            }
        }
    }

    public function testSafeRetryAndRollbackOnlyChangeIndex(): void
    {
        $rows = [['id' => 'a', 'phone' => null], ['id' => 'b', 'phone' => null], ['id' => 'c', 'phone' => '+63 917 123 4567']];
        $migration = $this->migration($rows);
        $migration->up($this->schema());
        self::assertSame('ALTER TABLE users ADD UNIQUE INDEX uniq_users_phone (phone)', $migration->getSql()[0]->getStatement());
        $retry = $this->migration($rows);
        $retry->up($this->schema(true));
        self::assertSame([], $retry->getSql());
        $retry->down($this->schema(true));
        self::assertSame('ALTER TABLE users DROP INDEX uniq_users_phone', $retry->getSql()[0]->getStatement());
        self::assertFalse($retry->isTransactional());
    }

    public function testAbsentHistoricPhonesAreNormalizedAfterSafePreflight(): void
    {
        $migration = $this->migration([
            ['id' => 'a', 'phone' => ''], ['id' => 'b', 'phone' => '   '],
            ['id' => 'tab', 'phone' => "\t"], ['id' => 'newline', 'phone' => "\n"],
            ['id' => 'c', 'phone' => null], ['id' => 'd', 'phone' => '+63 917 123 4567'],
        ]);
        $migration->up($this->schema());
        self::assertSame([
            'UPDATE users SET phone = NULL WHERE id = ? AND BINARY phone = ?',
            'UPDATE users SET phone = NULL WHERE id = ? AND BINARY phone = ?',
            'UPDATE users SET phone = NULL WHERE id = ? AND BINARY phone = ?',
            'UPDATE users SET phone = NULL WHERE id = ? AND BINARY phone = ?',
            'ALTER TABLE users ADD UNIQUE INDEX uniq_users_phone (phone)',
        ], array_map(static fn ($sql) => $sql->getStatement(), $migration->getSql()));
        self::assertSame([['a', ''], ['b', '   '], ['tab', "\t"], ['newline', "\n"], []],
            array_map(static fn ($sql) => $sql->getParameters(), $migration->getSql()));
    }
}
