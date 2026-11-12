<?php
declare(strict_types=1);

namespace Picklers\Tests\Web;

use Picklers\Repositories\FacilityRepository;
use Picklers\Tests\Admin\AdminWebTestCase;

final class OvernightCourtTest extends AdminWebTestCase
{
    public function testOvernightOccupancyProtectsCourtAndCanBeEndedEarly(): void
    {
        if (date('H:i') === '23:59') self::markTestSkipped('This overnight fixture finishes at 23:59.');
        $facility = $this->scalar("SELECT id FROM facilities WHERE owner_id='usr_fx_owner1' LIMIT 1");
        $court = $this->db()->fetchAssociative('SELECT * FROM courts WHERE facility_id=? ORDER BY id LIMIT 1', [$facility]);
        $this->db()->executeStatement('DELETE FROM matches WHERE facility_id=?', [$facility]);
        $this->db()->executeStatement('DELETE FROM bookings WHERE facility_id=?', [$facility]);
        $this->db()->update('courts', ['status'=>'available'], ['id'=>$court['id']]);
        $this->db()->insert('bookings', [
            'id'=>'PKL-OVERNIGHT-COURT', 'user_id'=>'usr_fx_p1', 'facility_id'=>$facility, 'court_id'=>$court['id'],
            'facility_name'=>'Fixture Arena Dumaguete', 'court_name'=>$court['name'],
            'date'=>date('Y-m-d', strtotime('-1 day')), 'booking_date'=>date('Y-m-d', strtotime('-1 day')),
            'time'=>'11:59 PM - 11:59 PM', 'start_min'=>1439, 'end_min'=>2879,
            'price'=>150, 'payment_method'=>'Pickle Credits', 'status'=>'confirmed',
        ]);
        $repository = static::getContainer()->get(FacilityRepository::class);
        $repository->invalidateReadCache();
        $courts = array_column($repository->getCourtsByFacility($facility), null, 'id');
        self::assertSame('occupied', $courts[$court['id']]['status']);
        self::assertSame(1, $repository->countUpcomingCourtBookings($facility, $court['id'], $court['name']));
        self::assertTrue($repository->endCourtSessionEarly($facility, $court['id'], $court['name'])['success']);
        self::assertSame(0, $repository->countUpcomingCourtBookings($facility, $court['id'], $court['name']));
        self::assertSame('confirmed', $this->scalar("SELECT status FROM bookings WHERE id='PKL-OVERNIGHT-COURT'"));
        self::assertSame('150.00', $this->scalar("SELECT price FROM bookings WHERE id='PKL-OVERNIGHT-COURT'"));
    }
}
