<?php
declare(strict_types=1);

namespace Picklers\Tests\Web;

use Picklers\Tests\Admin\AdminWebTestCase;

/** Rubric workflows use real Symfony endpoints and the disposable test database. */
final class RubricComplianceTest extends AdminWebTestCase
{
    private function api(string $action, array $params = [], string $token = 'rubric-token', string $method = 'POST'): array
    {
        $_SESSION['_sf2_attributes']['_csrf/picklers'] = 'rubric-token';
        $this->client->request($method, '/api.php?action=' . $action, $params, [], ['HTTP_X_CSRF_TOKEN' => $token]);
        return ['status' => $this->client->getResponse()->getStatusCode(),
            'json' => json_decode((string)$this->client->getResponse()->getContent(), true) ?? []];
    }

    protected function tearDown(): void
    {
        self::pdo()->exec("DELETE FROM staff WHERE user_id IN (SELECT id FROM users WHERE email LIKE '%@rubric.fixture.test')");
        self::pdo()->exec("DELETE FROM users WHERE email LIKE '%@rubric.fixture.test'");
        parent::tearDown();
    }

    public function testAdminCreatesStaffWithHashedPasswordAndUpdatesIdentity(): void
    {
        $this->signIn('usr_fx_admin');
        $facility = $this->scalar('SELECT id FROM facilities ORDER BY id LIMIT 1');
        $password = ' Staff-Password-2026 ';
        $created = $this->action('admin_create_user', ['name' => 'New Staff', 'email' => 'staff@rubric.fixture.test',
            'password' => $password, 'role' => 'staff', 'facility_id' => $facility, 'is_admin' => 1]);
        self::assertSame(200, $created['status'], json_encode($created['json']));
        $id = $created['json']['user_id'];
        $row = $this->db()->fetchAssociative('SELECT * FROM users WHERE id = ?', [$id]);
        self::assertTrue(password_verify($password, $row['password_hash']));
        self::assertSame(0, (int)$row['is_admin']);
        self::assertSame(0, (int)$row['is_dev']);
        self::assertSame(1, (int)$this->scalar('SELECT COUNT(*) FROM staff WHERE user_id = ? AND facility_id = ?', [$id, $facility]));
        self::assertArrayNotHasKey('password_hash', $created['json']);
        self::assertNotNull($this->lastAudit('user.create'));
        self::assertSame(200, $this->action('admin_update_user', ['user_id' => $id, 'name' => 'Updated Staff', 'email' => 'updated@rubric.fixture.test'])['status']);
        self::assertSame('updated@rubric.fixture.test', $this->scalar('SELECT email FROM staff WHERE user_id = ?', [$id]));
        self::assertSame('Updated Staff', $this->scalar('SELECT name FROM users WHERE id = ?', [$id]));
        self::assertSame(422, $this->action('admin_update_user', ['user_id' => $id, 'name' => 'Taken', 'email' => 'p1@fixture.test'])['status']);
        self::assertSame('updated@rubric.fixture.test', $this->scalar('SELECT email FROM users WHERE id = ?', [$id]));
        $this->client->request('GET', '/admin?tab=users&role=staff');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('tbody', 'Updated Staff');
        self::assertSelectorTextContains('tbody', 'Staff');
    }

    public function testOnlyPrivilegedAdminCreatesOrEditsAdminAccounts(): void
    {
        $input = ['name' => 'New Admin', 'email' => 'admin@rubric.fixture.test', 'password' => 'Admin-Pass-2026', 'role' => 'admin'];
        $this->signIn('usr_fx_admin');
        self::assertSame(403, $this->action('admin_create_user', $input)['status']);
        $this->signIn('usr_fx_root');
        $created = $this->action('admin_create_user', $input);
        self::assertSame(200, $created['status'], json_encode($created['json']));
        $id = $created['json']['user_id'];
        self::assertSame(1, (int)$this->scalar('SELECT is_admin FROM users WHERE id = ?', [$id]));
        self::assertSame(0, (int)$this->scalar('SELECT COUNT(*) FROM admin_privileges WHERE user_id = ?', [$id]));
        $this->signIn('usr_fx_admin');
        self::assertSame(403, $this->action('admin_update_user', ['user_id' => $id, 'name' => 'Other', 'email' => 'changed@rubric.fixture.test'])['status']);
        $this->signIn('usr_fx_root');
        self::assertSame(200, $this->action('admin_update_user', ['user_id' => $id, 'name' => 'Other', 'email' => 'changed@rubric.fixture.test'])['status']);
    }

