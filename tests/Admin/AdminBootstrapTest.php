<?php
declare(strict_types=1);

namespace Picklers\Tests\Admin;

use Picklers\Admin\Command\AdminPrivilegeCommand;
use Symfony\Component\Console\Tester\CommandTester;

final class AdminBootstrapTest extends AdminWebTestCase
{
    public function testConcurrentBootstrapCreatesOnlyOneAdministrator(): void
    {
        $db = $this->db();
        $original = $db->fetchAllAssociative('SELECT id, role, is_admin FROM users');
        $db->executeStatement('UPDATE users SET is_admin = 0');
        try {
            $results = $this->concurrently(2, 'bootstrap_admin', ['users' => ['usr_fx_p1', 'usr_fx_p2']]);
            self::assertSame(1, (int)$db->fetchOne("SELECT COUNT(*) FROM users WHERE is_admin = 1 AND role <> 'deleted'"), json_encode($results));
            $codes = array_map(static fn(array $r): int => $r['result']['code'] ?? -1, $results);
            sort($codes);
            self::assertSame([0, 1], $codes, json_encode($results));
            self::assertSame(1, (int)$db->fetchOne("SELECT COUNT(*) FROM admin_privileges WHERE user_id IN ('usr_fx_p1', 'usr_fx_p2')"));
        } finally {
            foreach ($original as $row) {
                $db->update('users', ['role' => $row['role'], 'is_admin' => $row['is_admin']], ['id' => $row['id']]);
            }
            $db->executeStatement("DELETE FROM admin_privileges WHERE user_id IN ('usr_fx_p1', 'usr_fx_p2')");
        }
    }

    public function testFirstAdministratorIsExplicitlyBootstrappedAndAudited(): void
    {
        $db = $this->db();
        $db->beginTransaction();
        try {
            $db->executeStatement("UPDATE users SET is_admin = 0 WHERE role <> 'deleted'");
            $tester = new CommandTester(static::getContainer()->get(AdminPrivilegeCommand::class));
            self::assertSame(0, $tester->execute(['operation' => 'bootstrap', 'user' => 'p1@fixture.test', '--reason' => 'Initial Docker administrator']), $tester->getDisplay());
            self::assertSame(1, (int)$db->fetchOne("SELECT is_admin FROM users WHERE id = 'usr_fx_p1'"));
            self::assertSame(1, (int)$db->fetchOne("SELECT COUNT(*) FROM admin_privileges WHERE user_id = 'usr_fx_p1'"));
            self::assertSame(1, (int)$db->fetchOne("SELECT COUNT(*) FROM audit_events WHERE target_id = 'usr_fx_p1' AND action = 'user.privilege.bootstrap' AND reason = 'Initial Docker administrator'"));
            self::assertSame(1, $tester->execute(['operation' => 'bootstrap', 'user' => 'p2@fixture.test', '--reason' => 'Second bootstrap must fail']));
            self::assertSame(0, (int)$db->fetchOne("SELECT is_admin FROM users WHERE id = 'usr_fx_p2'"));
        } finally {
            $db->rollBack();
        }
    }

    public function testBootstrapCannotReactivateADeletedAccount(): void
    {
        $db = $this->db();
        $db->beginTransaction();
        try {
            $db->executeStatement('UPDATE users SET is_admin = 0');
            $db->executeStatement("UPDATE users SET role = 'deleted' WHERE id = 'usr_fx_p1'");
            $tester = new CommandTester(static::getContainer()->get(AdminPrivilegeCommand::class));
            self::assertSame(1, $tester->execute(['operation' => 'bootstrap', 'user' => 'p1@fixture.test', '--reason' => 'Deleted account must fail']));
            self::assertSame(0, (int)$db->fetchOne("SELECT is_admin FROM users WHERE id = 'usr_fx_p1'"));
        } finally {
            $db->rollBack();
        }
    }
}
