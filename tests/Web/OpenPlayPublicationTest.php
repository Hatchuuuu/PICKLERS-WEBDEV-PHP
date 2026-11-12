<?php
declare(strict_types=1);
namespace Picklers\Tests\Web;
use Picklers\Tests\Admin\AdminWebTestCase;
final class OpenPlayPublicationTest extends AdminWebTestCase
{
    private function publish(): int
    {
        $this->signIn('usr_fx_owner1');
        $_SESSION['_sf2_attributes']['_csrf/picklers'] = 'publication-test';
        $this->client->request('POST', '/owner.php?action=host_open_play', ['court_name'=>'Court 2','date'=>date('Y-m-d', strtotime('+3 days')),'start_time'=>'6:00 PM','end_time'=>'9:00 PM','capacity'=>8,'fee'=>150], [], ['HTTP_X_CSRF_TOKEN'=>'publication-test']);
        return $this->client->getResponse()->getStatusCode();
    }
    private function fixture(): string
    {
        $fac = (string)$this->scalar("SELECT id FROM facilities WHERE owner_id = 'usr_fx_owner1'");
        self::pdo()->exec("DELETE FROM matches WHERE id = 'op_publication_old'");
        self::pdo()->exec("DELETE FROM matches WHERE facility_id = " . self::pdo()->quote($fac));
        $this->db()->insert('matches', ['id'=>'op_publication_old','facility_id'=>$fac,'facility_name'=>'Fixture Arena Dumaguete','date'=>date('Y-m-d',strtotime('-2 days')),'time'=>'6:00 PM - 9:00 PM','type'=>'Court 2','title'=>'Old session','host'=>'Fixture owner','max_players'=>8,'price'=>150]);
        $this->db()->executeStatement("UPDATE courts SET status='OCCUPIED', occupied_by='Old session', occupied_until='9:00 PM' WHERE facility_id=? AND name='Court 2'",[$fac]);
        $this->db()->insert('bookings', ['id'=>'PKL-OP-PUBLICATION','user_id'=>'usr_fx_p1','facility_id'=>$fac,'facility_name'=>'Fixture Arena Dumaguete','court_name'=>'Court 2','match_id'=>'op_publication_old','booking_date'=>date('Y-m-d',strtotime('+3 days')),'date'=>date('Y-m-d',strtotime('+3 days')),'time'=>'6:00 PM - 9:00 PM','price'=>150,'payment_method'=>'Pickle Credits','status'=>'pending']);
        return $fac;
    }
    private function state(string $fac): array
    {
        $db=$this->db();
        return [
            $db->fetchAllAssociative('SELECT * FROM matches WHERE facility_id=? ORDER BY id',[$fac]),
            $db->fetchAllAssociative("SELECT * FROM bookings WHERE id='PKL-OP-PUBLICATION'"),
            $db->fetchAllAssociative("SELECT wallet_balance FROM users WHERE id='usr_fx_p1'"),
            $db->fetchAllAssociative("SELECT * FROM wallet_transactions WHERE user_id='usr_fx_p1' ORDER BY id"),
            $db->fetchAllAssociative('SELECT * FROM courts WHERE facility_id=? ORDER BY id',[$fac]),
            $db->fetchAllAssociative("SELECT * FROM notifications WHERE user_id LIKE 'usr_fx_%' ORDER BY id"),
            $db->fetchAllAssociative('SELECT * FROM sync_versions ORDER BY channel'),
        ];
    }
    public function testInsertFailureRollsBackCancellationAndRefund(): void
    {
        $fac=$this->fixture(); $before=$this->state($fac);
        self::pdo()->exec("CREATE TRIGGER publication_fail BEFORE INSERT ON matches FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='publication insert failure'");
        try { self::assertSame(500,$this->publish()); self::assertSame($before,$this->state($fac)); }
        finally { self::pdo()->exec('DROP TRIGGER IF EXISTS publication_fail'); }
    }
    public function testCourtFailureRollsBackInsertedSession(): void
    {
        $fac=$this->fixture(); $before=$this->state($fac);
        self::pdo()->exec("CREATE TRIGGER publication_fail BEFORE UPDATE ON courts FOR EACH ROW BEGIN IF NEW.occupied_by='Hosted Open Play' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='publication court failure'; END IF; END");
        try { self::assertSame(500,$this->publish()); self::assertSame($before,$this->state($fac)); }
        finally { self::pdo()->exec('DROP TRIGGER IF EXISTS publication_fail'); }
    }
    public function testSuccessfulReplacementSettlesOldBookingsOnce(): void
    {
        $fac=$this->fixture(); self::assertSame(200,$this->publish());
        self::assertSame(1,(int)$this->scalar('SELECT COUNT(*) FROM matches WHERE facility_id=?',[$fac]));
        self::assertSame('cancelled',$this->scalar("SELECT status FROM bookings WHERE id='PKL-OP-PUBLICATION'"));
        self::assertSame('1350.00',$this->scalar("SELECT wallet_balance FROM users WHERE id='usr_fx_p1'"));
        self::assertSame(1,(int)$this->scalar("SELECT COUNT(*) FROM notifications WHERE user_id='usr_fx_p1' AND title='Open Play Cancelled'"));
    }

