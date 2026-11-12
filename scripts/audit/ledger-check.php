<?php
declare(strict_types=1);
function auditDecimalCents(string $value): int {
    if(!preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/D',$value,$m))throw new RuntimeException('Invalid ledger decimal');
    return ($m[1]==='-'?-1:1)*((int)$m[2]*100+(int)str_pad($m[3]??'',2,'0'));
}
/** Random IDs do not order second-resolution ledger timestamps. Check all feasible tied orders. */
function auditLedgerGroup(int $before,array $rows): array {
    $end=$before;$missing=0;
    foreach($rows as &$row){$row['delta']=($row['type']==='credit'?1:-1)*auditDecimalCents((string)$row['amount']);$row['after']=$row['balance_after']===null?null:auditDecimalCents((string)$row['balance_after']);$end+=$row['delta'];$missing+=(int)($row['after']===null);}unset($row);
    if(count($rows)>12)return ['status'=>'indeterminate','end'=>$end,'missingEvidence'=>$missing];
    $visited=[];$steps=0;$exhausted=false;$all=(1<<count($rows))-1;
    $visit=function(int $mask,int $balance)use(&$visit,&$visited,&$steps,&$exhausted,$rows,$all): bool{
        if(++$steps>50000){$exhausted=true;return false;}
        if($mask===$all)return true;
        $key="$mask:$balance";if(isset($visited[$key]))return false;$visited[$key]=true;
        foreach($rows as $i=>$row){$bit=1<<$i;if(($mask&$bit)!==0)continue;$after=$balance+$row['delta'];if($row['after']!==null&&$row['after']!==$after)continue;if($visit($mask|$bit,$after))return true;}
        return false;
    };
    $valid=$visit(0,$before);
    return ['status'=>$valid?'valid':($exhausted?'indeterminate':'invalid'),'end'=>$end,'missingEvidence'=>$missing];
}
