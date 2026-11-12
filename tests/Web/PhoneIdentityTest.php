<?php
declare(strict_types=1);

namespace Picklers\Tests\Web;

use Picklers\Tests\Admin\AdminWebTestCase;
use Picklers\Repositories\UserRepository;

final class PhoneIdentityTest extends AdminWebTestCase
{
    public function testEveryRepositoryWriterNormalizesAndReservesPhones(): void
    {
        $repository = static::getContainer()->get(UserRepository::class);
        $admin = static::getContainer()->get(\Picklers\Admin\Repository\AccountRepository::class);
        $repository->updateUser('usr_fx_p1', ['phone' => '09178887788']);
        self::assertSame('+63 917 888 7788', $repository->getUserById('usr_fx_p1')['phone']);
        $repository->updateUser('usr_fx_p1', ['phone' => '+639178887788']);
        $repository->updateUser('usr_fx_p1', ['role' => 'deactivated']);
        foreach (['create', 'update', 'admin'] as $writer) {
            try {
                match ($writer) {
                    'create' => $repository->createUser(['email' => 'phone-writer@fixture.test', 'phone' => '(0917) 888-7788']),
                    'update' => $repository->updateUser('usr_fx_p2', ['name' => 'Must not persist', 'phone' => '+639178887788']),
                    'admin' => $admin->update('usr_fx_p2', ['name' => 'Must not persist', 'phone' => '09178887788']),
                };
                self::fail('Duplicate phone must fail for ' . $writer);
            } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $e) {
                self::assertTrue(UserRepository::isPhoneConflict($e));
            } catch (\Picklers\Admin\Http\AdminActionException $e) {
                self::assertSame('This mobile number is already linked to another account.', $e->getMessage());
            }
        }
        self::assertNotSame('Must not persist', $repository->getUserById('usr_fx_p2')['name']);
        self::assertSame(0, (int)$this->scalar("SELECT COUNT(*) FROM users WHERE email = 'phone-writer@fixture.test'"));
        $repository->updateUser('usr_fx_p2', ['phone' => ' ']);
        $admin->update('usr_fx_p3', ['phone' => '']);
        self::assertNull($repository->getUserById('usr_fx_p2')['phone']);
        self::assertNull($repository->getUserById('usr_fx_p3')['phone']);
        $admin->update('usr_fx_p3', ['phone' => '09178887789']);
        self::assertSame('+63 917 888 7789', $repository->getUserById('usr_fx_p3')['phone']);
        try {
            $repository->updateUser('usr_fx_p2', ['email' => 'p3@fixture.test']);
            self::fail('Duplicate email must fail');
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $e) {
            self::assertFalse(UserRepository::isPhoneConflict($e));
        }
    }

    public function testExistingPhoneFormatsAndOptionalValues(): void
    {
        foreach (['09171234567', '+639171234567', '+63 917 123 4567', '(0917) 123-4567'] as $phone) {
            self::assertSame('+63 917 123 4567', UserRepository::normalizePhone($phone));
        }
        foreach ([null, '', '   '] as $phone) {
            self::assertNull(UserRepository::normalizePhone($phone));
        }
        self::assertSame('+1 202 555 0100', UserRepository::normalizePhone(' +1 202 555 0100 '));
        self::assertFalse(UserRepository::isPhoneConflict(new \RuntimeException('uniq_users_phone')));
    }
}
