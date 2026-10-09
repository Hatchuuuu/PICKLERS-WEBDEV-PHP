<?php
declare(strict_types=1);

namespace Picklers\Tests\Admin;

/** R05 — users & roles: privilege boundaries, last-admin protection, lifecycle integrity. */
final class UsersTest extends AdminWebTestCase
{
    public function testOrdinaryAdminCannotEscalate(): void
    {
        $this->signIn('usr_fx_admin');
        self::assertSame(403, $this->action('admin_update_role', ['user_id' => 'usr_fx_p1', 'role' => 'admin'])['status']);
        self::assertSame(403, $this->action('admin_update_role', ['user_id' => 'usr_fx_root', 'role' => 'player'])['status'], 'cannot demote an admin');
        self::assertSame(403, $this->action('admin_set_privilege', ['user_id' => 'usr_fx_admin', 'grant' => '1', 'reason' => 'Promote myself'])['status']);
        self::assertSame(403, $this->action('admin_reset_password', ['user_id' => 'usr_fx_root', 'new_password' => 'Takeover123', 'reason' => 'x'])['status']);
        self::assertSame(403, $this->action('admin_deactivate_user', ['user_id' => 'usr_fx_root', 'reason' => 'Remove the boss'])['status']);
        self::assertSame('0', (string)$this->scalar("SELECT is_admin FROM users WHERE id = 'usr_fx_p1'"));
        self::assertNotNull($this->lastAudit('user.role'), 'denials are audited');
        self::assertSame('denied', $this->scalar("SELECT outcome FROM audit_events WHERE action = 'user.role' ORDER BY id DESC LIMIT 1"));
    }

    public function testSelfChangesAreRefused(): void
    {
        $this->signIn('usr_fx_root');
        self::assertSame(403, $this->action('admin_update_role', ['user_id' => 'usr_fx_root', 'role' => 'player'])['status']);
        self::assertSame(403, $this->action('admin_deactivate_user', ['user_id' => 'usr_fx_root', 'reason' => 'Leaving the team'])['status']);
    }

    public function testPrivilegedAdminManagesRolesAndLastAdminIsProtected(): void
    {
        $this->signIn('usr_fx_root');
        $r = $this->action('admin_update_role', ['user_id' => 'usr_fx_p1', 'role' => 'admin']);
        self::assertSame(200, $r['status'], json_encode($r['json']));
        self::assertSame('1', (string)$this->scalar("SELECT is_admin FROM users WHERE id = 'usr_fx_p1'"));
        self::assertSame(200, $this->action('admin_update_role', ['user_id' => 'usr_fx_p1', 'role' => 'player'])['status']);

        // Only usr_fx_root + usr_fx_admin are admins (plus seeded dev admin, if any): demote all others first.
        self::pdo()->exec("UPDATE users SET is_admin = 0 WHERE id NOT IN ('usr_fx_root','usr_fx_admin')");
        self::assertSame(200, $this->action('admin_update_role', ['user_id' => 'usr_fx_admin', 'role' => 'player'])['status']);
        // Now root is the last admin; a second privileged admin would be needed to remove it, and self-change is refused anyway.
        self::assertSame(1, (int)$this->scalar("SELECT COUNT(*) FROM users WHERE is_admin = 1 AND role <> 'deleted'"));
    }

    public function testLastPrivilegedAdminCannotBeRevoked(): void
    {
        $this->signIn('usr_fx_root');
        self::assertSame(200, $this->action('admin_set_privilege', ['user_id' => 'usr_fx_admin', 'grant' => '1', 'reason' => 'Second privileged admin'])['status']);
        $this->signIn('usr_fx_admin');
        self::assertSame(200, $this->action('admin_set_privilege', ['user_id' => 'usr_fx_root', 'grant' => '0', 'reason' => 'Rotate duties'])['status']);
        $this->signIn('usr_fx_admin');
        // usr_fx_admin is now the only privileged admin and cannot revoke itself.
        self::assertSame(403, $this->action('admin_set_privilege', ['user_id' => 'usr_fx_admin', 'grant' => '0', 'reason' => 'Rotate again'])['status']);
        self::assertSame(1, (int)$this->scalar('SELECT COUNT(*) FROM admin_privileges'));
    }

