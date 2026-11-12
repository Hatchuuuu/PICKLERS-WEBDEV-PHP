<?php
declare(strict_types=1);
namespace Picklers\Tests\Web;

use Picklers\Repositories\BookingRepository;
use Picklers\Tests\Admin\AdminWebTestCase;

final class SessionEndedConcurrencyTest extends AdminWebTestCase
{
    private function fixture(): void
    {
        $this->db()->executeStatement("UPDATE bookings SET end_alert_sent=1 WHERE user_id='usr_fx_p1'");
        $facility = $this->scalar("SELECT id FROM facilities WHERE owner_id='usr_fx_owner1' LIMIT 1");
        $this->db()->insert('bookings', [
            'id'=>'PKL-END-ATOMIC', 'user_id'=>'usr_fx_p1', 'facility_id'=>$facility,
            'facility_name'=>'Fixture Arena Dumaguete', 'court_name'=>'Court 1',
            'date'=>date('Y-m-d'), 'booking_date'=>date('Y-m-d'),
            'time'=>'12:00 AM - 12:00 AM', 'start_min'=>0, 'end_min'=>0,
            'price'=>150, 'payment_method'=>'Pickle Credits', 'status'=>'confirmed',
        ]);
    }

    public function testInsertionFailureLeavesClaimRetryable(): void
    {
        $this->fixture();
        $before = $this->db()->fetchAssociative("SELECT * FROM bookings WHERE id='PKL-END-ATOMIC'");
        $this->db()->executeStatement("CREATE TRIGGER session_end_fail BEFORE INSERT ON notifications FOR EACH ROW BEGIN IF NEW.type='session_ended' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='session end insert failure'; END IF; END");
        try {
            try {
                static::getContainer()->get(BookingRepository::class)->checkAndNotifySessionEnd('usr_fx_p1');
                self::fail('Injected insertion must fail');
            } catch (\Doctrine\DBAL\Exception $e) {
                self::assertStringContainsString('session end insert failure', $e->getMessage());
            }
            self::assertSame($before, $this->db()->fetchAssociative("SELECT * FROM bookings WHERE id='PKL-END-ATOMIC'"));
            self::assertSame(0, $this->notificationCount());
        } finally {
            $this->db()->executeStatement('DROP TRIGGER IF EXISTS session_end_fail');
        }
        self::assertCount(1, static::getContainer()->get(BookingRepository::class)->checkAndNotifySessionEnd('usr_fx_p1'));
        self::assertSame(1, $this->notificationCount());
    }

    public function testOverlappingSyncPollsCommitOnlyOneAlert(): void
    {
        $this->fixture();
        $results = $this->overlap(2);
        self::assertSame(1, count($results[0]['ended_sessions']) + count($results[1]['ended_sessions']));
        self::assertSame(1, $this->notificationCount());
        self::assertSame('confirmed', $this->scalar("SELECT status FROM bookings WHERE id='PKL-END-ATOMIC'"));
    }

    public function testCancellationDuringEligibilityDoesNotAlert(): void
    {
        $this->fixture();
        $results = $this->overlap(1, ['status'=>'cancelled']);
        self::assertSame([], $results[0]['ended_sessions']);
        self::assertSame(0, $this->notificationCount());
        self::assertSame('cancelled', $this->scalar("SELECT status FROM bookings WHERE id='PKL-END-ATOMIC'"));
    }

    public function testExtendedEndTimeDuringEligibilityDoesNotAlert(): void
    {
        $this->fixture();
        $results = $this->overlap(1, ['end_min'=>1440]);
        self::assertSame([], $results[0]['ended_sessions']);
        self::assertSame(0, $this->notificationCount());
    }

    public function testMovedDateDuringEligibilityDoesNotAlert(): void
    {
        $this->fixture();
        $results = $this->overlap(1, ['booking_date'=>date('Y-m-d', strtotime('+1 day'))]);
        self::assertSame([], $results[0]['ended_sessions']);
        self::assertSame(0, $this->notificationCount());
    }

    public function testCurrentEarlyEndOverridesExtendedTime(): void
    {
        $this->fixture();
        $results = $this->overlap(1, ['end_min'=>1440, 'ended_early'=>1]);
        self::assertCount(1, $results[0]['ended_sessions']);
        self::assertSame(1, $this->notificationCount());
    }

    public function testRemovedEarlyEndIsRevalidatedAfterEligibility(): void
    {
        $this->fixture();
        $this->db()->update('bookings', ['ended_early'=>1, 'end_min'=>1440], ['id'=>'PKL-END-ATOMIC']);
        $results = $this->overlap(1, ['ended_early'=>0]);
        self::assertSame([], $results[0]['ended_sessions']);
        self::assertSame(0, $this->notificationCount());
    }

    public function testTodayOnlyNullClaimAndEarlyEndRemainSupported(): void
    {
        $this->fixture();
        $repository = static::getContainer()->get(BookingRepository::class);
        $this->db()->update('bookings', ['booking_date'=>date('Y-m-d', strtotime('-1 day'))], ['id'=>'PKL-END-ATOMIC']);
        self::assertSame([], $repository->checkAndNotifySessionEnd('usr_fx_p1'));
        $this->db()->update('bookings', ['booking_date'=>date('Y-m-d'), 'end_min'=>1440, 'ended_early'=>1, 'end_alert_sent'=>null], ['id'=>'PKL-END-ATOMIC']);
        self::assertCount(1, $repository->checkAndNotifySessionEnd('usr_fx_p1'));
        self::assertSame([], $repository->checkAndNotifySessionEnd('usr_fx_p1'));
        self::assertSame(1, $this->notificationCount());
    }

