<?php
declare(strict_types=1);

namespace Picklers\Tests\Web;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\TestCase;
use Picklers\Admin\Repository\AccountRepository;
use Picklers\Admin\Security\ImpersonationManager;
use Picklers\Admin\Security\PlatformSession;
use Picklers\Admin\Service\AuditContext;
use Picklers\Admin\Service\AuditLog;
use Picklers\Repositories\UserRepository;
use Picklers\Repositories\PromoRepository;
use Picklers\Persistence\SchemaInstaller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class PasswordSessionRevocationTest extends TestCase
{
    private array $row;
    private PlatformSession $session;
    private RequestStack $requests;

    protected function setUp(): void
    {
        $this->row = ['id' => 'admin', 'password_hash' => 'old hash', 'password_changed_at' => null];
        $db = $this->createMock(Connection::class);
        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')->willReturnCallback(fn() => $this->row);
        $db->method('executeQuery')->willReturn($result);
        $users = new UserRepository($db, new SchemaInstaller($db, new PromoRepository($db), sys_get_temp_dir()));
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $this->requests = new RequestStack();
        $this->requests->push($request);
        $this->session = new PlatformSession($this->requests, $users);
    }

    public function testPasswordResetRevokesSessionAuthenticatedInTheSameSecond(): void
    {
        $now = time();
        $this->session->signIn('admin', $now);
        $this->row['password_hash'] = 'new hash';
        $this->row['password_changed_at'] = date('Y-m-d H:i:s', $now);
        self::assertTrue($this->session->passwordChanged('admin', $this->row['password_changed_at']));
    }

    public function testFreshSignInAndCurrentPasswordChangerRemainAuthenticated(): void
    {
        $now = time();
        $this->session->signIn('admin', $now);
        $this->row['password_hash'] = 'new hash';
        $this->row['password_changed_at'] = date('Y-m-d H:i:s', $now);
        $this->session->markAuthenticatedNow($now);
        self::assertFalse($this->session->passwordChanged('admin', $this->row['password_changed_at']));
        $this->session->signIn('admin', $now);
        self::assertFalse($this->session->passwordChanged('admin', $this->row['password_changed_at']));
    }

    public function testLegacyUnboundSessionAtPasswordChangeSecondIsRevoked(): void
    {
        $now = time();
        $this->requests->getSession()->set(PlatformSession::USER_KEY, 'admin');
        $this->requests->getSession()->set(PlatformSession::AUTH_AT_KEY, $now);
        self::assertTrue($this->session->passwordChanged('admin', date('Y-m-d H:i:s', $now)));
        self::assertFalse($this->session->passwordChanged('admin', null));
    }

    public function testConcurrentResetCannotBindLoginOrPasswordChangerToAnUnprovedHash(): void
    {
        $now = time();
        $this->row['password_hash'] = 'concurrent reset hash';
        $this->session->signIn('admin', $now, 'hash whose password was verified');
        self::assertTrue($this->session->passwordChanged('admin', null));
        $this->session->markAuthenticatedNow($now, 'hash just written by password changer');
        self::assertTrue($this->session->passwordChanged('admin', null));
    }

    public function testOriginalAdministratorPasswordResetEndsImpersonation(): void
    {
        $now = time();
        $this->session->signIn('admin', $now);
        $this->session->setImpersonation(['actor_id' => 'admin', 'target_id' => 'player', 'started_at' => $now, 'expires_at' => $now + 1800]);
        $this->session->setUserId('player');
        $this->row['password_hash'] = 'new hash';
        $this->row['password_changed_at'] = date('Y-m-d H:i:s', $now);
        $db = $this->createMock(Connection::class);
        $db->method('fetchAssociative')->willReturn($this->row + ['name' => 'Admin', 'role' => 'admin', 'is_admin' => 1, 'is_owner' => 0, 'is_privileged' => 1]);
        $db->expects(self::once())->method('insert')->willReturn(1);
        $context = new AuditContext();
        $manager = new ImpersonationManager($this->session, new AccountRepository($db), new AuditLog($db, $context), $context, $this->requests);
        self::assertNull($manager->resolve($now));
        self::assertNull($this->session->userId());
        self::assertNull($this->session->impersonation());
    }
}
