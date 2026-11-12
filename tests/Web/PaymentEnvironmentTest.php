<?php
declare(strict_types=1);

namespace Picklers\Tests\Web;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\TestCase;
use Picklers\Persistence\SchemaInstaller;
use Picklers\Repositories\PromoRepository;
use Picklers\Repositories\UserRepository;
use Picklers\Repositories\WalletRepository;
use Picklers\Web\Api\PlayerActions;
use Picklers\Web\Http\LegacyInput;
use Symfony\Component\HttpFoundation\Request;

final class PaymentEnvironmentTest extends TestCase
{
    public function testSignupBonusFailsClosedAndRetainsDevelopmentCap(): void
    {
        $original = $_ENV;
        try {
            $_ENV['SIGNUP_BONUS_CREDITS'] = '900';
            foreach ([null, 'production', 'prod', 'live', 'staging', 'produciton', '', 'dev', 'development', 'local', 'test'] as $environment) {
                if ($environment === null) {
                    unset($_ENV['APP_ENV']);
                } else {
                    $_ENV['APP_ENV'] = $environment;
                }
                $expected = in_array($environment, ['dev', 'development', 'local', 'test'], true) ? 500.0 : 0.0;
                $db = $this->createMock(Connection::class);
                $result = $this->createMock(Result::class);
                $result->method('fetchAssociative')->willReturn(['id' => 'signup']);
                $db->method('executeQuery')->willReturn($result);
                $db->expects(self::once())->method('executeStatement')->willReturnCallback(
                    static function (string $sql, array $parameters) use ($expected, $environment): int {
                        self::assertSame($expected, $parameters[12], (string)$environment);
                        return 1;
                    }
                );
                $schema = new SchemaInstaller($db, new PromoRepository($db), sys_get_temp_dir());
                (new UserRepository($db, $schema))->createUser(['id' => 'signup']);
            }
        } finally {
            $_ENV = $original;
        }
    }

    public function testTopUpSimulationRequiresExplicitDevelopmentEnvironmentAndOptIn(): void
    {
        foreach (['prod', 'production', 'live', 'staging', '', 'typo', 'dev', 'test'] as $environment) {
            foreach (['true', 'false'] as $simulated) {
                $reflection = new \ReflectionClass(PlayerActions::class);
                $actions = $reflection->newInstanceWithoutConstructor();
                $reflection->getProperty('environment')->setValue($actions, $environment);
                $reflection->getProperty('paymentsSimulated')->setValue($actions, $simulated);
                $reflection->getProperty('wallet')->setValue($actions, (new \ReflectionClass(WalletRepository::class))->newInstanceWithoutConstructor());
                // Zero reaches wallet validation only when simulation is allowed, without needing a database.
                $response = $actions->topUp(new LegacyInput(Request::create('/api', 'POST', ['amount' => 0])), ['id' => 'player']);
                $allowed = in_array($environment, ['dev', 'test'], true) && $simulated === 'true';
                self::assertSame($allowed ? 200 : 503, $response->getStatusCode(), $environment . '/' . $simulated);
                if ($allowed) {
                    self::assertStringContainsString('Top-up amount must be between', $response->getContent());
                }
            }
        }
    }
}
