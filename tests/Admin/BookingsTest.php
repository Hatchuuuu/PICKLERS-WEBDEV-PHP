<?php
declare(strict_types=1);

namespace Picklers\Tests\Admin;

use Picklers\Core\Database;

/** R04 — bookings & matches: transitions, stale edits, refunds, capacity, concurrency. */
final class BookingsTest extends AdminWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->signIn('usr_fx_admin');
    }

    private function balance(string $user): string
    {
        return (string)$this->scalar('SELECT wallet_balance FROM users WHERE id = ?', [$user]);
    }

    public function testConfirmPendingRequestOnceWithStaleProtection(): void
    {
        $stale = $this->action('admin_update_booking_status', ['booking_id' => 'FX-PEND02', 'status' => 'confirmed', 'expected_status' => 'confirmed']);
        self::assertSame(409, $stale['status'], 'operator saw an outdated status');
        self::assertSame('pending', $this->scalar("SELECT status FROM bookings WHERE id = 'FX-PEND02'"));

        $r = $this->action('admin_update_booking_status', ['booking_id' => 'FX-PEND02', 'status' => 'confirmed', 'expected_status' => 'pending', 'reason' => 'Venue confirmed by phone']);
        self::assertSame(200, $r['status'], json_encode($r['json']));
        self::assertSame('confirmed', $this->scalar("SELECT status FROM bookings WHERE id = 'FX-PEND02'"));
        self::assertSame('usr_fx_admin', $this->scalar("SELECT status_changed_by FROM bookings WHERE id = 'FX-PEND02'"));
        self::assertSame('Booking Confirmed! 🎾', $this->scalar("SELECT title FROM notifications WHERE user_id = 'usr_fx_p2' ORDER BY created_at DESC LIMIT 1"));
        self::assertSame(409, $this->action('admin_update_booking_status', ['booking_id' => 'FX-PEND02', 'status' => 'confirmed'])['status'], 'second confirmation refused');
    }

    public function testForbiddenTransitions(): void
    {
        self::assertSame(409, $this->action('admin_cancel_booking', ['booking_id' => 'FX-DONE01', 'reason' => 'Player asked for refund'])['status'], 'completed is final');
        self::assertSame(409, $this->action('admin_update_booking_status', ['booking_id' => 'FX-CANC01', 'status' => 'confirmed'])['status'], 'no reinstatement');
        self::assertSame(422, $this->action('admin_update_booking_status', ['booking_id' => 'FX-PEND01', 'status' => 'upcoming'])['status']);
        self::assertSame(409, $this->action('admin_update_booking_status', ['booking_id' => 'FX-CONF01', 'status' => 'completed'])['status'], 'future session cannot be completed');
        self::assertSame(422, $this->action('admin_cancel_booking', ['booking_id' => 'FX-CONF01', 'reason' => ''])['status'], 'reason required');
        self::assertSame(404, $this->action('admin_cancel_booking', ['booking_id' => 'NOPE', 'reason' => 'Does not exist'])['status']);
    }

    public function testCancelRefundsPickleCreditsExactlyOnceAcrossChannels(): void
    {
        $before = $this->balance('usr_fx_p1');
        $r = $this->action('admin_cancel_booking', ['booking_id' => 'FX-CONF01', 'expected_status' => 'confirmed', 'reason' => 'Court flooded']);
        self::assertSame(200, $r['status'], json_encode($r['json']));
        self::assertTrue($r['json']['refunded']);
        self::assertSame('300.00', $r['json']['refund_amount']);
        self::assertSame(number_format((float)$before + 300, 2, '.', ''), $this->balance('usr_fx_p1'));
        self::assertSame(1, (int)$this->scalar("SELECT COUNT(*) FROM wallet_transactions WHERE idempotency_key = 'refund:booking:FX-CONF01'"));
        self::assertSame('Court flooded', $this->scalar("SELECT status_reason FROM bookings WHERE id = 'FX-CONF01'"));
        self::assertStringContainsString('Court flooded', (string)$this->scalar("SELECT body FROM notifications WHERE user_id = 'usr_fx_p1' ORDER BY created_at DESC LIMIT 1"));

        self::assertSame(409, $this->action('admin_cancel_booking', ['booking_id' => 'FX-CONF01', 'reason' => 'Again please'])['status']);
        $player = Database::get()->cancelBooking('FX-CONF01', 'usr_fx_p1');
        self::assertFalse($player['success'], 'the player path also refuses');
        self::assertSame(number_format((float)$before + 300, 2, '.', ''), $this->balance('usr_fx_p1'), 'no second refund from any path');
        $audit = $this->lastAudit('booking.cancel');
        self::assertSame('FX-CONF01', $audit['target_id']);
        self::assertStringContainsString('"refunded_in_app":true', $audit['changes']);
    }

    public function testLegacyRefundIsRecognisedSoOldCancellationsAreNotRefundedTwice(): void
    {
        // FX-CANC01 was refunded before idempotency keys existed (label only).
        self::pdo()->exec("UPDATE bookings SET status = 'confirmed' WHERE id = 'FX-CANC01'");
        $before = $this->balance('usr_fx_p2');
        $r = $this->action('admin_cancel_booking', ['booking_id' => 'FX-CANC01', 'reason' => 'Re-cancel legacy row']);
        self::assertSame(200, $r['status']);
        self::assertFalse($r['json']['refunded']);
        self::assertSame($before, $this->balance('usr_fx_p2'));
    }

    public function testExternalPaymentsAreNeverRefundedInApp(): void
    {
        $before = $this->balance('usr_fx_p2');
        $r = $this->action('admin_cancel_booking', ['booking_id' => 'FX-CONF03', 'reason' => 'Venue closed for event']);
        self::assertSame(200, $r['status']);
        self::assertFalse($r['json']['refunded']);
        self::assertTrue($r['json']['external_payment']);
        self::assertStringContainsString('no in-app refund', $r['json']['message']);
        self::assertSame($before, $this->balance('usr_fx_p2'));
    }

    public function testConcurrentCancellationsRefundOnce(): void
    {
        $before = $this->balance('usr_fx_p1');
        $results = $this->concurrently(4, 'cancel', ['booking_id' => 'FX-PEND01']);
        self::assertCount(1, array_filter($results, fn($r) => ($r['ok'] ?? false) === true), json_encode($results));
        self::assertSame(number_format((float)$before + 300, 2, '.', ''), $this->balance('usr_fx_p1'));
        self::assertSame(1, (int)$this->scalar("SELECT COUNT(*) FROM wallet_transactions WHERE user_id = 'usr_fx_p1' AND type = 'credit' AND label LIKE '%FX-PEND01%'"));
    }

    public function testOpenPlayCapacityIsEnforcedPerOccurrence(): void
    {
        $fac = (int)$this->scalar("SELECT id FROM facilities WHERE name = 'Fixture Arena Dumaguete'");
        $date = (new \DateTimeImmutable('+7 days'))->format('Y-m-d');
        self::pdo()->exec("DELETE FROM matches WHERE id = 'FX-OP1'");
        self::pdo()->prepare("INSERT INTO matches (id, facility_id, facility_name, location, date, time, level, current_players, max_players, price, type, host) VALUES ('FX-OP1', ?, 'Fixture Arena Dumaguete', 'x', ?, '6:00 PM - 8:00 PM', 'Open', 0, 2, 150, 'Court 1', 'Host')")
            ->execute([$fac, (new \DateTimeImmutable($date))->format('D, M j, Y')]);
        $ins = self::pdo()->prepare("INSERT INTO bookings (id, user_id, facility_id, match_id, facility_name, court_name, date, time, booking_date, duration, price, payment_method, status, created_at) VALUES (?, ?, ?, 'FX-OP1', 'Fixture Arena Dumaguete', 'Court 1', ?, '6:00 PM - 8:00 PM', ?, '2 Hours', 150, 'GCash', 'pending', NOW())");
        foreach (['FX-OPA' => 'usr_fx_p1', 'FX-OPB' => 'usr_fx_p2', 'FX-OPC' => 'usr_fx_p3'] as $id => $u) {
            $ins->execute([$id, $u, $fac, (new \DateTimeImmutable($date))->format('D, M j, Y'), $date]);
        }
        $full = $this->action('admin_update_booking_status', ['booking_id' => 'FX-OPA', 'status' => 'confirmed']);
        self::assertSame(409, $full['status'], 'three requests for two seats');
        self::assertStringContainsString('full', $full['json']['message']);
        self::assertSame(200, $this->action('admin_cancel_booking', ['booking_id' => 'FX-OPC', 'reason' => 'Session oversubscribed'])['status']);
        self::assertSame(200, $this->action('admin_update_booking_status', ['booking_id' => 'FX-OPA', 'status' => 'confirmed'])['status']);
        self::assertSame(1, (int)$this->scalar("SELECT current_players FROM matches WHERE id = 'FX-OP1'"), 'one seat taken');
        self::assertSame(200, $this->action('admin_cancel_booking', ['booking_id' => 'FX-OPA', 'reason' => 'Player injured'])['status']);
        self::assertSame(0, (int)$this->scalar("SELECT current_players FROM matches WHERE id = 'FX-OP1'"), 'seat released');
    }

    public function testFiltersAreCaseInsensitiveAndCountsExact(): void
    {
        $this->client->request('GET', '/admin/panel/bookings', ['payment' => 'gcash', 'q' => 'FX-']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.pk-pager__count', 'of 3', 'GCash and gcash rows are one method');
        $this->client->request('GET', '/admin/panel/bookings', ['status' => 'confirmed', 'kind' => 'court', 'q' => 'FX-', 'facility' => (string)$this->scalar("SELECT id FROM facilities WHERE name = 'Fixture Arena Dumaguete'")]);
        self::assertSelectorTextContains('.pk-pager__count', 'of 2');
        self::assertSelectorTextContains('.pk-table-summary', '₱650.00');
    }
}
