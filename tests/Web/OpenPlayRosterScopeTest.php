<?php
declare(strict_types=1);

namespace Picklers\Tests\Web;

use Picklers\Repositories\MatchRepository;
use Picklers\Repositories\OpenPlayRepository;
use Picklers\Tests\Admin\AdminWebTestCase;

final class OpenPlayRosterScopeTest extends AdminWebTestCase
{
    public function testCurrentRosterExcludesOtherSessionsAndOlderLegacyOccurrences(): void
    {
        $facility = (int)$this->scalar("SELECT facility_id FROM bookings WHERE id = 'FX-PEND01'");
        $day = date('Y-m-d', strtotime('+2 days'));
        static::getContainer()->get(MatchRepository::class)->insertMatch([
            'id' => 'op_roster_current', 'facility_id' => $facility, 'facility_name' => 'Fixture Arena Dumaguete',
            'type' => 'Court 1', 'date' => $day, 'time' => '10:00 AM - 12:00 PM',
        ]);
        foreach ([
            ['PKL-OP-CURRENT', 'usr_fx_p1', 'op_roster_current', $day, '10:00 AM - 12:00 PM'],
            ['PKL-OP-OTHER', 'usr_fx_p2', 'op_roster_old', $day, '10:00 AM - 12:00 PM'],
            ['PKL-OP-LEGACY-OLD', 'usr_fx_p3', null, date('Y-m-d', strtotime('-1 day')), '10:00 AM - 12:00 PM'],
            ['PKL-OP-LEGACY-TIME', 'usr_fx_p4', null, $day, '2:00 PM - 4:00 PM'],
            ['PKL-OP-LEGACY-CURRENT', 'usr_fx_p5', null, $day, '10:00 AM - 12:00 PM'],
        ] as [$id, $user, $match, $date, $time]) {
            $this->db()->insert('bookings', [
                'id' => $id, 'user_id' => $user, 'facility_id' => $facility, 'match_id' => $match,
                'court_name' => 'Court 1', 'booking_date' => $date, 'date' => $date,
                'time' => $time, 'status' => 'confirmed',
            ]);
        }
        $roster = static::getContainer()->get(OpenPlayRepository::class)->getOpenPlayRoster($facility, 'Court 1', "crt_{$facility}_1");
        $ids = array_column($roster, 'id');
        sort($ids);
        self::assertSame(['PKL-OP-CURRENT', 'PKL-OP-LEGACY-CURRENT'], $ids);
    }
}
