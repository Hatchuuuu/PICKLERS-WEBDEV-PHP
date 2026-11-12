<?php
declare(strict_types=1);

namespace Picklers\Tests;

/** Tests facility operating hours calculations without the wall clock or database. */
final class FacilityOperatingHoursTest extends TestCase {
    public function run(): void {
        $bookingRepository = (new \ReflectionClass(\Picklers\Repositories\BookingRepository::class))->newInstanceWithoutConstructor();
        foreach ([
            ['24/hrs', '01:30', true],
            ['24 Hours', '23:59', true],
            ['', '01:30', true],
            [null, '01:30', true],
            ['1:00 AM - 2:00 AM', '00:59', false],
            ['1:00 AM - 2:00 AM', '01:00', true],
            ['1:00 AM - 2:00 AM', '01:30', true],
            ['1:00 AM - 2:00 AM', '02:00', false],
            ['6:00 AM - 10:00 PM', '05:59', false],
            ['6:00 AM - 10:00 PM', '06:00', true],
            ['6:00 AM - 10:00 PM', '21:59', true],
            ['6:00 AM - 10:00 PM', '22:00', false],
            ['10:00 PM - 2:00 AM', '21:59', false],
            ['10:00 PM - 2:00 AM', '22:00', true],
            ['10:00 PM - 2:00 AM', '23:59', true],
            ['10:00 PM - 2:00 AM', '00:00', true],
            ['10:00 PM - 2:00 AM', '01:59', true],
            ['10:00 PM - 2:00 AM', '02:00', false],
            ['6:00 AM - 6:00 AM', '12:00', true],
        ] as [$hours, $time, $expected]) {
            $now = new \DateTimeImmutable('2026-10-12 ' . $time);
            $this->assertSame($expected, $bookingRepository->isFacilityOpen($hours, $now), ($hours ?? 'null') . ' at ' . $time);
        }
    }
}
