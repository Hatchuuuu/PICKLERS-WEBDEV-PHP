<?php
declare(strict_types=1);

namespace Picklers\Tests\Web;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Exception\AbortMigration;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Events;
use Doctrine\Migrations\EventDispatcher;
use Doctrine\Migrations\Metadata\MigrationPlan;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\Direction;
use Doctrine\Migrations\Version\Version;
use PHPUnit\Framework\TestCase;
use Picklers\Migrations\Version20261009000001;
use Picklers\Persistence\TournamentMigrationListener;
use Psr\Log\NullLogger;

final class TournamentMigrationRetryTest extends TestCase
{
    private string $path;
    private string|false $original;

    protected function setUp(): void
    {
        if (!defined('DATA_PATH')) {
            define('DATA_PATH', sys_get_temp_dir() . '/picklers-tournament-' . bin2hex(random_bytes(8)));
            mkdir(DATA_PATH);
        }
        $this->path = DATA_PATH . '/tournaments.json';
        $this->original = is_file($this->path) ? file_get_contents($this->path) : false;
    }

    protected function tearDown(): void
    {
        if ($this->original === false) {
            @unlink($this->path);
        } else {
            file_put_contents($this->path, $this->original);
        }
    }

    private function migration(Connection $db): Version20261009000001
    {
        $db->method('createSchemaManager')->willReturn($this->createStub(AbstractSchemaManager::class));
        $db->method('getDatabasePlatform')->willReturn(new MariaDBPlatform());
        return new Version20261009000001($db, new NullLogger());
    }

    private function importedSchema(): Schema
    {
        $schema = new Schema();
        $schema->createTable('tournaments')->addColumn('data', 'text');
        return $schema;
    }

    public function testRetryImportsMissingRecordsWithoutOverwritingExistingRecords(): void
    {
        $source = '[{"id":"existing","name":"Old name"},{"id":"missing","facility_id":"2"}]';
        file_put_contents($this->path, $source);
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('fetchFirstColumn')->with('SELECT id FROM tournaments')->willReturn(['existing']);
        $migration = $this->migration($db);
        $migration->up($this->importedSchema());
        $queries = $migration->getSql();
        self::assertCount(1, $queries);
        self::assertStringStartsWith('INSERT INTO tournaments', $queries[0]->getStatement());
        self::assertSame(['missing', '2', '{"id":"missing","facility_id":"2"}'], $queries[0]->getParameters());
        self::assertSame($source, file_get_contents($this->path));
    }

    public function testCompletedImportDoesNotChangeExistingRows(): void
    {
        file_put_contents($this->path, '[{"id":"existing","name":"Old name"}]');
        $db = $this->createMock(Connection::class);
        $db->method('fetchFirstColumn')->willReturn(['existing']);
        $migration = $this->migration($db);
        $migration->up($this->importedSchema());
        self::assertSame([], $migration->getSql());
    }

    public function testFreshImportCreatesTableBeforeImportingRecordsInFileOrder(): void
    {
        file_put_contents($this->path, '[{"id":"first"},{"id":"second"},{"id":"first"}]');
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('fetchFirstColumn');
        $migration = $this->migration($db);
        $migration->up(new Schema());
        $queries = $migration->getSql();
        self::assertCount(3, $queries);
        self::assertStringStartsWith('CREATE TABLE tournaments', $queries[0]->getStatement());
        self::assertSame('first', $queries[1]->getParameters()[0]);
        self::assertSame('second', $queries[2]->getParameters()[0]);
    }

    public function testPopulatedLegacyTablesAreKept(): void
    {
        file_put_contents($this->path, '[]');
        $db = $this->createMock(Connection::class);
        $db->method('fetchOne')->willReturn(1);
        $migration = $this->migration($db);
        $schema = new Schema();
        $schema->createTable('tournaments');
        try {
            $migration->up($schema);
            self::fail('Populated legacy tables must never be dropped.');
        } catch (AbortMigration $error) {
            self::assertSame([], $migration->getSql());
        }
    }

    public function testValidRollbackExportsAllRowsBeforeQueuingDrop(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchFirstColumn')->willReturn(['{"id":"first"}', '{"id":"second"}']);
        $migration = $this->migration($db);
        $migration->down($this->importedSchema());
        self::assertSame([['id' => 'first'], ['id' => 'second']], json_decode(file_get_contents($this->path), true));
        self::assertSame('DROP TABLE tournaments', $migration->getSql()[0]->getStatement());
    }

    public function testMigrationEventsKeepDryRunFileUnchangedAndPermitRealExport(): void
    {
        foreach ([true, false] as $dryRun) {
            file_put_contents($this->path, '[{"id":"original"}]');
            $db = $this->createMock(Connection::class);
            $db->method('fetchFirstColumn')->willReturn(['{"id":"current"}']);
            $migration = $this->migration($db);
            $factory = DependencyFactory::fromConnection(new ConfigurationArray([]), new ExistingConnection($db));
            $factory->setDefinition(EventDispatcher::class, new TournamentMigrationListener());
            $dispatcher = $factory->getEventDispatcher();
            $configuration = new MigratorConfiguration();
            $configuration->setDryRun($dryRun);
            $plan = new MigrationPlan(new Version(Version20261009000001::class), $migration, Direction::DOWN);
            $dispatcher->dispatchVersionEvent(Events::onMigrationsVersionExecuting, $plan, $configuration);
            $migration->down($this->importedSchema());
            self::assertSame([['id' => $dryRun ? 'original' : 'current']], json_decode(file_get_contents($this->path), true));
            self::assertSame('DROP TABLE tournaments', $migration->getSql()[0]->getStatement());
        }
    }

    public function testInvalidSourceAbortsBeforeAnyDdlIsQueued(): void
    {
        file_put_contents($this->path, '{invalid');
        $migration = $this->migration($this->createMock(Connection::class));
        try {
            $migration->up($this->importedSchema());
            self::fail('Invalid source JSON must abort a retry.');
        } catch (AbortMigration $error) {
            self::assertStringContainsString('valid JSON', $error->getMessage());
            self::assertSame([], $migration->getSql());
        }
    }

    public function testCorruptDatabaseRowAbortsRollbackWithoutReplacingFileOrDroppingTable(): void
    {
        file_put_contents($this->path, '[{"id":"backup"}]');
        $db = $this->createMock(Connection::class);
        $db->method('fetchFirstColumn')->willReturn(['{"id":"valid"}', '{corrupt']);
        $migration = $this->migration($db);
        try {
            $migration->down($this->importedSchema());
            self::fail('A corrupt database record must abort rollback.');
        } catch (AbortMigration $error) {
            self::assertSame([], $migration->getSql());
            self::assertSame('[{"id":"backup"}]', file_get_contents($this->path));
        }
    }
}
