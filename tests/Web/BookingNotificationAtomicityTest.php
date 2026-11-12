<?php
declare(strict_types=1);

namespace Picklers\Tests\Web;

use Picklers\Repositories\MatchRepository;
use Picklers\Repositories\WalletRepository;
use Picklers\Tests\Admin\AdminWebTestCase;

final class BookingNotificationAtomicityTest extends AdminWebTestCase
{
    public function testCourtRequestNotificationFailureDoesNotCommitChargeOrReservation(): void
    {
        $facility = (int)$this->scalar("SELECT facility_id FROM bookings WHERE id = 'FX-PEND01'");
        $this->assertNotificationFailureIsAtomic('book_court', [
            'facility_id' => $facility, 'court_id' => "crt_{$facility}_1", 'court_name' => 'Court 1',
            'date' => date('Y-m-d', strtotime('+20 days')), 'time' => '8:00 AM - 9:00 AM',
            'duration' => 1, 'payment_method' => 'Pickle Credits',
        ]);
    }

    public function testOpenPlayNotificationFailureDoesNotCommitChargeOrSeat(): void
    {
        $facility = (int)$this->scalar("SELECT facility_id FROM bookings WHERE id = 'FX-PEND01'");
        static::getContainer()->get(MatchRepository::class)->insertMatch([
            'id' => 'op_atomic_notification', 'facility_id' => $facility, 'facility_name' => 'Fixture Arena Dumaguete',
            'type' => 'Court 1', 'date' => date('Y-m-d', strtotime('+3 days')),
            'time' => '10:00 AM - 12:00 PM', 'price' => 150,
        ]);
        $this->assertNotificationFailureIsAtomic('join_match', ['match_id' => 'op_atomic_notification', 'payment_method' => 'Pickle Credits']);
    }

    public function testCancellationNotificationFailureDoesNotCommitRefundOrCancellation(): void
    {
        $this->assertNotificationFailureIsAtomic('cancel_booking', ['booking_id' => 'FX-PEND01']);
    }

    public function testTopUpNotificationFailureDoesNotCommitWalletCredit(): void
    {
        $before = $this->financialState();
        $this->db()->executeStatement("CREATE TRIGGER test_topup_notification_fail BEFORE INSERT ON notifications FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Injected notification write failure'");
        try {
            $result = static::getContainer()->get(WalletRepository::class)->topUpWallet('usr_fx_p1', 100, 'Synthetic top-up');
            self::assertSame($before, $this->financialState(), 'A failed top-up notification must roll back the credit.');
            self::assertFalse($result['success']);
        } finally {
            $this->db()->executeStatement('DROP TRIGGER test_topup_notification_fail');
        }
    }

    private function assertNotificationFailureIsAtomic(string $action, array $params): void
    {
        $before = $this->financialState();
        $this->db()->executeStatement("CREATE TRIGGER test_booking_notification_fail BEFORE INSERT ON notifications FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Injected notification write failure'");
        try {
            $this->signIn('usr_fx_p1');
            $_SESSION['_sf2_attributes']['_csrf/picklers'] = 'notification-atomicity';
            $this->client->request('POST', '/api?action=' . $action, $params, [], ['HTTP_X_CSRF_TOKEN' => 'notification-atomicity']);
            self::assertSame($before, $this->financialState(), 'A failed notification must roll back its booking, charge or refund.');
            $json = json_decode((string)$this->client->getResponse()->getContent(), true);
            self::assertFalse($json['success']);
        } finally {
            $this->db()->executeStatement('DROP TRIGGER test_booking_notification_fail');
        }
    }

    private function financialState(): array
    {
        return [
            $this->scalar("SELECT wallet_balance FROM users WHERE id = 'usr_fx_p1'"),
            $this->db()->fetchAllAssociative("SELECT * FROM bookings WHERE user_id = 'usr_fx_p1' ORDER BY id"),
            $this->db()->fetchAllAssociative("SELECT * FROM wallet_transactions WHERE user_id = 'usr_fx_p1' ORDER BY id"),
            $this->db()->fetchAllAssociative('SELECT * FROM notifications ORDER BY id'),
        ];
    }
}
