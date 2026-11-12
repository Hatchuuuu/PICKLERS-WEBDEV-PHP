<?php
declare(strict_types=1);

namespace Picklers\Tests\Web;

use Picklers\Repositories\BookingRepository;
use Picklers\Repositories\MatchRepository;
use Picklers\Repositories\OpenPlayRepository;
use Picklers\Repositories\OwnerRepository;
use Picklers\Tests\Admin\AdminWebTestCase;

final class OpenPlaySettlementTest extends AdminWebTestCase
{
    public function testPaidOpenPlayJoinPersistsItsWindowAndBecomesWithdrawableOnlyAfterPlay(): void
    {
        $facility = (int)$this->scalar("SELECT facility_id FROM bookings WHERE id = 'FX-PEND01'");
        $this->db()->update('facilities', ['hours' => '24 hours'], ['id' => $facility]);
        static::getContainer()->get(MatchRepository::class)->insertMatch([
            'id' => 'op_settlement', 'facility_id' => $facility, 'facility_name' => 'Fixture Arena Dumaguete',
            'type' => 'Court 1', 'date' => date('Y-m-d', strtotime('+3 days')),
            'time' => '10:00 PM - 1:00 AM', 'price' => 150,
        ]);
        $joined = static::getContainer()->get(OpenPlayRepository::class)->joinMatch('op_settlement', 'usr_fx_p1', 'Pickle Credits');
        self::assertTrue($joined['success'], json_encode($joined));
        $bookingId = $joined['booking']['id'];
        $row = $this->db()->fetchAssociative('SELECT * FROM bookings WHERE id = ?', [$bookingId]);
        self::assertSame(1320, (int)$row['start_min']);
        self::assertSame(1500, (int)$row['end_min']);
        self::assertSame('3 Hours', $row['duration']);
        self::assertTrue(static::getContainer()->get(BookingRepository::class)->confirmBookingRequest($bookingId)['ok']);
        $owners = static::getContainer()->get(OwnerRepository::class);
        self::assertSame(0.0, $owners->getOwnerFinancials($facility)['available'], 'Future play remains refundable and unavailable.');
        $this->db()->update('bookings', ['ended_early' => 1], ['id' => $bookingId]);
        self::assertSame(0.0, $owners->getOwnerFinancials($facility)['available'], 'Ending early does not bypass the scheduled settlement time.');

        // Advance only this synthetic booking's date to simulate the completed session.
        $this->db()->update('bookings', ['booking_date' => date('Y-m-d', strtotime('-2 days'))], ['id' => $bookingId]);
        self::assertSame('165.00', $row['price'], '150 entry plus the existing 10% checkout service fee.');
        self::assertSame(125.4, $owners->getOwnerFinancials($facility)['available'], '165 collected less 5% fee and 20% reserve.');
    }

    public function testHistoricalOpenPlayWithoutMinutesUsesItsRecordedWindowForSettlement(): void
    {
        $facility = (int)$this->scalar("SELECT facility_id FROM bookings WHERE id = 'FX-DONE01'");
        $this->db()->update('bookings', ['match_id' => 'op_historical', 'start_min' => null, 'end_min' => null], ['id' => 'FX-DONE01']);
        $this->db()->update('wallet_transactions', ['booking_id' => 'FX-DONE01', 'entry_kind' => 'booking_payment'], ['id' => 'fx_tx02']);
        $owners = static::getContainer()->get(OwnerRepository::class);
        self::assertSame(228.0, $owners->getOwnerFinancials($facility)['available']);
        self::assertNull($this->scalar("SELECT end_min FROM bookings WHERE id = 'FX-DONE01'"), 'Reading earnings does not rewrite history.');
        $this->db()->update('wallet_transactions', ['entry_kind' => null], ['id' => 'fx_tx02']);
        self::assertSame(0.0, $owners->getOwnerFinancials($facility)['available'], 'Legacy timing cannot replace collection evidence.');
        $this->db()->update('wallet_transactions', ['entry_kind' => 'booking_payment'], ['id' => 'fx_tx02']);
        $this->db()->insert('wallet_transactions', ['id' => 'fx_settlement_refund', 'user_id' => 'usr_fx_p1', 'type' => 'credit', 'amount' => 300, 'booking_id' => 'FX-DONE01']);
        self::assertSame(0.0, $owners->getOwnerFinancials($facility)['available'], 'Refunded legacy collections cannot fund payouts.');
        $this->db()->delete('wallet_transactions', ['id' => 'fx_settlement_refund']);

        foreach ([
            ['booking_date' => date('Y-m-d', strtotime('+2 days')), 'time' => '9:00 AM - 10:00 AM'],
            ['booking_date' => date('Y-m-d', strtotime('-2 days')), 'time' => 'not a time'],
            ['booking_date' => date('Y-m-d'), 'time' => '11:00 PM - 1:00 AM'],
        ] as $window) {
            $this->db()->update('bookings', $window, ['id' => 'FX-DONE01']);
            self::assertSame(0.0, $owners->getOwnerFinancials($facility)['available'], 'Future, unknown and unfinished overnight windows cannot fund payouts.');
        }
    }
}