    public function testPublicationRemovesTheCurrentSessionAfterWaitingForCourt(): void
    {
        $fac = $this->fixture();
        $this->db()->executeStatement("DELETE FROM bookings WHERE id='PKL-OP-PUBLICATION'");
        $this->db()->beginTransaction();
        $this->db()->fetchOne('SELECT id FROM courts WHERE facility_id=? AND name=? FOR UPDATE',[$fac,'Court 2']);
        $process = proc_open([PHP_BINARY,__DIR__.'/openplay-publication-worker.php','publish'],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes,dirname(__DIR__,2));
        self::assertIsResource($process);
        try {
            $id=(int)trim((string)fgets($pipes[1]));
            fwrite($pipes[0],"go\n"); fclose($pipes[0]);
            $deadline=microtime(true)+8;
            do {
                $info=(string)$this->db()->fetchOne('SELECT INFO FROM information_schema.PROCESSLIST WHERE ID=?',[$id]);
                if (str_contains($info,'SELECT * FROM courts')) break;
                usleep(10000);
            } while (microtime(true)<$deadline);
            self::assertStringContainsString('SELECT * FROM courts',$info);
            $this->db()->executeStatement("UPDATE matches SET id='op_publication_concurrent', date=? WHERE id='op_publication_old'",[date('Y-m-d',strtotime('+3 days'))]);
        } finally {
            $this->db()->commit();
            $output=stream_get_contents($pipes[1]); $error=stream_get_contents($pipes[2]);
            $exit=proc_close($process);
        }
        self::assertSame(0,$exit,$error);
        self::assertSame(200,json_decode($output,true)['status']);
        self::assertSame(1,(int)$this->scalar('SELECT COUNT(*) FROM matches WHERE facility_id=?',[$fac]));
        self::assertSame(0,(int)$this->scalar("SELECT COUNT(*) FROM matches WHERE id='op_publication_concurrent'"));
    }

    public function testPublicationWaitsForJoiningPlayerAndRejectsReplacement(): void
    {
        $fac = $this->fixture();
        $this->db()->executeStatement("DELETE FROM bookings WHERE id='PKL-OP-PUBLICATION'");
        $this->db()->executeStatement("UPDATE matches SET date=? WHERE id='op_publication_old'", [date('Y-m-d',strtotime('+3 days'))]);
        $this->db()->fetchOne("SELECT GET_LOCK('publication_join_barrier', 0)");
        self::pdo()->exec("CREATE TRIGGER publication_pause BEFORE INSERT ON bookings FOR EACH ROW BEGIN IF NEW.match_id='op_publication_old' THEN SET @publication_wait=GET_LOCK('publication_join_barrier', 15); SET @publication_release=RELEASE_LOCK('publication_join_barrier'); END IF; END");
        $workers = [];
        try {
            foreach (['join', 'publish'] as $mode) {
                $process = proc_open([PHP_BINARY,__DIR__.'/openplay-publication-worker.php',$mode], [['pipe','r'],['pipe','w'],['pipe','w']], $pipes, dirname(__DIR__,2));
                self::assertIsResource($process);
                $id = (int)trim((string)fgets($pipes[1]));
                $workers[$mode] = [$process,$pipes];
                fwrite($pipes[0], "go\n"); fclose($pipes[0]);
                $deadline = microtime(true)+8;
                do {
                    $state = $this->db()->fetchAssociative('SELECT STATE, INFO FROM information_schema.PROCESSLIST WHERE ID=?',[$id]);
                    $waiting = $mode==='join' ? str_contains(strtolower((string)($state['STATE']??'')), 'user lock') : str_contains((string)($state['INFO']??''),'SELECT * FROM courts');
                    if ($waiting) break;
                    usleep(10000);
                } while (microtime(true)<$deadline);
                self::assertTrue($waiting, $mode.' must overlap while blocked: '.json_encode($state));
            }
        } finally {
            $this->db()->fetchOne("SELECT RELEASE_LOCK('publication_join_barrier')");
            foreach ($workers as $mode=>[$process,$pipes]) {
                $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
                $results[$mode] = [proc_close($process),json_decode($output,true),$error];
            }
            self::pdo()->exec('DROP TRIGGER IF EXISTS publication_pause');
        }
        self::assertSame(0,$results['join'][0],$results['join'][2]);
        self::assertTrue($results['join'][1]['success']);
        self::assertSame(0,$results['publish'][0],$results['publish'][2]);
        self::assertSame(409,$results['publish'][1]['status']);
        self::assertSame(1,(int)$this->scalar("SELECT COUNT(*) FROM matches WHERE id='op_publication_old'"));
        self::assertSame('pending',$this->scalar("SELECT status FROM bookings WHERE match_id='op_publication_old' AND user_id='usr_fx_p2'"));
        self::assertSame(0,(int)$this->scalar("SELECT COUNT(*) FROM notifications WHERE title='Open Play Cancelled'"));
    }

