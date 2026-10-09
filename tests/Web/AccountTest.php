<?php
declare(strict_types=1);

namespace Picklers\Tests\Web;

use Picklers\Tests\Admin\AdminWebTestCase;

/** Section 3 — Account & notifications, served by Symfony with the legacy /api contract intact. */
final class AccountTest extends AdminWebTestCase
{
    private const TOKEN = 'web-test-token';

    /** POST a legacy action as the app does (form body + X-CSRF-Token). */
    private function post(string $action, array $fields = [], ?string $csrf = self::TOKEN, string $method = 'POST'): array
    {
        $_SESSION['_sf2_attributes']['_csrf/picklers'] = self::TOKEN;
        $this->client->request($method, '/api.php', ['action' => $action] + $fields, [], array_filter(['HTTP_X_CSRF_TOKEN' => $csrf]));
        $r = $this->client->getResponse();

        return ['status' => $r->getStatusCode(), 'json' => json_decode((string)$r->getContent(), true) ?? []];
    }

    public function testMeForGuestsAndSignedInPlayers(): void
    {
        $this->client->request('GET', '/api', ['action' => 'me']);
        $guest = json_decode((string)$this->client->getResponse()->getContent(), true);
        self::assertTrue($guest['success']);
        self::assertNull($guest['user']);
        self::assertSame([], $guest['notifications']);
        self::assertNotEmpty($guest['csrf_token']);

        $this->signIn('usr_fx_p1');
        $this->client->request('GET', '/api/me');
        $me = json_decode((string)$this->client->getResponse()->getContent(), true);
        self::assertSame('usr_fx_p1', $me['user']['id']);
        self::assertArrayNotHasKey('password_hash', $me['user'], 'secrets never leave the server');
        self::assertNotEmpty($me['csrf_token'], 'a token for the app to send back');
    }

    public function testEveryAccountChangeNeedsPostCsrfAndAnAccount(): void
    {
        $actions = [
            'change_password' => 'Unauthorized: Please log in to change your password.',
            'update_profile' => 'Unauthorized', 'verify_identity' => 'Unauthorized', 'delete_own_account' => 'Unauthorized',
            'mark_notifications_read' => 'Unauthorized', 'delete_notification' => 'Unauthorized',
        ];
        foreach ($actions as $action => $guestMessage) {
            self::assertSame(['success' => false, 'message' => $guestMessage, 'errors' => []], $this->post($action)['json'], "{$action} guest");
            $this->signIn('usr_fx_p2');
            self::assertSame(403, $this->post($action, [], 'wrong')['status'], "{$action} bad CSRF");
            self::assertSame(403, $this->post($action, [], null)['status'], "{$action} missing CSRF");
            // Before, a GET skipped the CSRF check entirely (e.g. ?action=delete_own_account&confirm=DELETE).
            self::assertSame(405, $this->post($action, ['confirm' => 'DELETE'], null, 'GET')['status'], "{$action} GET");
            $_SESSION = [];
        }
        self::assertNotSame('deleted', $this->scalar("SELECT role FROM users WHERE id = 'usr_fx_p2'"));
    }

    public function testChangePasswordKeepsThisSessionAndChecksTheCurrentPassword(): void
    {
        $this->signIn('usr_fx_p5', time() - 60);
        $wrong = $this->post('change_password', ['current_password' => 'not-it', 'new_password' => 'New-Fixture-Pass-2027', 'confirm_password' => 'New-Fixture-Pass-2027']);
        self::assertSame(400, $wrong['status']);
        self::assertSame('Current password is incorrect. Please try again.', $wrong['json']['message']);
        self::assertSame('New passwords do not match. Please re-check.', $this->post('change_password', ['current_password' => 'Fixture-Pass-2026', 'new_password' => 'New-Fixture-Pass-2027', 'confirm_password' => 'Other-2027'])['json']['message']);

        $ok = $this->post('change_password', ['current_password' => 'Fixture-Pass-2026', 'new_password' => 'New-Fixture-Pass-2027', 'confirm_password' => 'New-Fixture-Pass-2027']);
        self::assertSame(['success' => true, 'message' => 'Password updated successfully!'], $ok['json']);
        self::assertTrue(password_verify('New-Fixture-Pass-2027', (string)$this->scalar("SELECT password_hash FROM users WHERE id = 'usr_fx_p5'")));
        $this->client->request('GET', '/api/me');
        self::assertSame('usr_fx_p5', json_decode((string)$this->client->getResponse()->getContent(), true)['user']['id'] ?? null, 'the session that changed it stays signed in');
    }

