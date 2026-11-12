<?php
declare(strict_types=1);
require __DIR__.'/common.php';
if (($argv[1] ?? '') === '--self-check') {
    require dirname(__DIR__,2).'/src/Helpers/Format.php';
    $seen=[];for($i=1;$i<=10000;$i++){ $phone=auditPhone($i);if(Picklers\Helpers\Format::phMobile($phone)!==$phone||isset($seen[$phone]))throw new RuntimeException('Phone canonical uniqueness failed');$seen[$phone]=true; }
    if (auditCents(12345) !== '123.45' || auditUser(10000) !== 'audit_user_10000' || auditPhone(1)!=='+63 917 000 0001' || auditPhone(10000)!=='+63 917 001 0000') throw new RuntimeException('Fixture self-check failed');
    $balance=0;for($n=0;$n<20;$n++){ $step=auditLedgerStep($n); $balance+=($step['type']==='credit'?1:-1)*$step['amountCents'];if($balance!==$step['balanceCents'])throw new RuntimeException('Ledger self-check failed'); }
    if($balance!==100000)throw new RuntimeException('Closing balance failed');
    echo "fixture arithmetic and identity self-check passed\n"; exit;
}
try {
    $m = auditManifest($argv[1] ?? '');
    if (!is_string($m['fixturePassword'] ?? null) || strlen($m['fixturePassword']) < 12) throw new RuntimeException('Explicit synthetic fixture password required');
    $pdo = auditDb($m);
    $counts = ['users'=>10000,'facilities'=>100,'courts'=>400,'matches'=>100,'bookings'=>100000,'wallet_transactions'=>200000,'feed_posts'=>10000,'feed_comments'=>50000,'direct_messages'=>100000,'notifications'=>100000,'tournaments'=>1000];
    foreach ($counts as $table=>$count) {
        if ((int)$pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn() !== 0) throw new RuntimeException('Target fixture tables must be empty; no data was deleted');
        $engine = $pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?');
        $engine->execute([$m['db']['name'],$table]);
        if ($engine->fetchColumn() !== 'InnoDB') throw new RuntimeException('Transactional schema required');
    }
    $hash = password_hash($m['fixturePassword'], PASSWORD_BCRYPT);
    $epoch = $m['fixtureEpoch'] ?? '';
    $clock=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$epoch,new DateTimeZone('Asia/Manila'));
    if(!$clock || $clock->format('Y-m-d H:i:s')!==$epoch)throw new RuntimeException('Exact Manila fixtureEpoch required');
    $checksums = [];
    $insert = function(string $table, array $columns, int $count, callable $row) use ($pdo,&$checksums): void {
        $sql = 'INSERT INTO `'.$table.'` (`'.implode('`,`',$columns).'`) VALUES ('.implode(',',array_fill(0,count($columns),'?')).')';
        $stmt = $pdo->prepare($sql); $digest = hash_init('sha256');
        for ($i=1;$i<=$count;$i++) { $values=$row($i); $stmt->execute($values); if ($table==='users') $values[4]='<password-hash-excluded>'; hash_update($digest,json_encode($values,JSON_THROW_ON_ERROR)."\n"); }
        $checksums[$table]=hash_final($digest);
    };
    $pdo->beginTransaction();
    $insert('users',['id','name','email','phone','password_hash','role','verification_status','wallet_balance','is_admin','is_owner','created_at'],10000,fn($i)=>[auditUser($i),"Audit Player $i","user$i@capacity.invalid",auditPhone($i),$hash,$i<=100?'owner':($i===101?'admin':'player'),'verified','1000.00',(int)($i===101),(int)($i<=100),$epoch]);
    $insert('facilities',['id','owner_id','name','location','price','price_numeric','hours','courts_count','is_verified','image'],100,fn($i)=>[$i,auditUser($i),"Audit Venue $i",'Synthetic audit venue','PHP 300','300.00','6:00 AM - 10:00 PM',4,1,'assets/images/facilities/overhead_dumaguete.jpg']);
    $insert('courts',['id','facility_id','name','price','status'],400,fn($i)=>["audit_court_$i",intdiv($i-1,4)+1,'Court '.(($i-1)%4+1),'300.00','available']);
    $insert('matches',['id','facility_id','facility_name','location','date','time','current_players','max_players','price','type','host','title'],100,fn($i)=>["audit_match_$i",$i,"Audit Venue $i",'Synthetic audit venue','Everyday','6:00 PM - 8:00 PM',0,12,'150.00','Court 4',"Audit Owner $i","Audit Open Play $i"]);
    $insert('bookings',['id','user_id','facility_id','court_id','facility_name','court_name','date','time','booking_date','start_min','end_min','duration','price','payment_method','status','created_at'],100000,function($i) use ($epoch) {
        $court=($i-1)%400+1; $venue=intdiv($court-1,4)+1; $date=(new DateTimeImmutable('2025-01-01'))->modify('+'.intdiv($i-1,400).' days');
        return ["audit_booking_$i",auditUser(($i-1)%10000+1),$venue,"audit_court_$court","Audit Venue $venue",'Court '.(($court-1)%4+1),$date->format('D, M j, Y'),'8:00 AM - 9:00 AM',$date->format('Y-m-d'),480,540,'1 Hour','300.00','Pay at Venue','completed',$epoch];
    });
    $insert('wallet_transactions',['id','user_id','type','amount','label','date','created_at','entry_kind','balance_after','idempotency_key'],200000,function($i) use($clock) {
        $n=intdiv($i-1,10000); $step=auditLedgerStep($n);$at=$clock->modify("+$n minutes");
        return ["audit_tx_$i",auditUser(($i-1)%10000+1),$step['type'],auditCents($step['amountCents']),'Synthetic reconciled ledger',$at->format('M j, Y'),$at->format('Y-m-d H:i:s'),'admin_adjustment',auditCents($step['balanceCents']),"audit_tx_$i"];
    });
    $insert('feed_posts',['id','author_id','author_name','content','comment_count','created_at'],10000,fn($i)=>["audit_post_$i",auditUser($i),"Audit Player $i",'Synthetic capacity post',5,$epoch]);
    $insert('feed_comments',['id','post_id','user_id','author_name','comment','created_at'],50000,fn($i)=>["audit_comment_$i",'audit_post_'.(($i-1)%10000+1),auditUser(($i-1)%10000+1),'Audit Player','Synthetic comment',$epoch]);
    $insert('direct_messages',['id','sender_id','receiver_id','content','is_read','created_at'],100000,fn($i)=>["audit_dm_$i",auditUser(($i-1)%10000+1),auditUser($i%10000+1),'Synthetic direct message',$i%2,$epoch]);
    $insert('notifications',['id','user_id','title','body','type','is_read','created_at'],100000,fn($i)=>["audit_notification_$i",auditUser(($i-1)%10000+1),'Audit notification','Synthetic notification','system',$i%2,$epoch]);
    $insert('tournaments',['id','facility_id','data'],1000,fn($i)=>["audit_tournament_$i",(string)(($i-1)%100+1),json_encode(['id'=>"audit_tournament_$i",'facility_id'=>(string)(($i-1)%100+1),'owner_id'=>auditUser(($i-1)%100+1),'title'=>"Audit Tournament $i",'category'=>'Doubles','format'=>'single_elimination','status'=>'upcoming','pairing_mode'=>'fixed','date'=>'2026-11-01','end_date'=>'2026-11-01','time'=>'8:00 AM','venue'=>'Audit Venue','max_teams'=>8,'entry_fee'=>'0','prize_pool'=>'0','description'=>'Synthetic audit tournament','players_pool'=>[],'teams'=>[],'matches'=>[],'champion_team_id'=>null,'runner_up_team_id'=>null,'bracket_generated_at'=>null,'created_at'=>$epoch,'updated_at'=>$epoch],JSON_THROW_ON_ERROR)]);
    $pdo->commit();
    echo json_encode(['fixtureVersion'=>1,'epoch'=>$epoch,'counts'=>$counts,'orderedInputChecksums'=>$checksums,'passwordHashExcludedFromDeterminism'=>true,'users'=>['prefix'=>'audit_user_','emailPattern'=>'user{n}@capacity.invalid','owners'=>'1-100','admin'=>101,'players'=>'102-10000'],'limitations'=>['Uniform histories; no busy-user skew, deleted users, hidden posts or Open Play fixtures']],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $e) { if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack(); fwrite(STDERR,"Audit seed failed; transaction rolled back where active. ".get_class($e)."\n"); exit(1); }