    public function testTwoUsersCannotTakeTheFinalSeatAfterCourtWait(): void
    {
        $this->overlappingJoins('usr_fx_p3', 1);
    }

    public function testSameUserCannotCreateDuplicateRequests(): void
    {
        $this->overlappingJoins('usr_fx_p2', 8);
    }

    private function overlappingJoins(string $secondUser, int $capacity): void
    {
        $this->fixture();
        $this->db()->executeStatement("UPDATE users SET wallet_balance=1000 WHERE id IN ('usr_fx_p2','usr_fx_p3')");
        $balanceBefore=(float)$this->scalar('SELECT wallet_balance FROM users WHERE id=?',[$secondUser]);
        $ledgerBefore=(int)$this->scalar('SELECT COUNT(*) FROM wallet_transactions WHERE user_id=?',[$secondUser]);
        $this->db()->executeStatement("DELETE FROM bookings WHERE id='PKL-OP-PUBLICATION'");
        $this->db()->executeStatement("UPDATE matches SET date=?, max_players=? WHERE id='op_publication_old'", [date('Y-m-d',strtotime('+3 days')),$capacity]);
        $this->db()->fetchOne("SELECT GET_LOCK('publication_join_barrier', 0)");
        self::pdo()->exec("CREATE TRIGGER publication_pause BEFORE INSERT ON bookings FOR EACH ROW BEGIN IF NEW.match_id='op_publication_old' THEN SET @publication_wait=GET_LOCK('publication_join_barrier', 15); SET @publication_release=RELEASE_LOCK('publication_join_barrier'); END IF; END");
        $workers=[];
        try {
            foreach (['usr_fx_p2',$secondUser] as $index=>$user) {
                $process=proc_open([PHP_BINARY,__DIR__.'/openplay-publication-worker.php','join',$user,'Pickle Credits'],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes,dirname(__DIR__,2));
                self::assertIsResource($process);
                $id=(int)trim((string)fgets($pipes[1]));
                $workers[]=[$process,$pipes];
                fwrite($pipes[0],"go\n"); fclose($pipes[0]);
                $deadline=microtime(true)+8;
                do {
                    $state=$this->db()->fetchAssociative('SELECT STATE, INFO FROM information_schema.PROCESSLIST WHERE ID=?',[$id]);
                    $waiting=$index===0 ? str_contains(strtolower((string)($state['STATE']??'')),'user lock') : str_contains((string)($state['INFO']??''),$secondUser==='usr_fx_p2' ? 'SELECT wallet_balance, role FROM users' : 'SELECT * FROM courts');
                    if ($waiting) break;
                    usleep(10000);
                } while (microtime(true)<$deadline);
                self::assertTrue($waiting,'Join '.$index.' must be blocked: '.json_encode($state));
            }
        } finally {
            $this->db()->fetchOne("SELECT RELEASE_LOCK('publication_join_barrier')");
            foreach ($workers as [$process,$pipes]) {
                $output=stream_get_contents($pipes[1]); $error=stream_get_contents($pipes[2]);
                $results[]=[proc_close($process),json_decode($output,true),$error];
            }
            self::pdo()->exec('DROP TRIGGER IF EXISTS publication_pause');
        }
        self::assertSame(0,$results[0][0],$results[0][2]);
        self::assertSame(0,$results[1][0],$results[1][2]);
        self::assertTrue($results[0][1]['success']);
        self::assertFalse($results[1][1]['success']);
        self::assertSame(1,(int)$this->scalar("SELECT COUNT(*) FROM bookings WHERE match_id='op_publication_old' AND status='pending'"));
        $expectedCharges=$secondUser==='usr_fx_p2' ? 1 : 0;
        $charged=(float)$this->scalar("SELECT price FROM bookings WHERE match_id='op_publication_old'");
        self::assertSame($balanceBefore-$expectedCharges*$charged,(float)$this->scalar('SELECT wallet_balance FROM users WHERE id=?',[$secondUser]));
        self::assertSame($ledgerBefore+$expectedCharges,(int)$this->scalar('SELECT COUNT(*) FROM wallet_transactions WHERE user_id=?',[$secondUser]));
        $tx=$this->db()->fetchAssociative("SELECT wt.* FROM wallet_transactions wt JOIN bookings b ON b.id=wt.booking_id WHERE b.match_id='op_publication_old' AND wt.user_id='usr_fx_p2'");
        self::assertSame('booking_payment',$tx['entry_kind']);
        self::assertSame('debit',$tx['type']);
        self::assertSame(number_format(1000-$charged,2,'.',''),$tx['balance_after']);
        self::assertSame($this->scalar("SELECT wallet_balance FROM users WHERE id='usr_fx_p2'"),$tx['balance_after']);
    }
}
