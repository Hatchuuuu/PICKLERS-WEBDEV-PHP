<?php
declare(strict_types=1);

namespace Picklers\Tests\Web;

use Picklers\Tests\Admin\AdminWebTestCase;

final class FixtureReseedTest extends AdminWebTestCase
{
    public function testRawReseedRemovesGeneratedRefundsBeforeReusingBookingIds(): void
    {
        $this->db()->insert('wallet_transactions', [
            'id' => 'generated_refund_reseed', 'user_id' => 'usr_fx_p1', 'type' => 'credit',
            'amount' => '300.00', 'label' => 'Refund: Booking #FX-PEND01',
            'booking_id' => 'FX-PEND01', 'idempotency_key' => 'refund:FX-PEND01',
        ]);
        $this->db()->insert('notifications', [
            'id' => 'generated_notice_reseed', 'user_id' => 'usr_fx_p1', 'title' => 'Old decision',
        ]);
        $otherUser = $this->db()->fetchAssociative("SELECT * FROM users WHERE id='usr_fx_p2'");
        $otherUser['id'] = 'usr_untouched_reseed';
        $otherUser['email'] = 'untouched-reseed@fixture.test';
        $otherUser['phone'] = null;
        $this->db()->insert('users', $otherUser);
        $this->db()->insert('promo_codes', ['id' => 'keep_reseed_promo', 'code' => 'KEEPRESEED', 'times_used' => 2]);
        foreach (['generated_redeem_fixture' => 'usr_fx_p1', 'generated_redeem_other' => 'usr_untouched_reseed'] as $id => $userId) {
            $this->db()->insert('promo_redemptions', ['id' => $id, 'promo_id' => 'keep_reseed_promo', 'promo_code' => 'KEEPRESEED', 'user_id' => $userId, 'discount_amount' => 10]);
        }
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/scripts/seed-fixtures.php') . ' picklers_admin_test';
        exec($command . ' 2>&1', $output, $code);
        self::assertSame(0, $code, implode("\n", $output));
        self::assertSame(0, (int)$this->scalar("SELECT COUNT(*) FROM wallet_transactions WHERE id = 'generated_refund_reseed'"));
        self::assertSame(0, (int)$this->scalar("SELECT COUNT(*) FROM notifications WHERE id = 'generated_notice_reseed'"));
        self::assertSame(1, (int)$this->scalar("SELECT times_used FROM promo_codes WHERE id='keep_reseed_promo'"));
        self::assertSame(1, (int)$this->scalar("SELECT COUNT(*) FROM promo_redemptions WHERE id='generated_redeem_other'"));
        self::assertSame(0, (int)$this->scalar("SELECT COUNT(*) FROM promo_redemptions WHERE id='generated_redeem_fixture'"));
        self::assertEquals(80.5, $this->scalar("SELECT wallet_balance FROM users WHERE id='usr_untouched_reseed'"));
        $this->db()->delete('promo_redemptions', ['id' => 'generated_redeem_other']);
        $this->db()->delete('promo_codes', ['id' => 'keep_reseed_promo']);
        $this->db()->delete('users', ['id' => 'usr_untouched_reseed']);
        self::assertSame('pending', $this->scalar("SELECT status FROM bookings WHERE id = 'FX-PEND01'"));
        self::assertEquals(1200, $this->scalar("SELECT wallet_balance FROM users WHERE id = 'usr_fx_p1'"));
        self::assertSame(0, (int)$this->scalar("SELECT COUNT(*) FROM users u LEFT JOIN (SELECT user_id, SUM(CASE WHEN type='credit' THEN amount ELSE -amount END) amount FROM wallet_transactions GROUP BY user_id) w ON w.user_id=u.id WHERE LEFT(u.id,7)='usr_fx_' AND u.wallet_balance<>COALESCE(w.amount,0)"));
        self::assertSame('privileged', $this->scalar("SELECT level FROM admin_privileges WHERE user_id='usr_fx_root'"));
        $this->signIn('usr_fx_owner1');
        $_SESSION['_sf2_attributes']['_csrf/picklers'] = 'fixture-reset-token';
        $this->client->request('POST', '/api', [
            'action' => 'decline_booking', 'booking_id' => 'FX-PEND01',
            'csrf_token' => 'fixture-reset-token', 'reason' => 'Fixture repeat presentation',
        ]);
        $result = json_decode((string)$this->client->getResponse()->getContent(), true);
        self::assertTrue($result['success'] ?? false, json_encode($result));
        self::assertTrue($result['refunded'] ?? false);
        self::assertEquals(1500, $this->scalar("SELECT wallet_balance FROM users WHERE id = 'usr_fx_p1'"));
    }
}