    public function testInvalidAccountInputAndDuplicateEmailDoNotCreateRows(): void
    {
        $this->signIn('usr_fx_admin');
        $base = ['name' => 'New', 'email' => 'invalid@rubric.fixture.test', 'password' => 'Valid-Password-2026', 'role' => 'player'];
        foreach ([['name' => ''], ['name' => ['bad']], ['email' => 'bad'], ['password' => 'short'], ['role' => 'superadmin'],
            ['role' => 'staff', 'facility_id' => '99999999'], ['role' => 'staff', 'facility_id' => []], ['role' => 'staff', 'name' => str_repeat('x', 101)], ['email' => 'p1@fixture.test']] as $change) {
            self::assertSame(422, $this->action('admin_create_user', array_replace($base, $change))['status'], json_encode($change));
        }
        self::assertSame(0, (int)$this->scalar("SELECT COUNT(*) FROM users WHERE email = 'invalid@rubric.fixture.test'"));
        self::assertSame(403, $this->action('admin_create_user', $base, 'forged')['status']);
        self::assertSame(405, $this->action('admin_create_user', $base, method: 'GET')['status']);
    }

    public function testStaffAccountCreationRollsBackWhenMembershipFails(): void
    {
        $this->signIn('usr_fx_admin');
        $this->db()->executeStatement("CREATE TRIGGER rubric_staff_failure BEFORE INSERT ON staff FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Injected staff write failure'");
        try {
            self::assertSame(500, $this->action('admin_create_user', ['name' => 'Rollback', 'email' => 'rollback@rubric.fixture.test',
                'password' => 'Rollback-Pass-2026', 'role' => 'staff', 'facility_id' => $this->scalar('SELECT id FROM facilities LIMIT 1')])['status']);
            self::assertSame(0, (int)$this->scalar("SELECT COUNT(*) FROM users WHERE email = 'rollback@rubric.fixture.test'"));
        } finally {
            $this->db()->executeStatement('DROP TRIGGER rubric_staff_failure');
        }
    }

    public function testStaffLifecyclePreservesMembershipUntilPermanentDeletion(): void
    {
        $this->signIn('usr_fx_root');
        $created = $this->action('admin_create_user', ['name' => 'Lifecycle', 'email' => 'lifecycle@rubric.fixture.test',
            'password' => 'Lifecycle-Pass-2026', 'role' => 'staff', 'facility_id' => $this->scalar('SELECT id FROM facilities LIMIT 1')]);
        self::assertSame(200, $created['status']);
        $id = $created['json']['user_id'];
        self::assertSame(200, $this->action('admin_deactivate_user', ['user_id' => $id, 'reason' => 'Staff contract ended'])['status']);
        $this->signIn($id);
        $this->client->request('GET', '/app?tab=community');
        self::assertResponseRedirects('/auth.php');
        $this->signIn('usr_fx_root');
        self::assertSame(1, (int)$this->scalar('SELECT COUNT(*) FROM staff WHERE user_id = ?', [$id]));
        self::assertSame(200, $this->action('admin_reactivate_user', ['user_id' => $id])['status']);
        self::assertSame(422, $this->action('admin_delete_account', ['user_id' => $id, 'reason' => 'Remove unused account'])['status']);
        self::assertSame(200, $this->action('admin_delete_account', ['user_id' => $id, 'confirm_text' => 'DELETE', 'reason' => 'Remove unused account'])['status']);
        self::assertSame(0, (int)$this->scalar('SELECT COUNT(*) FROM staff WHERE user_id = ?', [$id]));
    }

    public function testAdminResetPreservesPasswordWhitespaceAndRejectsUnsafeBcryptInput(): void
    {
        $this->signIn('usr_fx_admin');
        $password = ' Reset-Secret-2026 ';
        self::assertSame(200, $this->action('admin_reset_password', ['user_id' => 'usr_fx_p1', 'new_password' => $password])['status']);
        self::assertTrue(password_verify($password, (string)$this->scalar("SELECT password_hash FROM users WHERE id = 'usr_fx_p1'")));
        foreach ([str_repeat('x', 72) . '1', "Password123\0", ['bad']] as $invalid) {
            self::assertSame(422, $this->action('admin_reset_password', ['user_id' => 'usr_fx_p1', 'new_password' => $invalid])['status']);
        }
    }

