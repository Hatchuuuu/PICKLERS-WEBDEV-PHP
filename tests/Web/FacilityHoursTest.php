<?php
declare(strict_types=1);

namespace Picklers\Tests\Web;

use Picklers\Tests\Admin\AdminWebTestCase;
use Picklers\Repositories\BookingRepository;
use Picklers\Repositories\MatchRepository;
use Picklers\Repositories\OpenPlayRepository;

final class FacilityHoursTest extends AdminWebTestCase
{
    private function venue(): array
    {
        return $this->db()->fetchAssociative("SELECT c.id court_id, c.name court_name, f.id facility_id, f.name facility_name FROM courts c JOIN facilities f ON f.id = c.facility_id WHERE f.owner_id = 'usr_fx_owner1' ORDER BY c.id LIMIT 1");
    }

    public function testAvailabilityAndReservationUseCurrentConfiguredHours(): void
    {
        $v = $this->venue();
        $repo = static::getContainer()->get(BookingRepository::class);
        $this->db()->update('facilities', ['hours' => '9am - 5pm'], ['id' => $v['facility_id']]);
        $day = date('Y-m-d', strtotime('+30 days'));
        $slots = array_column($repo->getSlotAvailability($v['facility_id'], $v['court_id'], $day), null, 'start_min');
        self::assertFalse($slots[420]['available']);
        self::assertTrue($slots[540]['available']);
        self::assertTrue($slots[960]['available']);
        self::assertFalse($slots[1020]['available']);
        $before = $this->scalar("SELECT wallet_balance FROM users WHERE id = 'usr_fx_p1'");
        $blocked = $repo->createBooking('usr_fx_p1', $v['facility_id'], $v['court_name'], $day, '8:00 PM - 9:00 PM', 1, 500, 'Pickle Credits', $v['court_id']);
        self::assertFalse($blocked['success']);
        self::assertStringContainsString('operating hours', $blocked['message']);
        self::assertSame($before, $this->scalar("SELECT wallet_balance FROM users WHERE id = 'usr_fx_p1'"));
        // A previously displayed valid slot must be rechecked after the owner changes hours.
        $this->db()->update('facilities', ['hours' => '10am - 5pm'], ['id' => $v['facility_id']]);
        self::assertFalse($repo->createBooking('usr_fx_p1', $v['facility_id'], $v['court_name'], $day, '9:00 AM - 10:00 AM', 1, 500, 'Pickle Credits', $v['court_id'])['success']);
        self::assertTrue($repo->createBooking('usr_fx_p1', $v['facility_id'], $v['court_name'], $day, '10:00 AM - 11:00 AM', 1, 500, 'Pay at Venue', $v['court_id'])['success']);
    }

    public function testOvernightReservationAndLegacyHours(): void
    {
        $v = $this->venue();
        $repo = static::getContainer()->get(BookingRepository::class);
        $this->db()->update('facilities', ['hours' => '9pm - 2am'], ['id' => $v['facility_id']]);
        $day = date('Y-m-d', strtotime('+30 days'));
        self::assertTrue($repo->createBooking('usr_fx_p1', $v['facility_id'], $v['court_name'], $day, '11:00 PM - 1:00 AM', 2, 500, 'Pay at Venue', $v['court_id'])['success']);
        self::assertFalse($repo->createBooking('usr_fx_p1', $v['facility_id'], $v['court_name'], $day, '1:00 AM - 3:00 AM', 2, 500, 'Pay at Venue', $v['court_id'])['success']);
        $this->db()->update('facilities', ['hours' => 'By appointment'], ['id' => $v['facility_id']]);
        self::assertTrue($repo->createBooking('usr_fx_p1', $v['facility_id'], $v['court_name'], $day, '3:00 AM - 4:00 AM', 1, 500, 'Pay at Venue', $v['court_id'])['success']);
    }

