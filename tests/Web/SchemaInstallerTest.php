<?php
declare(strict_types=1);

namespace Picklers\Tests\Web;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\TestCase;
use Picklers\Persistence\SchemaInstallCommand;
use Picklers\Persistence\SchemaInstaller;
use Picklers\Repositories\PromoRepository;
use Symfony\Component\Console\Tester\CommandTester;

final class SchemaInstallerTest extends TestCase
{
    public function testFailedAlterDoesNotStampSchemaAndCanBeRetried(): void
    {
        $directory = sys_get_temp_dir() . '/picklers-schema-' . bin2hex(random_bytes(8));
        mkdir($directory);
        $environment = $_ENV['APP_ENV'] ?? null;
        $_ENV['APP_ENV'] = 'production';
        $result = $this->createMock(Result::class);
        $result->method('fetchFirstColumn')->willReturn([]);
        $result->method('fetchAssociative')->willReturn(['c' => 0]);
        $result->method('fetchAllAssociative')->willReturn([]);
        $db = $this->createMock(Connection::class);
        $db->method('executeQuery')->willReturn($result);
        $attempts = 0;
        $db->method('executeStatement')->willReturnCallback(static function (string $sql) use (&$attempts): int {
            if (str_starts_with($sql, 'ALTER TABLE matches')) {
                ++$attempts;
                throw new \RuntimeException('ALTER permission denied');
            }
            return 0;
        });
        $schema = new SchemaInstaller($db, new PromoRepository($db), $directory);

        try {
            for ($attempt = 0; $attempt < 2; ++$attempt) {
                try {
                    $schema->ensure();
                    self::fail('A failed ALTER must fail installation.');
                } catch (\RuntimeException $error) {
                    self::assertSame('ALTER permission denied', $error->getMessage());
                }
                self::assertFileDoesNotExist($directory . '/.schema_version');
            }
            self::assertSame(2, $attempts);
        } finally {
            if ($environment === null) {
                unset($_ENV['APP_ENV']);
            } else {
                $_ENV['APP_ENV'] = $environment;
            }
            foreach (glob($directory . '/.*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($directory);
        }
    }

    public function testDemoSeedsAreOnlyAllowedInExplicitDevelopmentAndTestEnvironments(): void
    {
        $environment = $_ENV['APP_ENV'] ?? null;
        try {
            unset($_ENV['APP_ENV']);
            self::assertTrue(SchemaInstaller::isProductionEnv());
            foreach (['production', 'prod', 'live', 'staging', 'produciton', ''] as $value) {
                $_ENV['APP_ENV'] = $value;
                self::assertTrue(SchemaInstaller::isProductionEnv(), $value);
            }
            foreach (['dev', 'development', 'local', 'test', 'DEV'] as $value) {
                $_ENV['APP_ENV'] = $value;
                self::assertFalse(SchemaInstaller::isProductionEnv(), $value);
            }
        } finally {
            if ($environment === null) {
                unset($_ENV['APP_ENV']);
            } else {
                $_ENV['APP_ENV'] = $environment;
            }
        }
    }

    public function testFailedInstallationCanBeRetriedOnTheSameInstance(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('executeQuery')->willThrowException(new \RuntimeException('Database unavailable'));
        $db->expects(self::exactly(2))->method('executeStatement')
            ->willThrowException(new \RuntimeException('Schema installation failed'));
        $schema = new SchemaInstaller($db, new PromoRepository($db), sys_get_temp_dir());

        for ($attempt = 0; $attempt < 2; ++$attempt) {
            try {
                $schema->ensure();
                self::fail('A failed schema installation must propagate its error.');
            } catch (\RuntimeException $error) {
                self::assertSame('Schema installation failed', $error->getMessage());
            }
        }
    }

    public function testInstallCommandRejectsMissingDatabaseNameBeforeConnecting(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('getParams')->willReturn([]);
        $db->expects(self::never())->method('executeStatement');
        $schema = new SchemaInstaller($db, new PromoRepository($db), sys_get_temp_dir());
        $command = new CommandTester(new SchemaInstallCommand($db, $schema));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must configure a database name');
        $command->execute([]);
    }
}
