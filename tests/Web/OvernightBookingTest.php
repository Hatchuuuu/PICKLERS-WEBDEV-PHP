<?php
declare(strict_types=1);

namespace Picklers\Tests\Web;

use PHPUnit\Framework\TestCase;
use Picklers\Domain\BookingRules;

final class OvernightBookingTest extends TestCase
{
    public function testOvernightBookingStaysActiveUntilItsFollowingDayEnd(): void
    {
        $booking = ['status' => 'confirmed', 'booking_date' => '2026-10-21', 'time' => '11:00 PM - 1:00 AM', 'end_min' => 1500];
        self::assertFalse(BookingRules::isBookingPast($booking, new \DateTimeImmutable('2026-10-22 00:30:00')));
        self::assertTrue(BookingRules::isBookingPast($booking, new \DateTimeImmutable('2026-10-22 01:00:00')));
        unset($booking['end_min']);
        self::assertFalse(BookingRules::isBookingPast($booking, new \DateTimeImmutable('2026-10-22 00:30:00')));
        self::assertTrue(BookingRules::isBookingPast($booking, new \DateTimeImmutable('2026-10-22 01:00:00')));
    }

    public function testOrdinaryDatesEarlyEndAndCancellationKeepTheirMeaning(): void
    {
        $now = new \DateTimeImmutable('2026-10-22 12:00:00');
        $booking = ['status' => 'confirmed', 'booking_date' => '2026-10-22', 'end_min' => 720];
        self::assertTrue(BookingRules::isBookingPast($booking, $now));
        self::assertFalse(BookingRules::isBookingPast(array_replace($booking, ['end_min' => 780]), $now));
        self::assertTrue(BookingRules::isBookingPast(array_replace($booking, ['booking_date' => '2026-10-21']), $now));
        self::assertFalse(BookingRules::isBookingPast(array_replace($booking, ['booking_date' => '2026-10-23']), $now));
        self::assertTrue(BookingRules::isBookingPast(array_replace($booking, ['end_min' => 780, 'ended_early' => 1]), $now));
        self::assertFalse(BookingRules::isBookingPast(array_replace($booking, ['status' => 'cancelled']), $now));
    }
}