    public function testYesterdayOvernightEndAlertsTodayExactlyOnce(): void
    {
        $this->fixture();
        $this->db()->update('bookings', [
            'booking_date'=>date('Y-m-d', strtotime('-1 day')), 'start_min'=>1380, 'end_min'=>1440,
            'time'=>'11:00 PM - 12:00 AM',
        ], ['id'=>'PKL-END-ATOMIC']);
        $repository = static::getContainer()->get(BookingRepository::class);
        self::assertCount(1, $repository->checkAndNotifySessionEnd('usr_fx_p1'));
        self::assertSame([], $repository->checkAndNotifySessionEnd('usr_fx_p1'));
        self::assertSame(1, $this->notificationCount());
    }

    public function testYesterdayLegacyOvernightEndUsesItsTimeRange(): void
    {
        $this->fixture();
        $this->db()->update('bookings', [
            'booking_date'=>date('Y-m-d', strtotime('-1 day')), 'end_min'=>null,
            'time'=>'11:00 PM - 12:00 AM',
        ], ['id'=>'PKL-END-ATOMIC']);
        self::assertCount(1, static::getContainer()->get(BookingRepository::class)->checkAndNotifySessionEnd('usr_fx_p1'));
        self::assertSame(1, $this->notificationCount());
    }

    public function testYesterdayOvernightWindowIsRecheckedAfterLockWait(): void
    {
        $this->fixture();
        $this->db()->update('bookings', [
            'booking_date'=>date('Y-m-d', strtotime('-1 day')), 'end_min'=>1440,
        ], ['id'=>'PKL-END-ATOMIC']);
        $results = $this->overlap(1, ['end_min'=>1439]);
        self::assertSame([], $results[0]['ended_sessions']);
        self::assertSame(0, $this->notificationCount());
    }

    public function testYesterdayOvernightEarlyEndAlertsBeforeScheduledEnd(): void
    {
        $this->fixture();
        $this->db()->update('bookings', [
            'booking_date'=>date('Y-m-d', strtotime('-1 day')), 'end_min'=>2880, 'ended_early'=>1,
        ], ['id'=>'PKL-END-ATOMIC']);
        self::assertCount(1, static::getContainer()->get(BookingRepository::class)->checkAndNotifySessionEnd('usr_fx_p1'));
    }

    private function notificationCount(): int
    {
        return (int)$this->scalar("SELECT COUNT(*) FROM notifications WHERE user_id='usr_fx_p1' AND type='session_ended'");
    }

    private function overlap(int $count, array $change = []): array
    {
        $wallet = $this->scalar("SELECT wallet_balance FROM users WHERE id='usr_fx_p1'");
        $ledger = $this->db()->fetchAllAssociative("SELECT * FROM wallet_transactions WHERE user_id='usr_fx_p1' ORDER BY id");
        $this->db()->beginTransaction();
        $this->db()->fetchOne("SELECT id FROM bookings WHERE id='PKL-END-ATOMIC' FOR UPDATE");
        $workers = [];
        $results = [];
        try {
            for ($i=0; $i<$count; $i++) {
                $process = proc_open([PHP_BINARY, __DIR__.'/session-ended-worker.php'], [['pipe','r'],['pipe','w'],['pipe','w']], $pipes, dirname(__DIR__,2));
                self::assertIsResource($process);
                $workers[] = [$process, $pipes];
                $id = (int)trim((string)fgets($pipes[1]));
                fwrite($pipes[0], "go\n");
                fclose($pipes[0]);
                $deadline = microtime(true)+8;
                do {
                    $info = (string)$this->db()->fetchOne('SELECT INFO FROM information_schema.PROCESSLIST WHERE ID=?', [$id]);
                    $waiting = str_contains($info, 'UPDATE bookings SET end_alert_sent') || (str_contains($info, 'SELECT * FROM bookings') && str_contains($info, 'FOR UPDATE'));
                    if ($waiting) break;
                    usleep(10000);
                } while (microtime(true)<$deadline);
                self::assertTrue($waiting, 'Poll must reach the held booking row after eligibility: '.$info);
            }
            if ($change !== []) $this->db()->update('bookings', $change, ['id'=>'PKL-END-ATOMIC']);
        } finally {
            $this->db()->commit();
            foreach ($workers as [$process,$pipes]) {
                $output = stream_get_contents($pipes[1]);
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]); fclose($pipes[2]);
                self::assertSame(0, proc_close($process), $error);
                $results[] = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
            }
        }
        self::assertSame($wallet, $this->scalar("SELECT wallet_balance FROM users WHERE id='usr_fx_p1'"));
        self::assertSame($ledger, $this->db()->fetchAllAssociative("SELECT * FROM wallet_transactions WHERE user_id='usr_fx_p1' ORDER BY id"));
        self::assertSame('150.00', $this->scalar("SELECT price FROM bookings WHERE id='PKL-END-ATOMIC'"));
        return $results;
    }
}
