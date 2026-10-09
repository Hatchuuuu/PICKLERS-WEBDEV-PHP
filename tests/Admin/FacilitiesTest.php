<?php
declare(strict_types=1);

namespace Picklers\Tests\Admin;

use Picklers\Core\Database;

/** R03 — facilities & courts: real aggregates, governed edits, availability enforcement. */
final class FacilitiesTest extends AdminWebTestCase
{
    private int $fac1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signIn('usr_fx_admin');
        $this->fac1 = (int)$this->scalar("SELECT id FROM facilities WHERE name = 'Fixture Arena Dumaguete'");
    }

    private function futureDate(int $days = 10): string
    {
        return (new \DateTimeImmutable("+{$days} days"))->format('D, M j, Y');
    }

    public function testListShowsRealAggregatesNotDefaults(): void
    {
        $this->client->request('GET', '/admin/panel/facilities', ['q' => 'Fixture Courts Valencia']);
        self::assertResponseIsSuccessful();
        $html = (string)$this->client->getResponse()->getContent();
        self::assertStringContainsString('No reviews', $html, 'zero-review facility shows "No reviews", not an invented rating');
        self::assertStringContainsString('₱250.00', $html, 'rate comes from its single court');
        self::assertStringNotContainsString('4.9', $html);
        $this->client->request('GET', '/admin/detail/facility/' . $this->fac1);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#fac-courts', 'Courts (3)');
    }

    public function testSuspensionNeedsReasonAndBlocksEveryBookingChannel(): void
    {
        $r = $this->action('admin_set_facility_status', ['facility_id' => $this->fac1, 'status' => 'suspended', 'reason' => '']);
        self::assertSame(422, $r['status']);

        $upcomingBefore = (int)$this->scalar("SELECT COUNT(*) FROM bookings WHERE facility_id = ? AND status IN ('pending','confirmed')", [$this->fac1]);
        $r = $this->action('admin_set_facility_status', ['facility_id' => $this->fac1, 'status' => 'suspended', 'reason' => 'Safety inspection pending']);
        self::assertSame(200, $r['status'], json_encode($r['json']));
        self::assertNotEmpty($r['json']['affected_bookings'], 'existing bookings are listed for explicit handling');
        self::assertSame($upcomingBefore, (int)$this->scalar("SELECT COUNT(*) FROM bookings WHERE facility_id = ? AND status IN ('pending','confirmed')", [$this->fac1]), 'and are not cancelled');

        $db = Database::get();
        $db->invalidateReadCache();
        // Channel 1: player court booking.
        $res = $db->createBooking('usr_fx_p1', $this->fac1, 'Court 1', $this->futureDate(), '8:00 AM - 9:00 AM', 1, 300.0, 'GCash', 'crt_' . $this->fac1 . '_1');
        self::assertFalse($res['success']);
        self::assertStringContainsString('temporarily unavailable', $res['message']);
        // Channel 2: Open Play join.
        self::pdo()->exec("DELETE FROM matches WHERE id = 'FX-MATCH1'");
        self::pdo()->prepare("INSERT INTO matches (id, facility_id, facility_name, location, date, time, level, current_players, max_players, price, type, host) VALUES ('FX-MATCH1', ?, 'Fixture Arena Dumaguete', 'x', ?, '6:00 PM - 8:00 PM', 'Intermediate', 0, 4, 150, 'Court 2', 'Host')")
            ->execute([$this->fac1, $this->futureDate(3)]);
        $join = $db->joinMatch('FX-MATCH1', 'usr_fx_p2', 'GCash');
        self::assertFalse($join['success']);
        self::assertStringContainsString('temporarily unavailable', $join['message']);
        // Channel 3: owner-hosted session guard + Discover listing.
        self::assertNotNull($db->bookingBlockReason($this->fac1, 'crt_' . $this->fac1 . '_2', 'Court 2'));
        self::assertNotContains($this->fac1, array_map(fn($f) => (int)$f['id'], $db->getFacilities()), 'suspended facilities leave Discover');
        self::assertSame('suspended', $this->lastAudit('facility.suspend') ? 'suspended' : null);

        // Reactivate restores booking.
        self::assertSame(200, $this->action('admin_set_facility_status', ['facility_id' => $this->fac1, 'status' => 'active', 'reason' => 'Inspection passed'])['status']);
        $db->invalidateReadCache();
        $ok = $db->createBooking('usr_fx_p1', $this->fac1, 'Court 1', $this->futureDate(), '8:00 AM - 9:00 AM', 1, 300.0, 'GCash', 'crt_' . $this->fac1 . '_1');
        self::assertTrue($ok['success'], json_encode($ok));
    }

    public function testCourtMaintenanceBlocksOnlyThatCourt(): void
    {
        $court2 = 'crt_' . $this->fac1 . '_2';
        $r = $this->action('admin_update_court_status', ['court_id' => $court2, 'status' => 'maintenance', 'reason' => 'Resurfacing the court']);
        self::assertSame(200, $r['status'], json_encode($r['json']));
        $db = Database::get();
        $db->invalidateReadCache();
        $blocked = $db->createBooking('usr_fx_p1', $this->fac1, 'Court 2', $this->futureDate(12), '9:00 AM - 10:00 AM', 1, 350.0, 'GCash', $court2);
        self::assertFalse($blocked['success']);
        self::assertStringContainsString('maintenance', $blocked['message']);
        $open = $db->createBooking('usr_fx_p1', $this->fac1, 'Court 1', $this->futureDate(12), '9:00 AM - 10:00 AM', 1, 300.0, 'GCash', 'crt_' . $this->fac1 . '_1');
        self::assertTrue($open['success'], json_encode($open));
        self::assertSame(422, $this->action('admin_update_court_status', ['court_id' => 'crt_' . $this->fac1 . '_1', 'status' => 'maintenance', 'reason' => ''])['status'], 'maintenance needs a reason');
        self::assertSame(422, $this->action('admin_update_court_status', ['court_id' => $court2, 'status' => 'exploded'])['status']);
        self::assertSame(404, $this->action('admin_update_court_status', ['court_id' => 'nope', 'status' => 'available'])['status']);
    }

    public function testGovernedDetailEdit(): void
    {
        self::assertSame(422, $this->action('admin_update_facility', ['facility_id' => $this->fac1, 'name' => 'X', 'reason' => 'Typo fix'])['status']);
        self::assertSame(422, $this->action('admin_update_facility', ['facility_id' => $this->fac1, 'hours' => 'all day', 'reason' => 'Typo fix'])['status']);
        $r = $this->action('admin_update_facility', ['facility_id' => $this->fac1, 'name' => 'Fixture Arena Dumaguete City', 'hours' => '6:00 AM - 10:00 PM', 'reason' => 'Owner requested rename']);
        self::assertSame(200, $r['status'], json_encode($r['json']));
        self::assertSame('Fixture Arena Dumaguete City', $this->scalar('SELECT name FROM facilities WHERE id = ?', [$this->fac1]));
        $audit = $this->lastAudit('facility.update');
        self::assertStringContainsString('Fixture Arena Dumaguete City', $audit['changes']);
        self::assertSame('Owner requested rename', $audit['reason']);
    }
}
