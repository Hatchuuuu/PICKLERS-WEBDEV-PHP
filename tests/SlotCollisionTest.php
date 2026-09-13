<?php
declare(strict_types=1);

namespace Picklers\Tests;

use Picklers\Core\Database;

/**
 * Guards double-booking detection.
 *
 * Regression origin: `time` is stored as a display string, and the collision
 * check compared it with `=`. "6:00 PM - 8:00 PM" and "7:00 PM - 9:00 PM" are
 * different strings, so both bookings succeeded on the same court.
 */
final class SlotCollisionTest extends TestCase {

    public function run(): void {
        $db = Database::get();

        // ── Range parsing ──────────────────────────────────────────────────
        $this->assertEquals([1080, 1200], $db->parseTimeRange('6:00 PM - 8:00 PM'),
            'A 12-hour range parses to minutes from midnight');
        $this->assertEquals([1080, 1200], $db->parseTimeRange('6:00 PM – 8:00 PM'),
            'An en-dash separator parses identically');
        $this->assertEquals([1080, 1200], $db->parseTimeRange('18:00 - 20:00'),
            'A 24-hour range parses identically');
        $this->assertEquals([540, 630], $db->parseTimeRange('9:00 AM - 10:30 AM'),
            'Half-hour boundaries parse correctly');
        $this->assertEquals([1380, 1500], $db->parseTimeRange('11:00 PM - 1:00 AM'),
            'A range crossing midnight is unwrapped past 1440');
        $this->assertNull($db->parseTimeRange('whenever'),
            'Unparseable text yields null rather than a bogus range');
        $this->assertNull($db->parseTimeRange('6:00 PM'),
            'A single time with no range yields null');

        // ── Overlap maths ──────────────────────────────────────────────────
        $this->assertTrue($db->rangesOverlap([1080, 1200], [1080, 1200]), 'Identical ranges overlap');
        $this->assertTrue($db->rangesOverlap([1080, 1200], [1140, 1440]), 'Partially offset ranges overlap');
        $this->assertTrue($db->rangesOverlap([1080, 1200], [1140, 1190]), 'A contained range overlaps');
        $this->assertFalse($db->rangesOverlap([1080, 1200], [1200, 1440]), 'Back-to-back ranges do NOT overlap');
        $this->assertFalse($db->rangesOverlap([540, 600], [1080, 1200]), 'Disjoint ranges do not overlap');

        // ── The actual regression ──────────────────────────────────────────
        $this->assertSame('7:00 PM - 9:00 PM',
            $db->findSlotConflict('6:00 PM - 8:00 PM', ['7:00 PM - 9:00 PM']),
            'REGRESSION: an overlapping-but-differently-worded slot is now caught');
        $this->assertNull(
            $db->findSlotConflict('6:00 PM - 8:00 PM', ['8:00 PM - 10:00 PM']),
            'An adjacent slot is still bookable');
        $this->assertSame('6:00 PM - 8:00 PM',
            $db->findSlotConflict('6:00 PM - 8:00 PM', ['6:00 PM - 8:00 PM']),
            'An exact duplicate still conflicts');
        $this->assertSame('6:00 PM - 8:00 PM',
            $db->findSlotConflict('6:30 PM - 7:30 PM', ['6:00 PM - 8:00 PM']),
            'A shorter slot inside an existing booking conflicts');
        $this->assertNull($db->findSlotConflict('6:00 PM - 8:00 PM', []),
            'An empty court has no conflict');
        $this->assertNull(
            $db->findSlotConflict('9:00 AM - 10:00 AM', ['6:00 PM - 8:00 PM', '8:00 PM - 10:00 PM']),
            'A morning slot is free against evening bookings');
        $this->assertSame('6:30 PM - 7:30 PM',
            $db->findSlotConflict('7:00 PM - 8:00 PM', ['9:00 AM - 10:00 AM', '6:30 PM - 7:30 PM']),
            'The conflicting slot is found among several bookings');

        // ── Unparseable data must fail safe, never silently allow ──────────
        $this->assertSame('TBD', $db->findSlotConflict('TBD', ['TBD']),
            'Unparseable slots fall back to exact-match so a clash is not missed');
        $this->assertNull($db->findSlotConflict('6:00 PM - 8:00 PM', ['TBD']),
            'An unparseable existing slot with a different string does not block');

        // ── Integration: createBooking() itself enforces this across DATE
        // spellings, not just time-string wording (a second, independent
        // regression from the same root cause). validateBookingSlot() always
        // resolved a canonical date, but createBooking()'s lock query then
        // compared the client's RAW display string instead of that resolved
        // value — so "Tomorrow" and tomorrow's own "D, M j, Y" spelling for
        // the exact same real day never collided, and a real double-booking
        // (PKL-59B129 / PKL-3BD361, same court, same day, overlapping times)
        // reached the live database before this fix. Late-night hours here
        // are deliberate: they must stay valid no matter what hour of the
        // day this suite itself happens to run at. ──────────────────────
        $facility = $db->getFacilities()[0] ?? null;
        $this->assertNotNull($facility, 'At least one seeded facility exists to run this integration check against');

        if ($facility) {
            $facilityId = $facility['id'];
            $tester = $db->createUser([
                'id' => 'usr_test_slot_' . bin2hex(random_bytes(4)),
                'name' => 'Slot Collision Tester',
                'email' => 'slot_test_' . bin2hex(random_bytes(4)) . '@example.test',
                'password_hash' => password_hash('x', PASSWORD_DEFAULT),
            ]);
            // A disposable court of its own, so this never collides with
            // seeded data or with a previous run of this same test.
            $court = $db->insertCourt([
                'facility_id' => $facilityId,
                'name'        => 'Court ' . random_int(5000, 99999),
                'price'       => 100.00,
                'status'      => 'available',
            ]);

            $tomorrowSpelledOut = date('D, M j, Y', strtotime('+1 day'));

            $first = $db->createBooking(
                $tester['id'], $facilityId, $court['name'], 'Tomorrow', '10:00 PM - 11:00 PM',
                1, 100.0, 'Pay at Venue', (string)$court['id']
            );
            $this->assertTrue((bool)($first['success'] ?? false),
                'The first booking on a free court/slot succeeds: ' . ($first['message'] ?? ''));

            // ── REGRESSION: the facility owner is notified of a new booking,
            // not just the player who made it (F-24 — createBooking() used
            // to tell only the booker; the owner's only way to learn of a
            // reservation was noticing it in the dashboard queue) ──────────
            $ownerId = $db->getFacilityOwnerId($facilityId);
            if ($ownerId !== null) {
                $latestOwnerNotif = $db->getNotifications($ownerId)[0] ?? null;
                $this->assertNotNull($latestOwnerNotif,
                    'The facility owner has at least one notification after a booking is created');
                $this->assertTrue(
                    stripos((string)($latestOwnerNotif['title'] ?? ''), 'reservation') !== false,
                    'REGRESSION: that notification announces the new reservation, not something unrelated'
                );
            }

            $second = $db->createBooking(
                $tester['id'], $facilityId, $court['name'], $tomorrowSpelledOut, '10:30 PM - 11:30 PM',
                1, 100.0, 'Pay at Venue', (string)$court['id']
            );
            $this->assertFalse((bool)($second['success'] ?? true),
                "REGRESSION: an overlapping slot on the SAME real day, spelled differently "
                . "('Tomorrow' vs '{$tomorrowSpelledOut}'), is now correctly rejected");

            $third = $db->createBooking(
                $tester['id'], $facilityId, $court['name'], $tomorrowSpelledOut, '11:30 PM - 11:59 PM',
                1, 100.0, 'Pay at Venue', (string)$court['id']
            );
            $this->assertTrue((bool)($third['success'] ?? false),
                'A genuinely free slot later the same day still books fine: ' . ($third['message'] ?? ''));

            // ── Dynamic real-time court status verification ──────────────────────
            $courtList = $db->getCourtsByFacility($facilityId);
            $testCourtRow = null;
            foreach ($courtList as $cRow) {
                if ((string)($cRow['id'] ?? '') === (string)$court['id']) {
                    $testCourtRow = $cRow;
                    break;
                }
            }
            $this->assertNotNull($testCourtRow, 'Test court exists in facility listing');
            $this->assertSame('available', strtolower((string)($testCourtRow['status'] ?? '')),
                'A court with a booking later in the day remains AVAILABLE at the current real time');

            $slots = $db->getSlotAvailability($facilityId, (string)$court['id'], date('Y-m-d', strtotime('+1 day')));
            $this->assertTrue(!empty($slots), 'getSlotAvailability returns slots for the court');
            $bookedSlot = null;
            foreach ($slots as $s) {
                if (!$s['available'] && $s['reason'] === 'booked') {
                    $bookedSlot = $s;
                    break;
                }
            }
            if ($bookedSlot) {
                $this->assertFalse($bookedSlot['available'], 'The booked slot is correctly reported as unavailable');
                $this->assertSame('booked', $bookedSlot['reason'], 'The reason for unavailability is booked');
            }

            // Leave no state behind — the next run reuses a fresh random court.
            if (!empty($first['booking']['id']))  $db->cancelBooking($first['booking']['id'], $tester['id']);
            if (!empty($third['booking']['id']))  $db->cancelBooking($third['booking']['id'], $tester['id']);
        }
    }
}