    private function assignStaff(): void
    {
        $this->db()->insert('staff', ['id' => 'rubric_staff', 'facility_id' => $this->scalar('SELECT id FROM facilities LIMIT 1'),
            'user_id' => 'usr_fx_p1', 'name' => 'Fixture staff', 'email' => 'p1@fixture.test', 'role' => 'Front Desk']);
    }

    public function testStaffGrantRejectsADeletedOrChangedAccountFromAnEarlierSelection(): void
    {
        $owners = static::getContainer()->get(\Picklers\Repositories\OwnerRepository::class);
        $facility = $this->scalar('SELECT id FROM facilities LIMIT 1');
        foreach (['deleted', 'owner'] as $role) {
            $this->db()->update('users', ['role' => $role], ['id' => 'usr_fx_p1']);
            try {
                $owners->insertStaff(['facility_id' => $facility, 'user_id' => 'usr_fx_p1', 'name' => 'Stale selection', 'email' => 'old@fixture.test']);
                self::fail('An account changed after selection must not receive a staff grant.');
            } catch (\InvalidArgumentException) {
                self::assertSame(0, (int)$this->scalar("SELECT COUNT(*) FROM staff WHERE user_id = 'usr_fx_p1'"));
            }
        }
        $this->db()->update('users', ['role' => 'player', 'name' => 'Current name', 'email' => 'current@fixture.test'], ['id' => 'usr_fx_p1']);
        $staff = $owners->insertStaff(['facility_id' => $facility, 'user_id' => 'usr_fx_p1', 'name' => 'Stale name', 'email' => 'old@fixture.test']);
        self::assertSame('Current name', $staff['name']);
        self::assertSame('current@fixture.test', $staff['email']);
    }

