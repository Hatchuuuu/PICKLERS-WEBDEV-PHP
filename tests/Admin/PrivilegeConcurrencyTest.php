<?php
declare(strict_types=1);

namespace Picklers\Tests\Admin;

final class PrivilegeConcurrencyTest extends AdminWebTestCase
{
    public function testConcurrentWebDemotionsPreserveTheLastActiveAdministrator(): void
    {
        $db = $this->db();
        $others = $db->fetchAllAssociative("SELECT id, role, is_admin, is_dev FROM users WHERE id NOT IN ('usr_fx_root', 'usr_fx_admin')");
        $db->executeStatement("UPDATE users SET role = 'player', is_admin = 0, is_dev = 0 WHERE id NOT IN ('usr_fx_root', 'usr_fx_admin')");
        $db->insert('admin_privileges', ['user_id' => 'usr_fx_admin', 'level' => 'privileged', 'granted_at' => date('Y-m-d H:i:s'), 'note' => 'fixture']);
        $db->executeStatement('CREATE TRIGGER test_slow_demotion BEFORE UPDATE ON users FOR EACH ROW DO SLEEP(0.2)');
        try {
            $results = $this->concurrently(2, 'demote_admin', ['actors' => ['usr_fx_root', 'usr_fx_admin'], 'users' => ['usr_fx_admin', 'usr_fx_root']]);
            self::assertSame(1, (int)$this->scalar('SELECT COUNT(*) FROM users WHERE is_admin = 1'), json_encode($results));
            self::assertSame(1, count(array_filter($results, fn($r) => $r['ok'] ?? false)), json_encode($results));
            $rejected = array_values(array_filter($results, fn($r) => !($r['ok'] ?? false)))[0];
            self::assertSame(409, $rejected['status']);
            self::assertStringContainsString('last active administrator', $rejected['message']);
        } finally {
            $db->executeStatement('DROP TRIGGER test_slow_demotion');
            foreach ($others as $row) {
                $id = $row['id']; unset($row['id']);
                $db->update('users', $row, ['id' => $id]);
            }
        }
    }

    public function testConcurrentConsoleRevokesPreserveOnePrivilegedAdministrator(): void
    {
        $this->db()->insert('admin_privileges', ['user_id' => 'usr_fx_admin', 'level' => 'privileged', 'granted_at' => date('Y-m-d H:i:s'), 'note' => 'fixture']);
        $this->db()->executeStatement('CREATE TRIGGER test_slow_revoke BEFORE DELETE ON admin_privileges FOR EACH ROW DO SLEEP(0.2)');
        try {
            $results = $this->concurrently(2, 'revoke_privilege', ['users' => ['usr_fx_root', 'usr_fx_admin']]);
            self::assertSame(1, (int)$this->scalar('SELECT COUNT(*) FROM admin_privileges'), json_encode($results));
            $codes = array_map(fn($r) => $r['result']['code'] ?? -1, $results);
            sort($codes);
            self::assertSame([0, 1], $codes, json_encode($results));
        } finally {
            $this->db()->executeStatement('DROP TRIGGER test_slow_revoke');
        }
    }
}
