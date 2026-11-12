<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
date_default_timezone_set('Asia/Manila');

use Picklers\Domain\Schedule;

$cases = [
    ['9am - 5pm', '9:00 AM - 10:00 AM', true],
    ['9am - 5pm', '4:00 PM - 5:00 PM', true],
    ['9am - 5pm', '8:00 AM - 10:00 AM', false],
    ['9am - 5pm', '4:00 PM - 6:00 PM', false],
    ['9am - 5pm', '5:00 PM - 6:00 PM', false],
    ['9am - 5pm', '8:00 AM - 6:00 PM', false],
    ['9pm - 2am', '9:00 PM - 11:00 PM', true],
    ['9pm - 2am', '11:00 PM - 1:00 AM', true],
    ['9pm - 2am', '1:00 AM - 2:00 AM', true],
    ['9pm - 2am', '1:00 AM - 3:00 AM', false],
    ['9pm - 2am', '8:00 PM - 10:00 PM', false],
    ['9pm - 2am', '1:00 AM - 10:00 PM', false],
    ['24 hours', '11:00 PM - 1:00 AM', true],
    ['9am - 9am', '11:00 PM - 1:00 AM', true],
    ['', '3:00 AM - 4:00 AM', true],
    [null, '3:00 AM - 4:00 AM', true],
    ['By appointment', '3:00 AM - 4:00 AM', true],
];
foreach ($cases as [$hours, $slot, $expected]) {
    $actual = Schedule::slotWithinOperatingHours($hours, Schedule::parseTimeRange($slot));
    if ($actual !== $expected) {
        throw new RuntimeException("Hours {$hours}, slot {$slot}: expected " . json_encode($expected));
    }
}
echo count($cases) . " facility-hours cases passed.\n";
