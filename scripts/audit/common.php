<?php
declare(strict_types=1);
// Audit CLIs never load application bootstrap or .env.
function auditManifest(string $file): array {
    if (PHP_SAPI !== 'cli') throw new RuntimeException('CLI only');
    $m = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    $d = $m['db'] ?? [];
    if (($m['rootConfirmed'] ?? false) !== true || ($m['externalServicesDisabled'] ?? false) !== true
        || ($d['host'] ?? '') !== '127.0.0.1' || ($d['port'] ?? 0) !== 3306
        || !in_array($d['name'] ?? '', ['picklers_capacity_test','picklers_wholeflow_e2e'], true)
        || !isset($d['user'], $d['password'], $d['datadir'])) throw new RuntimeException('Explicit isolated manifest required');
    $root = realpath($m['auditRoot'] ?? '');
    $allowed = realpath(dirname(__DIR__, 2) . '/var');
    if (!$root || !$allowed || !str_starts_with(strtolower($root . DIRECTORY_SEPARATOR), strtolower($allowed . DIRECTORY_SEPARATOR))) throw new RuntimeException('Audit root must be inside project var');
    foreach (['statePath','privatePath','sessionPath'] as $key) {
        $p = realpath($m[$key] ?? '');
        if (!$p || !str_starts_with(strtolower($p . DIRECTORY_SEPARATOR), strtolower($root . DIRECTORY_SEPARATOR))) throw new RuntimeException('Invalid audit path');
    }
    return $m;
}
function auditDb(array $m): PDO {
    $d = $m['db'];
    $pdo = new PDO("mysql:host=127.0.0.1;port=3306;dbname={$d['name']};charset=utf8mb4", $d['user'], $d['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]);
    $actual = $pdo->query('SELECT @@datadir')->fetchColumn();
    if (strtolower(str_replace('\\','/',rtrim($actual,'/\\'))) !== strtolower(str_replace('\\','/',rtrim($d['datadir'],'/\\')))) throw new RuntimeException('Server datadir mismatch');
    return $pdo;
}
function auditCents(int $cents): string { return intdiv($cents,100) . '.' . str_pad((string)($cents%100),2,'0',STR_PAD_LEFT); }
function auditUser(int $i): string { return sprintf('audit_user_%05d',$i); }
function auditPhone(int $i): string { $digits=sprintf('%07d',$i); return '+63 917 '.substr($digits,0,3).' '.substr($digits,3); }
function auditLedgerStep(int $step): array {
    $credit=$step%2===0;
    return ['type'=>$credit?'credit':'debit','amountCents'=>$step===0?200000:($step===1?100000:1000),'balanceCents'=>$step===0?200000:($credit?101000:100000)];
}
