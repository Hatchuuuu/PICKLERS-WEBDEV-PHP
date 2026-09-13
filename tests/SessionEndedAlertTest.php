<?php
declare(strict_types=1);

namespace Picklers\Tests;

use Picklers\Core\Database;

/**
 * Covers the real-time "your time is up" alert: Database::checkAndNotifySessionEnd(),
 * polled via action=sync (ApiController) roughly every 12s while the player app is
 * open (see PickSync.onSessionEnded() in ux-core.js / app.js). A booking whose end
 * time has passed must fire exactly once — a real notification is created and the
 * booking is flagged so the next poll (and the one after that) doesn't re-fire it.
 */
final class SessionEndedAlertTest extends TestCase {

    public function run(): void {
        $db = Database::get();
        $facilityId = 3;
        $courts = $db->getCourtsByFacility($facilityId);
        $court = $courts[0];

        $allUsers = $db->getAllUsers();
        $this->assertTrue(count($allUsers) > 0, 'At least one seeded user exists to book as');
        $userId = (string)($allUsers[0]['id'] ?? '');

        $notifsBefore = count($db->getNotifications($userId));

        // A booking that ended a few minutes ago, today.
        $nowMin   = ((int)date('G') * 60) + (int)date('i');
        $startMin = max(0, $nowMin - 70);
        $endMin   = max(1, $nowMin - 10);
        $startLabel = date('g:i A', strtotime('today') + $startMin * 60);
        $endLabel   = date('g:i A', strtotime('today') + $endMin * 60);

        $bookingId = 'test_sea_' . bin2hex(random_bytes(4));
        $db->insertBooking([
            'id' => $bookingId,
            'user_id' => $userId,
            'facility_id' => $facilityId,
            'court_id' => (string)($court['id'] ?? ''),
            'facility_name' => 'Incredoball Sports Center',
            'court_name' => (string)($court['name'] ?? 'Court 1'),
            'date' => date('Y-m-d'),
            'time' => "{$startLabel} - {$endLabel}",
            'booking_date' => date('Y-m-d'),
            'start_min' => $startMin,
            'end_min' => $endMin,
            'duration' => 1,
            'price' => 250.0,
            'payment_method' => 'GCash',
            'status' => 'confirmed',
        ]);

        // First check: this is the one live poll that actually catches it.
        $alerts = $db->checkAndNotifySessionEnd($userId);
        $found = null;
        foreach ($alerts as $a) {
            if (($a['booking_id'] ?? '') === $bookingId) { $found = $a; break; }
        }
        $this->assertNotNull($found, 'A just-ended booking is returned on the poll that catches it');
        $this->assertSame((string)($court['name'] ?? 'Court 1'), $found['court_name'] ?? null, 'Alert carries the real court name');

        $updated = $db->getBookingById($bookingId);
        $this->assertNotNull($updated, 'Booking still exists after the alert fires');
        $this->assertTrue(!empty($updated['end_alert_sent']), 'Booking is flagged end_alert_sent so it is not re-alerted');
        $this->assertSame('confirmed', (string)($updated['status'] ?? ''), 'Firing the alert does not change the booking status');

        $notifsAfter = count($db->getNotifications($userId));
        $this->assertSame($notifsBefore + 1, $notifsAfter, 'Exactly one real notification is created for the alert');

        // Second check, simulating the next ~12s poll: must not fire again.
        $alertsAgain = $db->checkAndNotifySessionEnd($userId);
        $foundAgain = false;
        foreach ($alertsAgain as $a) {
            if (($a['booking_id'] ?? '') === $bookingId) { $foundAgain = true; break; }
        }
        $this->assertFalse($foundAgain, 'A later poll does not re-alert the same booking');

        $notifsFinal = count($db->getNotifications($userId));
        $this->assertSame($notifsAfter, $notifsFinal, 'No duplicate notification is created on the later poll');

        // Leave no trace for later suite runs.
        $db->updateBookingStatus($bookingId, 'cancelled');
    }
}
