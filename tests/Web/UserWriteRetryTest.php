<?php
declare(strict_types=1);

namespace Picklers\Tests\Web;

use Doctrine\DBAL\Exception\RetryableException;
use PHPUnit\Framework\Attributes\DataProvider;
use Picklers\Repositories\UserRepository;
use Picklers\Tests\Admin\AdminWebTestCase;

final class UserWriteRetryTest extends AdminWebTestCase
{
    public static function writes(): iterable
    {
        yield 'registration' => ['create'];
        yield 'profile' => ['update'];
    }

    #[DataProvider('writes')]
    public function testAutocommitWriteRetriesOneDeadlock(string $mode): void
    {
        $this->withDeadlock($mode, 1, function () use ($mode): void {
            try {
                $this->write($mode);
            } catch (RetryableException) {
                self::fail('A rolled-back autocommit user write must retry once.');
            }
            self::assertSame(2, (int)$this->db()->fetchOne('SELECT @user_write_attempts'));
            self::assertSame(1, (int)$this->scalar("SELECT COUNT(*) FROM users WHERE email = 'retry-write@fixture.test'"));
        });
    }

    #[DataProvider('writes')]
    public function testRetryIsBoundedAndDoesNotRestartACallersTransaction(string $mode): void
    {
        foreach ([false, true] as $transaction) {
            $this->withDeadlock($mode, 99, function () use ($mode, $transaction): void {
                if ($transaction) {
                    $this->db()->beginTransaction();
                }
                try {
                    $this->write($mode);
                    self::fail('Unresolved deadlocks must propagate.');
                } catch (RetryableException) {
                    self::assertSame($transaction ? 1 : 2, (int)$this->db()->fetchOne('SELECT @user_write_attempts'));
                    self::assertSame(0, (int)$this->scalar("SELECT COUNT(*) FROM users WHERE email = 'retry-write@fixture.test'"));
                } finally {
                    if ($this->db()->isTransactionActive()) {
                        $this->db()->rollBack();
                    }
                }
            });
        }
    }

    private function withDeadlock(string $mode, int $failures, callable $check): void
    {
        $this->db()->executeStatement('SET @user_write_attempts = 0');
        $operation = $mode === 'create' ? 'INSERT' : 'UPDATE';
        $this->db()->executeStatement("CREATE TRIGGER test_user_write_deadlock BEFORE $operation ON users FOR EACH ROW BEGIN IF NEW.email = 'retry-write@fixture.test' THEN SET @user_write_attempts = @user_write_attempts + 1; IF @user_write_attempts <= $failures THEN SIGNAL SQLSTATE '40001' SET MYSQL_ERRNO = 1213, MESSAGE_TEXT = 'Injected user-write deadlock'; END IF; END IF; END");
        try {
            $check();
        } finally {
            $this->db()->executeStatement('DROP TRIGGER IF EXISTS test_user_write_deadlock');
            $this->db()->executeStatement("DELETE FROM users WHERE email = 'retry-write@fixture.test' AND id <> 'usr_fx_p4'");
        }
    }

    private function write(string $mode): void
    {
        $users = static::getContainer()->get(UserRepository::class);
        $fields = ['email' => 'retry-write@fixture.test', 'phone' => '09178887788'];
        $mode === 'create' ? $users->createUser($fields) : $users->updateUser('usr_fx_p4', $fields);
    }
}
