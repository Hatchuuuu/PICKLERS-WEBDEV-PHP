<?php
declare(strict_types=1);

namespace Picklers\Tests\Web;

use Picklers\Tests\Admin\AdminWebTestCase;

final class BookingCancellationDisplayTest extends AdminWebTestCase
{
    public function testFutureConfirmedBookingOffersCancellationAndStartedBookingDoesNot(): void
    {
        $this->signIn('usr_fx_p1');
        $crawler = $this->client->request('GET', '/app.php', ['tab' => 'bookings']);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('button[onclick*="promptCancelBooking"]')->reduce(fn($node) => str_contains($node->attr('onclick'), 'FX-CONF01')));

        $this->db()->update('bookings', ['date' => date('D, M j, Y'), 'booking_date' => date('Y-m-d'), 'time' => '12:00 AM - 11:59 PM', 'start_min' => 0, 'end_min' => 1439], ['id' => 'FX-CONF01']);
        $crawler = $this->client->request('GET', '/app.php', ['tab' => 'bookings']);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('button[onclick*="promptCancelBooking"]')->reduce(fn($node) => str_contains($node->attr('onclick'), 'FX-CONF01')));
    }
}
