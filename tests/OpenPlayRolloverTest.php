<?php
declare(strict_types=1);

namespace Picklers\Tests;

use Picklers\Core\Database;

/**
 * Tests Open Play recurring rollover logic, target date resolution,
 * display date formatting, player count scoping, and roster filtering.
 */
final class OpenPlayRolloverTest extends TestCase {

    public function run(): void {
        $db = Database::get();

        // 1. Non-everyday expired match vs active match
        $expiredMatch = [
            'id' => 'test_op_expired',
            'facility_id' => 1,
            'date' => date('Y-m-d', strtotime('-2 days')),
            'time' => '8:00 AM - 11:00 PM'
        ];
        $this->assertTrue(Database::isMatchExpired($expiredMatch), 'Past dated match is identified as expired');

        $activeMatch = [
            'id' => 'test_op_active',
            'facility_id' => 1,
            'date' => date('Y-m-d', strtotime('+2 days')),
            'time' => '8:00 AM - 11:00 PM'
        ];
        $this->assertFalse(Database::isMatchExpired($activeMatch), 'Future dated match is identified as active');

        // 2. Everyday match before end time
        $futureEndTime = date('g:i A', strtotime('+2 hours'));
        $everydayActive = [
            'id' => 'test_everyday_today',
            'facility_id' => 1,
            'date' => 'Everyday',
            'time' => '8:00 AM - ' . $futureEndTime
        ];
        $todayFormatted = date('F j, Y');
        $tomorrowFormatted = date('F j, Y', strtotime('+1 day'));
        $this->assertFalse(Database::isMatchExpired($everydayActive), 'Everyday match before end time is not expired');
        $this->assertSame(date('Y-m-d'), Database::getMatchTargetDate($everydayActive), 'Everyday match before end time targets today');
        $this->assertSame($todayFormatted, Database::getMatchDisplayDate($everydayActive), 'Everyday match before end time displays today formatted date');

        // 3. Everyday match after end time. The window must start before it
        // ends on the same day: a fixed "8:00 AM" start with an end of now−2h
        // is an OVERNIGHT session whenever the suite runs before 10 AM, which
        // (correctly) does not roll over — the fixture, not the code, was wrong.
        if ((int)date('G') >= 3) {
            $pastStartTime = date('g:i A', strtotime('-3 hours'));
            $pastEndTime = date('g:i A', strtotime('-2 hours'));
        } else {
            // Too early for a same-day window 2-3 hours back: use one that
            // started at midnight and ended a minute ago.
            $pastStartTime = '12:00 AM';
            $pastEndTime = date('g:i A', max(strtotime('today') + 60, strtotime('-1 minute')));
        }
        $everydayPassed = [
            'id' => 'test_everyday_tomorrow',
            'facility_id' => 1,
            'date' => 'Everyday',
            'time' => $pastStartTime . ' - ' . $pastEndTime
        ];
        $tomorrowStr = date('Y-m-d', strtotime('+1 day'));
        $this->assertFalse(Database::isMatchExpired($everydayPassed), 'Everyday match past end time is not expired (rolls over)');
        $this->assertSame($tomorrowStr, Database::getMatchTargetDate($everydayPassed), 'Everyday match past end time targets tomorrow');
        $this->assertSame($tomorrowFormatted, Database::getMatchDisplayDate($everydayPassed), 'Everyday match past end time displays tomorrow formatted date');

        // 4. Test getMatches output formatting for rollover match
        $facilityId = 1;
        $rolledMatch = $db->insertMatch([
            'id' => 'op_rollover_test',
            'facility_id' => $facilityId,
            'facility_name' => 'Cebu IT Park Pickle Center',
            'location' => 'Apas, Cebu City',
            'date' => 'Everyday',
            'time' => $pastStartTime . ' - ' . $pastEndTime,
            'level' => 'Advanced',
            'current_players' => 3,
            'max_players' => 4,
            'price' => 180.0,
            'type' => 'Court 1',
            'host' => 'Vince Teves',
            'title' => 'Cebu IT Park Pickle Center'
        ]);

        $matches = $db->getMatches();
        $foundRollover = null;
        foreach ($matches as $m) {
            if (($m['id'] ?? '') === 'op_rollover_test') {
                $foundRollover = $m;
                break;
            }
        }

        $this->assertNotNull($foundRollover, 'Rollover match is present in getMatches() output');
        $this->assertSame($tomorrowFormatted, $foundRollover['date'] ?? null, 'Rollover match date property is tomorrow formatted date');
        $this->assertSame(0, (int)($foundRollover['current_players'] ?? -1), 'Rollover match current_players resets to 0 for tomorrow session');

        // Clean up test match
        $db->deleteMatch('op_rollover_test', $facilityId, 'Court 1');
    }
}
