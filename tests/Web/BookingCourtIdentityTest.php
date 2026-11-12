<?php
declare(strict_types=1);

namespace Picklers\Tests\Web;

use Picklers\Tests\Admin\AdminWebTestCase;

final class BookingCourtIdentityTest extends AdminWebTestCase
{
    private function book(array $params): array
    {
        $this->signIn('usr_fx_p1');
        $_SESSION['_sf2_attributes']['_csrf/picklers'] = 'court-test';
        $this->client->request('POST', '/api.php?action=book_court', $params, [], ['HTTP_X_CSRF_TOKEN' => 'court-test']);
        return json_decode((string)$this->client->getResponse()->getContent(), true);
    }

    public function testUnknownCourtCannotChargeAndLegacyNameResolvesToRealIdentity(): void
    {
        $facility = (string)$this->scalar("SELECT id FROM facilities WHERE owner_id = 'usr_fx_owner1' LIMIT 1");
        $base = ['facility_id' => $facility, 'date' => date('Y-m-d', strtotime('+10 days')), 'time' => '9:00 AM - 10:00 AM', 'duration' => 1, 'payment_method' => 'Pickle Credits'];
        $before = $this->scalar("SELECT wallet_balance FROM users WHERE id = 'usr_fx_p1'");
        $count = $this->scalar("SELECT COUNT(*) FROM bookings WHERE user_id = 'usr_fx_p1'");
        foreach ([['court_name' => 'Nonexistent Court'], ['court_id' => 'missing-court', 'court_name' => 'Court 1']] as $court) {
            self::assertFalse($this->book($base + $court)['success']);
            self::assertSame($before, $this->scalar("SELECT wallet_balance FROM users WHERE id = 'usr_fx_p1'"));
            self::assertSame($count, $this->scalar("SELECT COUNT(*) FROM bookings WHERE user_id = 'usr_fx_p1'"));
        }
        $valid = $this->book($base + ['court_name' => 'Court 1']);
        self::assertTrue($valid['success'], json_encode($valid));
        self::assertSame('crt_' . $facility . '_1', $valid['booking']['court_id']);
        self::assertFalse($this->book($base + ['court_id' => 'crt_' . $facility . '_1', 'court_name' => 'Untrusted label'])['success']);
    }

    public function testEndedEarlyReservationDoesNotBlockRemainingSlots(): void
    {
        $facility = (string)$this->scalar("SELECT id FROM facilities WHERE owner_id = 'usr_fx_owner1' LIMIT 1");
        $day = date('Y-m-d', strtotime('+10 days'));
        $court = 'crt_' . $facility . '_1';
        $this->db()->insert('bookings', ['id' => 'FX-EARLY', 'user_id' => 'usr_fx_p1', 'facility_id' => $facility, 'court_id' => $court, 'court_name' => 'Court 1', 'date' => $day, 'time' => '2:00 PM - 5:00 PM', 'booking_date' => $day, 'start_min' => 840, 'end_min' => 1020, 'duration' => 3, 'price' => 900, 'payment_method' => 'GCash', 'status' => 'confirmed', 'ended_early' => 1]);
        $repository = static::getContainer()->get(\Picklers\Repositories\BookingRepository::class);
        $slot = array_values(array_filter($repository->getSlotAvailability($facility, $court, $day), fn($s) => $s['start_min'] === 900))[0];
        self::assertTrue($slot['available']);
        self::assertTrue($this->book(['facility_id' => $facility, 'court_id' => $court, 'date' => $day, 'time' => '3:00 PM - 4:00 PM', 'duration' => 1, 'payment_method' => 'Pickle Credits'])['success']);
        self::assertSame('confirmed', $this->scalar("SELECT status FROM bookings WHERE id = 'FX-EARLY'"));
        self::assertSame('900.00', $this->scalar("SELECT price FROM bookings WHERE id = 'FX-EARLY'"));
    }
}