    public function testOpenPlayCannotJoinOrPublishOutsideHours(): void
    {
        $v = $this->venue();
        $this->db()->update('facilities', ['hours' => '9am - 5pm'], ['id' => $v['facility_id']]);
        $day = date('Y-m-d', strtotime('+30 days'));
        $match = static::getContainer()->get(MatchRepository::class)->insertMatch([
            'facility_id' => $v['facility_id'], 'facility_name' => $v['facility_name'], 'type' => $v['court_name'],
            'date' => $day, 'time' => '8:00 PM - 9:00 PM', 'price' => 100, 'max_players' => 4,
        ]);
        try {
            $before = $this->scalar("SELECT wallet_balance FROM users WHERE id = 'usr_fx_p1'");
            $join = static::getContainer()->get(OpenPlayRepository::class)->joinMatch($match['id'], 'usr_fx_p1', 'Pickle Credits');
            self::assertFalse($join['success']);
            self::assertStringContainsString('operating hours', $join['message']);
            self::assertSame($before, $this->scalar("SELECT wallet_balance FROM users WHERE id = 'usr_fx_p1'"));
            $this->signIn('usr_fx_owner1');
            $_SESSION['_sf2_attributes']['_csrf/picklers'] = 'hours-test';
            $this->client->request('POST', '/owner.php?action=host_open_play', [
                'facility_id' => $v['facility_id'], 'court_name' => $v['court_name'], 'date' => $day,
                'start_time' => '8:00 PM', 'end_time' => '9:00 PM', 'fee' => 100, 'capacity' => 4,
            ], [], ['HTTP_X_CSRF_TOKEN' => 'hours-test']);
            self::assertSame(409, $this->client->getResponse()->getStatusCode());
            self::assertStringContainsString('operating hours', (string)$this->client->getResponse()->getContent());
        } finally {
            $this->db()->delete('bookings', ['match_id' => $match['id']]);
            $this->db()->delete('matches', ['id' => $match['id']]);
        }
    }

    public function testAdjacentDayOverlapsAreBlockedInBothDirectionsIncludingLegacyOpenPlay(): void
    {
        $v = $this->venue();
        $repo = static::getContainer()->get(BookingRepository::class);
        $this->db()->update('facilities', ['hours' => '24 hours'], ['id' => $v['facility_id']]);
        $day = date('Y-m-d', strtotime('+30 days'));
        $next = date('Y-m-d', strtotime($day . ' +1 day'));
        $early = $repo->createBooking('usr_fx_p1', $v['facility_id'], $v['court_name'], $next, '12:00 AM - 1:00 AM', 1, 100, 'Pay at Venue', $v['court_id']);
        self::assertTrue($early['success']);
        self::assertFalse($repo->createBooking('usr_fx_p2', $v['facility_id'], $v['court_name'], $day, '11:00 PM - 1:00 AM', 2, 100, 'Pay at Venue', $v['court_id'])['success']);
        self::assertTrue($repo->cancelBooking($early['booking']['id'], 'usr_fx_p1')['success']);
        $late = $repo->createBooking('usr_fx_p1', $v['facility_id'], $v['court_name'], $day, '11:00 PM - 1:00 AM', 2, 100, 'Pay at Venue', $v['court_id']);
        self::assertTrue($late['success'], 'Cancelled adjacent booking does not block');
        // Open Play join rows have a canonical day but legacy nullable interval columns.
        $this->db()->update('bookings', ['court_id' => null, 'start_min' => null, 'end_min' => null], ['id' => $late['booking']['id']]);
        self::assertFalse($repo->createBooking('usr_fx_p2', $v['facility_id'], $v['court_name'], $next, '12:00 AM - 1:00 AM', 1, 100, 'Pay at Venue', $v['court_id'])['success']);
        $slots = array_column($repo->getSlotAvailability($v['facility_id'], $v['court_id'], $next), null, 'start_min');
        self::assertFalse($slots[0]['available']);
        self::assertTrue($repo->createBooking('usr_fx_p2', $v['facility_id'], $v['court_name'], $next, '1:00 AM - 2:00 AM', 1, 100, 'Pay at Venue', $v['court_id'])['success'], 'Touching endpoints remain free');
    }
}
