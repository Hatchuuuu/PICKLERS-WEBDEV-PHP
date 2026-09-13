<?php
declare(strict_types=1);

namespace Picklers\Tests;

use Picklers\Core\Database;

/**
 * Covers the "End Session Early" fix: ending a live, real (non-Open-Play)
 * booking must actually free the court on the very next read, must not
 * touch price/status/refund, and must move the booking into the player's
 * "Completed" bucket immediately rather than waiting out its original,
 * un-shortened end time.
 */
final class EndCourtSessionEarlyTest extends TestCase {

    public function run(): void {
        $db = Database::get();
        $facilityId = 3; // Incredoball Sports Center — reused by OpenPlayAndSettingsSyncTest
        $courts = $db->getCourtsByFacility($facilityId);
        $this->assertTrue(count($courts) > 0, 'Facility 3 has at least one court to test against');
        $court = $courts[0];
        $courtId = (string)($court['id'] ?? '');
        $courtName = (string)($court['name'] ?? 'Court 1');

        // A booking whose window covers right now, regardless of when this
        // suite happens to run. createBooking() itself refuses anything not
        // in the future (correctly, for a real player) so a "currently in
        // progress" fixture has to go in through insertBooking() directly —
        // the same lower-level path joinMatch() already uses.
        $nowMin     = ((int)date('G') * 60) + (int)date('i');
        $startMin   = max(0, $nowMin - 10);
        $endMin     = min(1439, $nowMin + 50);
        $startLabel = date('g:i A', strtotime('today') + $startMin * 60);
        $endLabel   = date('g:i A', strtotime('today') + $endMin * 60);
        $timeRange  = "{$startLabel} - {$endLabel}";

        $allUsers = $db->getAllUsers();
        $this->assertTrue(count($allUsers) > 0, 'At least one seeded user exists to book as');
        $userId = (string)($allUsers[0]['id'] ?? '');

        $bookingId = 'test_ese_' . bin2hex(random_bytes(4));
        $db->insertBooking([
            'id' => $bookingId,
            'user_id' => $userId,
            'facility_id' => $facilityId,
            'court_id' => $courtId,
            'facility_name' => 'Incredoball Sports Center',
            'court_name' => $courtName,
            'date' => date('Y-m-d'),
            'time' => $timeRange,
            'booking_date' => date('Y-m-d'),
            'start_min' => $startMin,
            'end_min' => $endMin,
            'duration' => 1,
            'price' => 300.0,
            'payment_method' => 'GCash',
            'status' => 'confirmed',
        ]);

        $beforeCourts = $db->getCourtsByFacility($facilityId);
        $beforeCard = null;
        foreach ($beforeCourts as $c) {
            if ((string)($c['id'] ?? '') === $courtId) { $beforeCard = $c; break; }
        }
        $this->assertNotNull($beforeCard, 'Court is present in the facility court list');
        $this->assertSame('occupied', strtolower((string)($beforeCard['status'] ?? '')), 'Court shows occupied while the booking\'s original window is active');

        $endResult = $db->endCourtSessionEarly($facilityId, $courtId, $courtName);
        $this->assertTrue($endResult['success'] ?? false, 'endCourtSessionEarly() finds and ends the active booking: ' . ($endResult['message'] ?? ''));

        $afterCourts = $db->getCourtsByFacility($facilityId);
        $afterCard = null;
        foreach ($afterCourts as $c) {
            if ((string)($c['id'] ?? '') === $courtId) { $afterCard = $c; break; }
        }
        $this->assertNotNull($afterCard, 'Court is still present after ending the session');
        $this->assertSame('available', strtolower((string)($afterCard['status'] ?? '')), 'Court is free immediately — no waiting for the original end time');

        $updatedBooking = $db->getBookingById($bookingId);
        $this->assertNotNull($updatedBooking, 'Ended booking still exists');
        $this->assertSame('confirmed', (string)($updatedBooking['status'] ?? ''), 'Status is untouched — this is not a cancellation');
        $this->assertEquals(300.0, (float)($updatedBooking['price'] ?? 0), 'Price is untouched — no refund for ending early');
        $this->assertTrue(!empty($updatedBooking['ended_early']), 'Booking is flagged ended_early');
        $this->assertTrue($db->isBookingPast($updatedBooking), 'isBookingPast() reports it as past immediately, not at its original end time');

        // Ending an already-ended (or otherwise inactive) court is a clean,
        // reported failure — never a silent no-op mistaken for success.
        $secondAttempt = $db->endCourtSessionEarly($facilityId, $courtId, $courtName);
        $this->assertFalse($secondAttempt['success'] ?? true, 'A second call finds nothing active left to end');

        // Leave no trace for later suite runs.
        $db->updateBookingStatus($bookingId, 'cancelled');
    }
}
