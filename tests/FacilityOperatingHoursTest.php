<?php
declare(strict_types=1);

namespace Picklers\Tests;

use Picklers\Core\Database;

/**
 * Tests facility operating hours calculations (isFacilityOpen).
 */
final class FacilityOperatingHoursTest extends TestCase {

    public function run(): void {
        $db = Database::get();

        // 1. 24/hrs facilities should always be open
        $this->assertTrue($db->isFacilityOpen('24/hrs'), '24/hrs is open');
        $this->assertTrue($db->isFacilityOpen('24 Hours'), '24 Hours is open');
        $this->assertTrue($db->isFacilityOpen(''), 'Empty hours defaults to open');

        // 2. Test active vs closed operating hours
        $this->assertFalse($db->isFacilityOpen('1:00 AM - 2:00 AM'), '1am to 2am is closed at night');

        // Range spanning current time is open
        $openNowRange = date('g:i A', strtotime('-1 hour')) . ' - ' . date('g:i A', strtotime('+1 hour'));
        $this->assertTrue($db->isFacilityOpen($openNowRange), 'Time range covering current time is open');
    }
}
