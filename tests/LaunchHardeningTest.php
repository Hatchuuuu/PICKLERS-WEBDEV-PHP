<?php
declare(strict_types=1);

namespace Picklers\Tests;

use Picklers\Core\Database;

/**
 * Regression coverage for the launch-readiness audit: booking slot integrity,
 * precise Open Play cancellation with refunds, promo redemption on Open Play
 * joins, atomic status transitions, payout balance accounting, the
 * started-session cancellation guard, and a non-mutating wallet ledger read.
 *
 * Everything runs on a freshly provisioned facility so no seeded data is touched.
 */
final class LaunchHardeningTest extends TestCase {

    public function run(): void {
        $db = Database::get();
        $tag = bin2hex(random_bytes(4));

        $makeUser = function (string $label, float $wallet = 0.0) use ($db, $tag): string {
            $id = "usr_test_harden_{$label}_{$tag}";
            $db->createUser([
                'id' => $id, 'name' => "Hardening {$label}",
                'email' => "{$id}@example.test", 'password_hash' => password_hash('x1234567', PASSWORD_DEFAULT),
            ]);
            if ($wallet > 0) {
                $db->updateUser($id, ['wallet_balance' => $wallet]);
            }
            return $id;
        };

        $ownerId = $makeUser('owner');
        $facilityId = $db->createFacilityFromApplication([
            'id' => "app_test_harden_{$tag}",
            'user_id' => $ownerId,
            'facility_name' => "Hardening Facility {$tag}",
            'address' => '1 Integrity Street',
            'courts_count' => 10,
            'operating_hours' => '6:00 AM – 11:00 PM',
        ]);
        $courts = [];
        foreach ($db->getCourtsByFacility($facilityId) as $c) {
            $courts[(string)$c['name']] = $c;
        }
        $this->assertTrue(isset($courts['Court 1'], $courts['Court 10']), 'Fixture facility has Court 1 and Court 10');

        // ── A booking must carry a real time range ─────────────────────────
        $booker = $makeUser('booker');
        $bad = $db->createBooking($booker, $facilityId, 'Court 2', 'Tomorrow', '8:00 AM - whenever', 1, 440.0, 'Pay at Venue', (string)$courts['Court 2']['id']);
        $this->assertFalse((bool)($bad['success'] ?? true), 'REGRESSION: a time slot with an unparseable end is rejected');

        // ── Fixtures: one Open Play session on Court 1 and one on Court 10 ─
        $sessionDate = date('Y-m-d', strtotime('+3 days'));
        $matchCourt1 = $db->insertMatch([
            'id' => "op_harden_c1_{$tag}", 'facility_id' => $facilityId, 'facility_name' => "Hardening Facility {$tag}",
            'date' => $sessionDate, 'time' => '6:00 PM - 8:00 PM', 'max_players' => 8, 'price' => 100.0, 'type' => 'Court 1', 'title' => 'C1 Social',
        ]);
        $matchCourt10 = $db->insertMatch([
            'id' => "op_harden_c10_{$tag}", 'facility_id' => $facilityId, 'facility_name' => "Hardening Facility {$tag}",
            'date' => $sessionDate, 'time' => '6:00 PM - 8:00 PM', 'max_players' => 8, 'price' => 100.0, 'type' => 'Court 10', 'title' => 'C10 Social',
        ]);

        $creditsPlayer = $makeUser('credits', 1000.0);
        $join1 = $db->joinMatch($matchCourt1['id'], $creditsPlayer, 'Pickle Credits');
        $this->assertTrue((bool)($join1['success'] ?? false), 'A Pickle Credits player joins the Court 1 session: ' . ($join1['message'] ?? ''));
        $this->assertEquals(890.0, (float)($db->getUserById($creditsPlayer)['wallet_balance'] ?? 0), 'The join debits entry + service fee (₱110)');

        $venuePlayer = $makeUser('venue');
        $join10 = $db->joinMatch($matchCourt10['id'], $venuePlayer, 'Pay at Venue');
        $this->assertTrue((bool)($join10['success'] ?? false), 'A pay-at-venue player joins the Court 10 session');

        // ── REGRESSION: an Open Play join with a valid promo code succeeds ─
        // (swapped recordPromoRedemption() arguments threw inside the
        // transaction, so every promo join failed with a system error)
        $promo = $db->createPromoCode(['code' => 'HARDEN' . strtoupper($tag), 'discount_type' => 'fixed', 'discount_value' => 20, 'user_limit' => 1]);
        $promoPlayer = $makeUser('promo', 500.0);
        $promoJoin = $db->joinMatch($matchCourt10['id'], $promoPlayer, 'Pickle Credits', $promo['code']);
        $this->assertTrue((bool)($promoJoin['success'] ?? false), 'REGRESSION: an Open Play join with a valid promo code succeeds: ' . ($promoJoin['message'] ?? ''));
        $this->assertEquals(90.0, (float)($promoJoin['booking']['price'] ?? 0), 'The promo discount is applied to the charged price');
        $this->assertSame(1, $db->getPromoRedemptionsCount((string)$promo['id'], $promoPlayer), 'The redemption is recorded against the player');

        // A regular court reservation on Court 1 that is NOT part of Open Play.
        $reservation = $db->createBooking($booker, $facilityId, 'Court 1', 'Tomorrow', '9:00 AM - 10:00 AM', 1, 440.0, 'Pay at Venue', (string)$courts['Court 1']['id']);
        $this->assertTrue((bool)($reservation['success'] ?? false), 'A regular reservation on Court 1 is booked: ' . ($reservation['message'] ?? ''));

        // ── Roster is the session's own players only ───────────────────────
        $roster10 = $db->getOpenPlayRoster($facilityId, 'Court 10');
        $this->assertSame(2, count($roster10), "Court 10's roster lists exactly its two joined players");

        // ── REGRESSION: cancelling Court 1's Open Play is precise ──────────
        $outcome = $db->cancelOpenPlaySessions('', $facilityId, 'Court 1');
        $this->assertTrue($outcome['deleted'], 'The Court 1 session is removed');
        $this->assertSame(1, $outcome['cancelled_bookings'], 'Only the Court 1 join booking is cancelled');
        $this->assertEquals(110.0, $outcome['refunded_amount'], 'The Pickle Credits payment is refunded');
        $this->assertEquals(1000.0, (float)($db->getUserById($creditsPlayer)['wallet_balance'] ?? 0), "The player's wallet is made whole");
        $this->assertNotNull($db->getMatchById($matchCourt10['id']), 'REGRESSION: "Court 10" is not caught by cancelling "Court 1"');
        $this->assertSame('pending', (string)($db->getBookingById((string)$join10['booking']['id'])['status'] ?? ''), "Court 10's join request is untouched");
        $this->assertSame('pending', (string)($db->getBookingById((string)$reservation['booking']['id'])['status'] ?? ''),
            'REGRESSION: an ordinary reservation on Court 1 is not cancelled with the Open Play session');
        $this->assertSame([], $db->getOpenPlayRoster($facilityId, 'Court 1'), 'A court without an active session has an empty roster');

        // ── Atomic status transition ───────────────────────────────────────
        $venueBookingId = (string)$join10['booking']['id'];
        $this->assertTrue($db->transitionBookingStatus($venueBookingId, ['pending'], 'confirmed'), 'The first approval moves the request to confirmed');
        $this->assertFalse($db->transitionBookingStatus($venueBookingId, ['pending'], 'confirmed'), 'REGRESSION: a repeated approval reports no change');

        // ── The owner's request queue and daily income use real data ───────
        $queue = $db->getPendingBookingRequests($facilityId);
        $queueIds = array_column($queue, 'id');
        $this->assertTrue(in_array((string)$promoJoin['booking']['id'], $queueIds, true) && in_array((string)$reservation['booking']['id'], $queueIds, true),
            'The facility-scoped request queue lists this facility\'s pending requests');
        $this->assertFalse(in_array($venueBookingId, $queueIds, true), 'A confirmed booking has left the request queue');

        $breakdown = $db->getOwnerFinancials($facilityId)['daily_breakdown'];
        $this->assertSame(30, count($breakdown), 'The daily income breakdown covers exactly 30 days');
        $this->assertEquals(110.0, (float)$breakdown[0]['revenue'], "Today's row carries today's confirmed revenue, not an invented figure");
        $this->assertSame(1, (int)$breakdown[0]['sessions'], "Today's row counts the one confirmed booking");

        // ── Payout availability accounts for earlier requests ──────────────
        $available = (float)$db->getOwnerFinancials($facilityId)['available'];
        $this->assertEquals(83.6, $available, 'Available = 80% of net confirmed revenue (₱110 gross, 5% fee)');
        $firstPayout = $db->createPayoutRequest($ownerId, (string)$facilityId, $available, 'GCash', 'test');
        $this->assertTrue((bool)($firstPayout['success'] ?? false), 'A payout of the full available balance is accepted');
        $this->assertEquals(0.0, (float)$db->getOwnerFinancials($facilityId)['available'], 'The requested amount is no longer available');
        $secondPayout = $db->createPayoutRequest($ownerId, (string)$facilityId, 1.0, 'GCash', 'test');
        $this->assertFalse((bool)($secondPayout['success'] ?? true), 'REGRESSION: the same earnings cannot be requested twice');

        // ── A session that has already started cannot be cancelled ─────────
        $nowMin = ((int)date('G') * 60) + (int)date('i');
        $startMin = max(0, $nowMin - 30);
        $endMin = min(1439, $nowMin + 30);
        $liveId = "test_harden_live_{$tag}";
        $db->insertBooking([
            'id' => $liveId, 'user_id' => $booker, 'facility_id' => $facilityId, 'court_id' => (string)$courts['Court 3']['id'],
            'facility_name' => "Hardening Facility {$tag}", 'court_name' => 'Court 3', 'date' => date('Y-m-d'),
            'time' => date('g:i A', strtotime('today') + $startMin * 60) . ' - ' . date('g:i A', strtotime('today') + $endMin * 60),
            'booking_date' => date('Y-m-d'), 'start_min' => $startMin, 'end_min' => $endMin,
            'duration' => 1, 'price' => 440.0, 'payment_method' => 'Pay at Venue', 'status' => 'confirmed',
        ]);
        $lateCancel = $db->cancelBooking($liveId, $booker);
        $this->assertFalse((bool)($lateCancel['success'] ?? true), 'REGRESSION: a booking already in play cannot be cancelled');

        // ── Reading the wallet ledger never writes to it ───────────────────
        $legacy = $makeUser('legacy', 250.0);
        $first = $db->getWalletTransactions($legacy);
        $second = $db->getWalletTransactions($legacy);
        $labels = array_column($second, 'label');
        $this->assertTrue(in_array('Opening Balance', array_column($first, 'label'), true), 'An unexplained balance is shown as an opening balance');
        $this->assertTrue(in_array('Opening Balance', $labels, true) && !in_array('GCash Top-Up', $labels, true),
            'REGRESSION: no fabricated "GCash Top-Up" is persisted by viewing the wallet');

        // ── The cancel dialog's refund promise matches cancelBooking() ─────
        $promoBookingId = (string)$promoJoin['booking']['id'];
        $promoBooking = $db->getBookingById($promoBookingId);
        $this->assertTrue($db->isFullRefundEligible($promoBooking), 'A Pickle Credits booking days away is shown as fully refundable');
        $this->assertFalse($db->isFullRefundEligible($db->getBookingById((string)$reservation['booking']['id'])),
            'A Pay at Venue booking is never promised a refund');
        $imminent = $promoBooking;
        $imminent['status'] = 'confirmed';
        $imminent['booking_date'] = date('Y-m-d');
        $imminent['start_min'] = min(1439, $nowMin + 60);
        $imminent['end_min'] = min(1439, $nowMin + 120);
        $this->assertFalse($db->isFullRefundEligible($imminent), 'A Pickle Credits booking within 24 hours is not promised a refund');

        $promoCancel = $db->cancelBooking($promoBookingId, $promoPlayer);
        $this->assertEquals(90.0, (float)($promoCancel['refund_amount'] ?? 0), 'Cancelling the eligible booking refunds exactly what was charged');
        $this->assertEquals(500.0, (float)($db->getUserById($promoPlayer)['wallet_balance'] ?? 0), "The promo player's wallet is made whole");

        $gcashId = "test_harden_gcash_{$tag}";
        $gcashDate = date('Y-m-d', strtotime('+3 days'));
        $db->insertBooking([
            'id' => $gcashId, 'user_id' => $booker, 'facility_id' => $facilityId, 'court_id' => (string)$courts['Court 4']['id'],
            'facility_name' => "Hardening Facility {$tag}", 'court_name' => 'Court 4', 'date' => $gcashDate,
            'time' => '10:00 AM - 11:00 AM', 'booking_date' => $gcashDate, 'start_min' => 600, 'end_min' => 660,
            'duration' => 1, 'price' => 440.0, 'payment_method' => 'GCash', 'status' => 'pending',
        ]);
        $gcashCancel = $db->cancelBooking($gcashId, $booker);
        $gcashMessage = (string)($gcashCancel['message'] ?? '');
        $this->assertTrue((bool)($gcashCancel['success'] ?? false), 'A GCash booking days away can be cancelled');
        $this->assertTrue(str_contains($gcashMessage, 'GCash') && !str_contains($gcashMessage, 'within 24 hours'),
            'REGRESSION: a GCash cancellation days ahead is not told it was "within 24 hours"');

        // ── A phone number is one identity, however it is typed ────────────
        $phoneDigits = str_pad((string)random_int(0, 9999999), 7, '0', STR_PAD_LEFT);
        $canonicalPhone = '+63 917 ' . substr($phoneDigits, 0, 3) . ' ' . substr($phoneDigits, 3);
        $this->assertSame($canonicalPhone, \Picklers\Helpers\Format::phMobile('0917' . $phoneDigits), 'A local 09… number normalizes to the stored format');
        $this->assertSame($canonicalPhone, \Picklers\Helpers\Format::phMobile('+63917' . $phoneDigits), 'An international +639… number normalizes to the stored format');
        $this->assertNull(\Picklers\Helpers\Format::phMobile('player@example.test'), 'An email address is not treated as a phone number');
        $phoneUser = $makeUser('phone');
        $db->updateUser($phoneUser, ['phone' => $canonicalPhone]);
        $this->assertSame($phoneUser, (string)($db->getUserByEmailOrPhone('0917' . $phoneDigits)['id'] ?? ''),
            'REGRESSION: signing in with 09… finds the account stored as +63 9xx xxx xxxx');

        // Tidy the court fixtures' remaining session.
        $db->cancelOpenPlaySessions('', $facilityId, 'Court 10');
    }
}