    public function testDeactivationKeepsHistoryAndEndsSessions(): void
    {
        $this->signIn('usr_fx_admin');
        self::assertSame(409, $this->action('admin_deactivate_user', ['user_id' => 'usr_fx_owner1', 'reason' => 'Owner left the platform'])['status'], 'active listing must be suspended first');
        $bookings = (int)$this->scalar("SELECT COUNT(*) FROM bookings WHERE user_id = 'usr_fx_p1'");
        $r = $this->action('admin_deactivate_user', ['user_id' => 'usr_fx_p1', 'reason' => 'Chargeback fraud investigation']);
        self::assertSame(200, $r['status'], json_encode($r['json']));
        self::assertSame('deleted', $this->scalar("SELECT role FROM users WHERE id = 'usr_fx_p1'"));
        self::assertSame('p1@fixture.test', $this->scalar("SELECT email FROM users WHERE id = 'usr_fx_p1'"), 'email kept for reactivation');
        self::assertSame($bookings, (int)$this->scalar("SELECT COUNT(*) FROM bookings WHERE user_id = 'usr_fx_p1'"));
        self::assertGreaterThan(0, $r['json']['upcoming_bookings']);

        // The deactivated person's own session is refused on the player side.
        $_SESSION = ['_sf2_attributes' => ['picklers_user_id' => 'usr_fx_p1', 'picklers_last_activity' => time()]];
        self::assertNull($this->webUser());

        $this->signIn('usr_fx_admin');
        self::assertSame(200, $this->action('admin_reactivate_user', ['user_id' => 'usr_fx_p1'])['status']);
        self::assertSame('player', $this->scalar("SELECT role FROM users WHERE id = 'usr_fx_p1'"));
    }

    public function testPermanentDeletionOnlyWithoutHistory(): void
    {
        $this->signIn('usr_fx_admin');
        self::assertSame(403, $this->action('admin_hard_delete_user', ['user_id' => 'usr_fx_p4', 'confirm_text' => 'DELETE', 'reason' => 'Spam account'])['status'], 'privileged only');
        $this->signIn('usr_fx_root');
        self::assertSame(422, $this->action('admin_hard_delete_user', ['user_id' => 'usr_fx_p4', 'confirm_text' => 'delete it', 'reason' => 'Spam account'])['status']);
        $withHistory = $this->action('admin_hard_delete_user', ['user_id' => 'usr_fx_p1', 'confirm_text' => 'DELETE', 'reason' => 'Spam account']);
        self::assertSame(409, $withHistory['status']);
        self::assertStringContainsString('deactivate it instead', $withHistory['json']['message']);
        self::pdo()->exec("DELETE FROM owner_applications WHERE user_id = 'usr_fx_p4'");
        $ok = $this->action('admin_delete_account', ['user_id' => 'usr_fx_p4', 'confirm_text' => 'DELETE', 'reason' => 'Duplicate signup']);
        self::assertSame(200, $ok['status'], json_encode($ok['json']));
        self::assertFalse($this->scalar("SELECT id FROM users WHERE id = 'usr_fx_p4'"));
    }

    public function testPasswordResetPolicyAndSessionInvalidation(): void
    {
        $this->signIn('usr_fx_admin');
        self::assertSame(422, $this->action('admin_reset_password', ['user_id' => 'usr_fx_p2', 'new_password' => 'short'])['status']);
        self::assertSame(422, $this->action('admin_reset_password', ['user_id' => 'usr_fx_p2', 'new_password' => 'nonumbershere'])['status']);
        $r = $this->action('admin_reset_password', ['user_id' => 'usr_fx_p2', 'new_password' => 'NewPass2026', 'reason' => 'Locked out, verified by phone']);
        self::assertSame(200, $r['status']);
        self::assertTrue(password_verify('NewPass2026', (string)$this->scalar("SELECT password_hash FROM users WHERE id = 'usr_fx_p2'")));
        self::assertNotFalse($this->scalar("SELECT password_changed_at FROM users WHERE id = 'usr_fx_p2' AND password_changed_at IS NOT NULL"));
        self::assertStringNotContainsString('NewPass2026', (string)$this->lastAudit('user.password_reset')['changes'], 'never in the audit trail');

        // A session that signed in before the reset is signed out.
        $_SESSION = ['_sf2_attributes' => ['picklers_user_id' => 'usr_fx_p2', 'picklers_last_activity' => time(), 'picklers_auth_at' => time() - 3600]];
        self::assertNull($this->webUser());
    }

    public function testVerificationAndDetail(): void
    {
        $this->signIn('usr_fx_admin');
        self::assertSame(200, $this->action('admin_set_verification', ['user_id' => 'usr_fx_p2', 'status' => 'verified'])['status']);
        self::assertSame('verified', $this->scalar("SELECT verification_status FROM users WHERE id = 'usr_fx_p2'"));
        $r = $this->action('admin_get_user', ['user_id' => 'usr_fx_root'], null, '/admin/api', 'GET');
        self::assertSame(['Privileged admin'], $r['json']['user']['role_labels'], 'truthful role display');
        self::assertArrayNotHasKey('password_hash', $r['json']['user']);
        $this->client->request('GET', '/admin/panel/users', ['role' => 'privileged']);
        self::assertSelectorTextContains('.pk-pager__count', 'of 1');
    }
}
