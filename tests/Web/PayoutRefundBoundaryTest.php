<?php
declare(strict_types=1);

namespace Picklers\Tests\Web;

use Picklers\Tests\Admin\AdminWebTestCase;

final class PayoutRefundBoundaryTest extends AdminWebTestCase
{
    public function testOwnerCannotRefundAnElapsedBookingAfterRequestingItsPayout(): void
    {
        $facility = (string)$this->scalar("SELECT facility_id FROM bookings WHERE id = 'FX-DONE01'");
        $this->db()->update('wallet_transactions', ['booking_id' => 'FX-DONE01', 'entry_kind' => 'booking_payment'], ['id' => 'fx_tx02']);
        $this->signIn('usr_fx_owner1');
        self::assertSame(200, $this->ownerRequest('request_payout', ['facility_id' => $facility, 'amount' => 100, 'method' => 'GCash', 'account_name' => 'Owner', 'account_number' => '09171234567']));
        foreach (['confirmed', 'completed'] as $status) {
            $this->db()->update('bookings', ['status' => $status], ['id' => 'FX-DONE01']);
            $this->assertOwnerDeclineHasNoEffect('FX-DONE01');
        }
    }

    public function testOwnerCannotRefundAnInProgressOrEndedEarlyBooking(): void
    {
        $this->signIn('usr_fx_owner1');
        $this->db()->update('bookings', ['booking_date' => date('Y-m-d'), 'start_min' => 0, 'end_min' => 1440], ['id' => 'FX-CONF01']);
        $this->assertOwnerDeclineHasNoEffect('FX-CONF01');
        $this->db()->update('bookings', ['booking_date' => date('Y-m-d', strtotime('+2 days')), 'ended_early' => 1], ['id' => 'FX-CONF01']);
        $this->assertOwnerDeclineHasNoEffect('FX-CONF01');
    }

    public function testFutureRequestsStillRefundAndGovernedAdminCancellationIsUnchanged(): void
    {
        $this->signIn('usr_fx_owner1');
        foreach (['FX-PEND01', 'FX-CONF01'] as $booking) {
            $balance = (float)$this->scalar("SELECT wallet_balance FROM users WHERE id = 'usr_fx_p1'");
            self::assertSame(200, $this->ownerRequest('decline_booking', ['booking_id' => $booking]));
            self::assertSame('cancelled', $this->scalar('SELECT status FROM bookings WHERE id = ?', [$booking]));
            self::assertEquals($balance + 300, (float)$this->scalar("SELECT wallet_balance FROM users WHERE id = 'usr_fx_p1'"));
        }
        $this->db()->update('bookings', ['status' => 'confirmed'], ['id' => 'FX-DONE01']);
        $this->signIn('usr_fx_admin');
        self::assertSame(200, $this->action('admin_cancel_booking', ['booking_id' => 'FX-DONE01', 'reason' => 'Support approved correction'])['status']);
        self::assertNotNull($this->lastAudit('booking.cancel'));
    }

    public function testElapsedUnacceptedRequestCanStillBeDeclinedAndRefunded(): void
    {
        $this->db()->update('bookings', ['booking_date' => date('Y-m-d', strtotime('-2 days'))], ['id' => 'FX-PEND01']);
        $this->signIn('usr_fx_owner1');
        $balance = (float)$this->scalar("SELECT wallet_balance FROM users WHERE id = 'usr_fx_p1'");
        self::assertSame(200, $this->ownerRequest('decline_booking', ['booking_id' => 'FX-PEND01']));
        self::assertSame('cancelled', $this->scalar("SELECT status FROM bookings WHERE id = 'FX-PEND01'"));
        self::assertEquals($balance + 300, (float)$this->scalar("SELECT wallet_balance FROM users WHERE id = 'usr_fx_p1'"));
    }

    private function assertOwnerDeclineHasNoEffect(string $booking): void
    {
        $before = [
            $this->db()->fetchAssociative('SELECT * FROM bookings WHERE id = ?', [$booking]),
            $this->db()->fetchAssociative("SELECT * FROM users WHERE id = 'usr_fx_p1'"),
            $this->db()->fetchAllAssociative('SELECT * FROM wallet_transactions ORDER BY id'),
            $this->db()->fetchAllAssociative('SELECT * FROM payout_requests ORDER BY id'),
            $this->db()->fetchAllAssociative('SELECT * FROM notifications ORDER BY id'),
        ];
        self::assertSame(409, $this->ownerRequest('decline_booking', ['booking_id' => $booking]));
        self::assertSame($before, [
            $this->db()->fetchAssociative('SELECT * FROM bookings WHERE id = ?', [$booking]),
            $this->db()->fetchAssociative("SELECT * FROM users WHERE id = 'usr_fx_p1'"),
            $this->db()->fetchAllAssociative('SELECT * FROM wallet_transactions ORDER BY id'),
            $this->db()->fetchAllAssociative('SELECT * FROM payout_requests ORDER BY id'),
            $this->db()->fetchAllAssociative('SELECT * FROM notifications ORDER BY id'),
        ]);
    }

    private function ownerRequest(string $action, array $params): int
    {
        $_SESSION['_sf2_attributes']['_csrf/picklers'] = 'payout-refund';
        $path = $action === 'request_payout' ? '/owner.php' : '/api';
        $this->client->request('POST', $path . '?action=' . $action, $params, [], ['HTTP_X_CSRF_TOKEN' => 'payout-refund', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        return $this->client->getResponse()->getStatusCode();
    }
}
