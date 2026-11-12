<?php
declare(strict_types=1);

namespace Picklers\Tests\Web;

use Picklers\Tests\Admin\AdminWebTestCase;

final class AccountConcurrencyTest extends AdminWebTestCase
{
    public function testConcurrentBookingCannotLeaveADeletedAccountHoldingACourt(): void
    {
        $facility = (string)$this->scalar("SELECT id FROM facilities WHERE owner_id = 'usr_fx_owner1' LIMIT 1");
        $wallet = $this->scalar("SELECT wallet_balance FROM users WHERE id = 'usr_fx_p4'");
        $this->db()->executeStatement("CREATE TRIGGER test_slow_account_delete BEFORE UPDATE ON users FOR EACH ROW BEGIN IF NEW.id = 'usr_fx_p4' AND NEW.role = 'deleted' THEN DO SLEEP(0.2); END IF; END");
        try {
            $results = $this->concurrently(2, 'delete_vs_book', ['user_id' => 'usr_fx_p4', 'facility_id' => $facility, 'court_id' => 'crt_' . $facility . '_1', 'date' => date('Y-m-d', strtotime('+20 days'))]);
            self::assertTrue($results[0]['ok'] ?? false, json_encode($results));
            self::assertTrue($results[1]['ok'] ?? false, json_encode($results));
            self::assertArrayHasKey('success', $results[1]['result']);
            self::assertSame('deleted', $this->scalar("SELECT role FROM users WHERE id = 'usr_fx_p4'"));
            self::assertSame(0, (int)$this->scalar("SELECT COUNT(*) FROM bookings WHERE user_id = 'usr_fx_p4' AND status IN ('pending', 'confirmed')"));
            self::assertSame($wallet, $this->scalar("SELECT wallet_balance FROM users WHERE id = 'usr_fx_p4'"));
        } finally {
            $this->db()->executeStatement('DROP TRIGGER test_slow_account_delete');
        }
    }
}