    public function testProfileValidationAndUpdate(): void
    {
        $this->signIn('usr_fx_p1');
        self::assertSame(400, $this->post('update_profile', ['name' => '<script>'])['status']);
        self::assertSame('An account with this email address already exists', $this->post('update_profile', ['email' => 'p2@fixture.test'])['json']['message']);
        self::assertSame(413, $this->post('update_profile', ['avatar_url' => 'data:image/png;base64,' . str_repeat('A', 200001)])['status']);
        self::assertSame('Unsupported image format.', $this->post('update_profile', ['avatar_url' => 'javascript:alert(1)'])['json']['message']);

        $ok = $this->post('update_profile', ['name' => 'Paula P. Player', 'level' => 'Advanced', 'avatar_url' => 'https://example.test/a.png']);
        self::assertSame(200, $ok['status']);
        self::assertSame('Paula P. Player', $ok['json']['user']['name']);
        self::assertArrayNotHasKey('password_hash', $ok['json']['user']);
        self::assertSame('Advanced', $this->scalar("SELECT level FROM users WHERE id = 'usr_fx_p1'"));
    }

    public function testVerificationIsRequestedNeverSelfGranted(): void
    {
        $this->signIn('usr_fx_p2');
        $first = $this->post('verify_identity');
        self::assertSame('pending_review', $first['json']['status']);
        self::assertSame('pending_review', $this->scalar("SELECT verification_status FROM users WHERE id = 'usr_fx_p2'"));
        self::assertSame(1, (int)$this->scalar("SELECT COUNT(*) FROM notifications WHERE user_id = 'usr_fx_p2' AND title LIKE 'Verification Requested%'"));
        self::assertStringContainsString('already under review', $this->post('verify_identity')['json']['message']);
    }

    public function testSelfDeletionRules(): void
    {
        $this->signIn('usr_fx_owner1');
        self::assertSame(403, $this->post('delete_own_account', ['confirm' => 'DELETE'])['status'], 'owners must contact support');

        $this->signIn('usr_fx_p4');
        self::assertSame('Type DELETE in capitals to confirm.', $this->post('delete_own_account', ['confirm' => 'delete'])['json']['message']);
        $ok = $this->post('delete_own_account', ['confirm' => 'DELETE']);
        self::assertSame(200, $ok['status']);
        self::assertStringEndsWith('auth.php?logout=1', $ok['json']['redirect']);
        self::assertSame('deleted', $this->scalar("SELECT role FROM users WHERE id = 'usr_fx_p4'"));
        self::assertArrayNotHasKey('picklers_user_id', $_SESSION['_sf2_attributes'] ?? [], 'signed out');
    }

    public function testNotificationsReadAndDeleteOnlyYourOwn(): void
    {
        $ins = self::pdo()->prepare("INSERT INTO notifications (id, user_id, title, body, type, is_read) VALUES (?, ?, 'T', 'B', 'system', 0)");
        $ins->execute(['web_n_p1', 'usr_fx_p1']);
        $ins->execute(['web_n_p2', 'usr_fx_p2']);

        $this->signIn('usr_fx_p1');
        self::assertTrue($this->post('mark_notifications_read')['json']['success']);
        self::assertSame(1, (int)$this->scalar("SELECT is_read FROM notifications WHERE id = 'web_n_p1'"));
        self::assertSame(0, (int)$this->scalar("SELECT is_read FROM notifications WHERE id = 'web_n_p2'"), 'other players untouched');

        $this->post('delete_notification', ['id' => 'web_n_p2']);
        self::assertSame(1, (int)$this->scalar("SELECT COUNT(*) FROM notifications WHERE id = 'web_n_p2'"), "cannot delete someone else's");
        $this->post('delete_notification', ['id' => 'web_n_p1']);
        self::assertSame(0, (int)$this->scalar("SELECT COUNT(*) FROM notifications WHERE id = 'web_n_p1'"));
    }

    public function testSensitiveChangesAreRefusedWhileAnAdminViewsAsTheUser(): void
    {
        $this->signIn('usr_fx_root');
        self::assertSame(200, $this->action('admin_impersonate', ['user_id' => 'usr_fx_p1', 'reason' => 'Support check'])['status']);
        foreach (['change_password', 'update_profile', 'verify_identity', 'delete_own_account'] as $action) {
            self::assertSame('This action is not available while an administrator is viewing as this user.', $this->post($action, ['confirm' => 'DELETE'])['json']['message'], $action);
        }
        self::assertTrue($this->post('mark_notifications_read')['json']['success'], 'ordinary actions still work');
        self::assertSame('player', $this->scalar("SELECT role FROM users WHERE id = 'usr_fx_p1'"));
    }

    public function testLogout(): void
    {
        $this->signIn('usr_fx_p1');
        self::assertSame(403, $this->post('logout', [], 'wrong')['status'], 'a posted sign-out needs the token');
        self::assertSame('Logged out successfully', $this->post('logout')['json']['message']);
        self::assertArrayNotHasKey('picklers_user_id', $_SESSION['_sf2_attributes'] ?? []);
    }
}
