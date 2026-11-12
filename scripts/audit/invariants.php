<?php
declare(strict_types=1);
require __DIR__.'/common.php';
require __DIR__.'/ledger-check.php';
try {
    $pdo=auditDb(auditManifest($argv[1] ?? ''));
    // Scope: persistent relational/financial checks; HTTP ownership and race checks are separate.
    $queries=[
        'walletMismatch'=>"SELECT COUNT(*) FROM users u LEFT JOIN (SELECT user_id,SUM(CASE WHEN type='credit' THEN amount ELSE -amount END) balance FROM wallet_transactions GROUP BY user_id) l ON l.user_id=u.id WHERE u.id LIKE 'audit_user_%' AND u.wallet_balance<>COALESCE(l.balance,0)",
        'courtVenueMismatch'=>'SELECT COUNT(*) FROM bookings b JOIN courts c ON c.id=b.court_id WHERE c.facility_id<>b.facility_id',
        'missingLiveCourt'=>"SELECT COUNT(*) FROM bookings b LEFT JOIN courts c ON c.id=b.court_id WHERE b.court_id IS NOT NULL AND b.court_id<>'' AND c.id IS NULL AND b.status NOT IN ('cancelled','completed','rejected') AND (b.booking_date IS NULL OR b.booking_date>=CURRENT_DATE())",
        'missingBookingUser'=>'SELECT COUNT(*) FROM bookings b LEFT JOIN users u ON u.id=b.user_id WHERE u.id IS NULL',
        'duplicateStaff'=>'SELECT COUNT(*) FROM (SELECT facility_id,user_id FROM staff WHERE user_id IS NOT NULL AND status=\'Active\' GROUP BY facility_id,user_id HAVING COUNT(*)>1) d',
        'openPlayOverCapacity'=>'SELECT COUNT(*) FROM matches WHERE current_players>max_players OR current_players<0',
        'duplicateIdempotency'=>'SELECT COUNT(*) FROM (SELECT idempotency_key FROM wallet_transactions WHERE idempotency_key IS NOT NULL GROUP BY idempotency_key HAVING COUNT(*)>1) d',
        'invalidLedger'=>'SELECT COUNT(*) FROM wallet_transactions WHERE type NOT IN (\'credit\',\'debit\') OR amount<0',
        'missingMessageUsers'=>'SELECT COUNT(*) FROM direct_messages m LEFT JOIN users s ON s.id=m.sender_id LEFT JOIN users r ON r.id=m.receiver_id WHERE s.id IS NULL OR r.id IS NULL',
        'postCommentCountMismatch'=>'SELECT COUNT(*) FROM feed_posts p LEFT JOIN (SELECT post_id,COUNT(*) n FROM feed_comments GROUP BY post_id) c ON c.post_id=p.id WHERE p.comment_count<>COALESCE(c.n,0)',
        'invalidTournamentJson'=>'SELECT COUNT(*) FROM tournaments WHERE NOT JSON_VALID(data)',
        'promoMismatch'=>'SELECT COUNT(*) FROM promo_codes p LEFT JOIN (SELECT promo_id,COUNT(*) n FROM promo_redemptions GROUP BY promo_id) r ON r.promo_id=p.id WHERE p.times_used<>COALESCE(r.n,0)',
    ];
    $result=[];
    foreach($queries as $name=>$sql) { $start=hrtime(true); $result[$name]=['violations'=>(int)$pdo->query($sql)->fetchColumn(),'elapsedMs'=>(hrtime(true)-$start)/1e6]; }
    $start=hrtime(true);$ledger=['invalidGroups'=>0,'indeterminateGroups'=>0,'missingBalanceAfterRows'=>0,'groups'=>0];$user=null;$time=null;$before=0;$group=[];
    $finish=function()use(&$group,&$before,&$ledger):void{if(!$group)return;$r=auditLedgerGroup($before,$group);$before=$r['end'];$ledger['groups']++;$ledger['invalidGroups']+=(int)($r['status']==='invalid');$ledger['indeterminateGroups']+=(int)($r['status']==='indeterminate');$ledger['missingBalanceAfterRows']+=$r['missingEvidence'];$group=[];};
    $stmt=$pdo->query("SELECT user_id,created_at,type,amount,balance_after FROM wallet_transactions WHERE user_id LIKE 'audit_user_%' ORDER BY user_id,created_at,id");
    while($row=$stmt->fetch(PDO::FETCH_ASSOC)){if($user!==$row['user_id']||$time!==$row['created_at']){$finish();if($user!==$row['user_id'])$before=0;$user=$row['user_id'];$time=$row['created_at'];}$group[]=$row;}$finish();
    $result['ledgerBalanceAfterMismatch']=['violations'=>$ledger['invalidGroups'],'elapsedMs'=>(hrtime(true)-$start)/1e6];
    $result['ledgerChainIndeterminate']=['violations'=>0,'unverifiedGroups'=>$ledger['indeterminateGroups']];
    $historicalMissing=(int)$pdo->query("SELECT COUNT(*) FROM bookings b LEFT JOIN courts c ON c.id=b.court_id WHERE b.court_id IS NOT NULL AND b.court_id<>'' AND c.id IS NULL AND (b.status IN ('cancelled','completed','rejected') OR b.booking_date<CURRENT_DATE())")->fetchColumn();
    $counts=[]; foreach(['users','facilities','courts','matches','bookings','wallet_transactions','feed_posts','feed_comments','direct_messages','notifications','tournaments'] as $t) $counts[$t]=(int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    $failed=array_sum(array_column($result,'violations'))>0;
    $unverified=$ledger['missingBalanceAfterRows']>0||$ledger['indeterminateGroups']>0;
    echo json_encode(['passed'=>!$failed&&!$unverified,'status'=>$failed?'failed':($unverified?'unverified':'passed'),'arithmeticAndRelationalChecksPassed'=>!$failed,'balanceAfterEvidenceComplete'=>!$unverified,'ledgerChainEvidence'=>$ledger,'historicalMissingCourtReferences'=>$historicalMissing,'counts'=>$counts,'checks'=>$result,'peakCliMemoryBytes'=>memory_get_peak_usage(true),'limitations'=>['No per-request SQL profile','No booking interval semantics/race/ownership certification','Missing balance_after is reported evidence incompleteness; total wallet reconciliation and all recorded balance_after constraints remain asserted','Zero opening ledger balance applies only to these synthetic audit users']],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
    exit($failed?1:($unverified?2:0));
} catch(Throwable $e) { fwrite(STDERR,'Audit invariants failed: '.get_class($e)."\n"); exit(1); }
