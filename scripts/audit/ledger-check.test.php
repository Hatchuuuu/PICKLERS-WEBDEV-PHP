<?php
declare(strict_types=1);
require __DIR__.'/ledger-check.php';
function check(bool $condition):void{if(!$condition)throw new RuntimeException('Ledger checker test failed');}
check(auditDecimalCents('123.4')===12340);check(auditDecimalCents('-0.01')===-1);
$row=fn($type,$amount,$after)=>['type'=>$type,'amount'=>$amount,'balance_after'=>$after];
// Random ID sorting can put refund before debit: original order must still be discoverable.
$tied=[$row('credit','300.00','1000.00'),$row('debit','300.00','700.00')];
check(auditLedgerGroup(100000,$tied)['status']==='valid');
$tied[1]['balance_after']='701.00';check(auditLedgerGroup(100000,$tied)['status']==='invalid');
$unknown=[$row('credit','300.00','1000.00'),$row('debit','300.00',null)];
$r=auditLedgerGroup(100000,$unknown);check($r['status']==='valid'&&$r['missingEvidence']===1&&$r['end']===100000);
$unknown[0]['balance_after']='999.00';check(auditLedgerGroup(100000,$unknown)['status']==='invalid');
check(auditLedgerGroup(0,array_fill(0,13,$row('credit','1.00',null)))['status']==='indeterminate');
echo "Ledger tied-order, invalid-chain, missing-evidence and bounded-search checks passed\n";