    public function testStaffCannotReachAdminPagesActionsOrAliases(): void
    {
        $this->assignStaff();
        $this->signIn('usr_fx_p1');
        foreach (['/admin', '/admin.php', '/admin/panel/users', '/admin?tab=overview'] as $url) {
            $this->client->request('GET', $url);
            self::assertTrue(in_array($this->client->getResponse()->getStatusCode(), [302, 403], true), $url);
        }
        foreach (['/admin/api', '/api', '/api.php'] as $uri) {
            foreach (['admin_create_user', 'admin_update_user', 'admin_update_role', 'admin_delete_account', 'admin_stats'] as $action) {
                self::assertSame(403, $this->action($action, ['user_id' => 'usr_fx_p2'], uri: $uri)['status']);
            }
        }
        $this->client->request('GET', '/app?tab=community');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href="admin.php"]');
    }

    public function testStaffIdentityUpdateRejectsNamesAboveItsStorageLimit(): void
    {
        $this->assignStaff();
        $this->signIn('usr_fx_admin');
        $before = $this->db()->fetchAssociative("SELECT name, email FROM users WHERE id = 'usr_fx_p1'");
        $result = $this->action('admin_update_user', ['user_id' => 'usr_fx_p1',
            'name' => str_repeat('x', 101), 'email' => 'changed@fixture.test']);
        self::assertSame(422, $result['status']);
        self::assertSame($before, $this->db()->fetchAssociative("SELECT name, email FROM users WHERE id = 'usr_fx_p1'"));
        self::assertSame('Fixture staff', $this->scalar("SELECT name FROM staff WHERE id = 'rubric_staff'"));
        self::assertSame(200, $this->action('admin_update_user', ['user_id' => 'usr_fx_p1',
            'name' => str_repeat('x', 100), 'email' => 'p1@fixture.test'])['status']);
        self::assertSame(str_repeat('x', 100), $this->scalar("SELECT name FROM staff WHERE id = 'rubric_staff'"));
    }

    public function testCommunityFeedIsRemovedForGuestsPlayersAndStaff(): void
    {
        $before = (int)$this->scalar('SELECT COUNT(*) FROM feed_posts');
        foreach ([null, 'usr_fx_p1', 'usr_fx_admin'] as $user) {
            if ($user !== null) $this->signIn($user);
            foreach (['feed_posts', 'create_post', 'update_post', 'delete_post', 'like_post', 'add_comment'] as $action) {
                foreach (['GET', 'POST'] as $method) {
                    $result = $this->api($action, ['post_id' => 'fx_post1', 'content' => 'Removed feature', 'confirm' => 'DELETE'], method: $method);
                    self::assertSame(400, $result['status'], $action);
                    self::assertFalse($result['json']['success']);
                }
            }
        }
        self::assertSame($before, (int)$this->scalar('SELECT COUNT(*) FROM feed_posts'));
        $this->signIn('usr_fx_p1');
        $this->client->request('GET', '/app?tab=community');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a.nav-item-btn.active[href="app.php?tab=play"]');
        self::assertSelectorNotExists('a[href="app.php?tab=community"]');
        self::assertSelectorNotExists('.community-feed');
        self::assertSelectorNotExists('[data-post-form]');
    }

    public function testAdminEditsDeletesAnyPostAndKeepsAuditHistory(): void
    {
        $this->signIn('usr_fx_admin');
        $flag = $this->action('admin_moderation_flag', ['content_type' => 'post', 'content_id' => 'fx_post1', 'category' => 'other', 'reason' => 'Review original post']);
        self::assertSame(200, $flag['status']);
        $snapshot = $this->scalar('SELECT content_snapshot FROM moderation_cases WHERE id = ?', [$flag['json']['case_id']]);
        $this->db()->update('feed_posts', ['hidden_at' => date('Y-m-d H:i:s')], ['id' => 'fx_post1']);
        self::assertSame(200, $this->action('admin_update_post', ['post_id' => 'fx_post1', 'content' => 'Admin correction'])['status']);
        self::assertSame('Admin correction', $this->scalar("SELECT content FROM feed_posts WHERE id = 'fx_post1'"));
        self::assertSame(422, $this->action('admin_delete_post', ['post_id' => 'fx_post1'])['status']);
        self::assertSame(200, $this->action('admin_delete_post', ['post_id' => 'fx_post1', 'confirm_text' => 'DELETE'])['status']);
        foreach (['feed_posts' => 'id', 'feed_comments' => 'post_id', 'feed_likes' => 'post_id'] as $table => $column) {
            self::assertSame(0, (int)$this->scalar("SELECT COUNT(*) FROM {$table} WHERE {$column} = 'fx_post1'"));
        }
        self::assertSame($snapshot, $this->scalar('SELECT content_snapshot FROM moderation_cases WHERE id = ?', [$flag['json']['case_id']]));
        self::assertNotNull($this->lastAudit('post.update'));
        self::assertNotNull($this->lastAudit('post.delete'));
        self::assertSame(404, $this->action('admin_update_post', ['post_id' => 'missing', 'content' => 'Missing'])['status']);
    }

    public function testMandatoryDashboardTotalsCountDistinctActiveStaff(): void
    {
        $this->assignStaff();
        $this->db()->insert('staff', ['id' => 'rubric_staff_duplicate', 'facility_id' => $this->scalar('SELECT id FROM facilities LIMIT 1'), 'user_id' => 'usr_fx_p1', 'name' => 'Duplicate membership']);
        $this->signIn('usr_fx_admin');
        $stats = $this->action('admin_stats')['json'];
        self::assertSame((int)$this->scalar("SELECT COUNT(DISTINCT s.user_id) FROM staff s JOIN users u ON u.id = s.user_id WHERE u.role <> 'deleted'"), $stats['staff_total']);
        self::assertSame((int)$this->scalar('SELECT COUNT(*) FROM feed_posts'), $stats['records_total']);
        $this->client->request('GET', '/admin');
        self::assertSelectorTextContains('[data-kpi="staff_total"]', (string)$stats['staff_total']);
        self::assertSelectorTextContains('[data-kpi="records_total"]', (string)$stats['records_total']);
        $this->db()->update('users', ['role' => 'deleted'], ['id' => 'usr_fx_p1']);
        self::assertSame($stats['staff_total'] - 1, $this->action('admin_stats')['json']['staff_total']);
    }

    public function testEveryLogoutAliasRejectsCsrfAndKeepsSession(): void
    {
        foreach (['/logout', '/auth?logout=1', '/auth.php?logout=1', '/api/logout', '/api?action=logout', '/api.php?action=logout'] as $url) {
            $this->signIn('usr_fx_p1');
            $this->client->request('GET', $url);
            self::assertSame(403, $this->client->getResponse()->getStatusCode(), $url);
            self::assertSame('usr_fx_p1', $_SESSION['_sf2_attributes']['picklers_user_id'] ?? null, $url);
            $_SESSION['_sf2_attributes']['_csrf/picklers'] = 'logout-token';
            $this->client->request('GET', $url, ['csrf_token' => 'logout-token']);
            self::assertContains($this->client->getResponse()->getStatusCode(), [200, 302], $url);
            self::assertArrayNotHasKey('picklers_user_id', $_SESSION['_sf2_attributes'] ?? [], $url);
        }
    }
}
