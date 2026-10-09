<?php
declare(strict_types=1);

/**
 * Upgrade + rollback + restore rehearsal on a DISPOSABLE copy of a database.
 *
 *   php scripts/rehearse-upgrade.php [source_db]        (default: picklers_db)
 *
 * 1. Read-only dump of the source (mysqldump --single-transaction) into var/rehearsal/.
 * 2. Import into `picklers_upgrade_rehearsal` and snapshot row counts, id sets and
 *    money totals for every legacy table.
 * 3. Run every Doctrine migration (prod mode: no demo seeding), then reconcile:
 *    identical counts, ids and totals for all legacy tables; new tables present.
 * 4. Roll back to the baseline migration and verify the schema is back to the
 *    original columns with the data still identical.
 * 5. Restore the copy from the dump and verify again.
 * The source database is only ever READ. The dump is deleted at the end
 * (it contains personal data); pass --keep-dump to keep it.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$root = dirname(__DIR__);
$source = $argv[1] ?? 'picklers_db';
$keepDump = in_array('--keep-dump', $argv, true);
if (str_starts_with($source, '--')) {
    $source = 'picklers_db';
}
$target = 'picklers_upgrade_rehearsal';
$stateDir = $root . '/var/rehearsal/state';
$dumpFile = $root . '/var/rehearsal/' . $source . '-' . date('Ymd-His') . '.sql';
@mkdir($stateDir, 0750, true);
array_map('unlink', glob($stateDir . '/{,.}[!.]*', GLOB_BRACE) ?: []);

$env = [];
foreach (file($root . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
    [$k, $v] = array_map('trim', explode('=', $line, 2));
    $env[$k] = trim($v, "\"'");
}
$host = $env['DB_HOST'] ?? '127.0.0.1';
$port = (int)($env['DB_PORT'] ?? 3306);
$user = $env['DB_USERNAME'] ?? 'root';
$pass = $env['DB_PASSWORD'] ?? '';
$bin = getenv('MYSQL_BIN') ?: 'C:/xampp/mysql/bin';
$auth = sprintf('-h %s -P %d -u %s %s', escapeshellarg($host), $port, escapeshellarg($user), $pass !== '' ? '-p' . escapeshellarg($pass) : '');

$pdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$failures = 0;
$step = static function (string $label, bool $ok, string $detail = '') use (&$failures): void {
    printf("  %-62s %s%s\n", $label, $ok ? 'PASS' : 'FAIL', $detail !== '' ? "  ({$detail})" : '');
    if (!$ok) $failures++;
};
$sh = static function (string $cmd): array {
    exec($cmd . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
};

$legacyTables = ['users', 'facilities', 'courts', 'staff', 'matches', 'bookings', 'wallet_transactions', 'feed_posts', 'feed_likes',
    'facility_favorites', 'feed_comments', 'direct_messages', 'notifications', 'owner_applications', 'promo_codes', 'promo_redemptions',
    'court_images', 'amenities', 'facility_amenities', 'court_attributes', 'court_attribute_map', 'payout_requests'];
$snapshot = static function (string $db) use ($pdo, $legacyTables): array {
    $s = [];
    foreach ($legacyTables as $t) {
        $s["rows:{$t}"] = (int)$pdo->query("SELECT COUNT(*) FROM `{$db}`.`{$t}`")->fetchColumn();
    }
    $s['ids:users'] = md5(implode(',', $pdo->query("SELECT id FROM `{$db}`.users ORDER BY id")->fetchAll(PDO::FETCH_COLUMN)));
    $s['ids:bookings'] = md5(implode(',', $pdo->query("SELECT id FROM `{$db}`.bookings ORDER BY id")->fetchAll(PDO::FETCH_COLUMN)));
    $s['ids:facilities'] = md5(implode(',', $pdo->query("SELECT id FROM `{$db}`.facilities ORDER BY id")->fetchAll(PDO::FETCH_COLUMN)));
    $s['sum:wallet_balance'] = (string)$pdo->query("SELECT COALESCE(SUM(wallet_balance),0) FROM `{$db}`.users")->fetchColumn();
    $s['sum:booking_price'] = (string)$pdo->query("SELECT COALESCE(SUM(price),0) FROM `{$db}`.bookings")->fetchColumn();
    $s['sum:wallet_tx'] = (string)$pdo->query("SELECT CONCAT(COALESCE(SUM(CASE WHEN type='credit' THEN amount END),0),'/',COALESCE(SUM(CASE WHEN type='debit' THEN amount END),0)) FROM `{$db}`.wallet_transactions")->fetchColumn();
    $s['status:bookings'] = json_encode($pdo->query("SELECT status, COUNT(*) c FROM `{$db}`.bookings GROUP BY status ORDER BY status")->fetchAll(PDO::FETCH_KEY_PAIR));
    return $s;
};
$columns = static function (string $db) use ($pdo, $legacyTables): string {
    $in = "'" . implode("','", $legacyTables) . "'";
    return md5(implode('|', $pdo->query("SELECT CONCAT(table_name,'.',column_name) FROM information_schema.columns WHERE table_schema = '{$db}' AND table_name IN ({$in}) ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN)));
};
$console = static function (string $args) use ($root, $target, $sh): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/console') . " {$args} -n --env=prod --database={$target} --data-path=var/rehearsal/state";
    return $sh($cmd);
};

echo "Upgrade rehearsal: {$source} (read-only) -> {$target}\n";

// 1. Dump
[$code, $out] = $sh(escapeshellarg($bin . '/mysqldump') . " {$auth} --single-transaction --routines --triggers --hex-blob --default-character-set=utf8mb4 " . escapeshellarg($source) . ' > ' . escapeshellarg($dumpFile));
$step('read-only dump of source', $code === 0 && filesize($dumpFile) > 0, $code === 0 ? round(filesize($dumpFile) / 1024) . ' KiB' : $out);

// 2. Import into the disposable copy
$pdo->exec("DROP DATABASE IF EXISTS `{$target}`");
$pdo->exec("CREATE DATABASE `{$target}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
[$code, $out] = $sh(escapeshellarg($bin . '/mysql') . " {$auth} " . escapeshellarg($target) . ' < ' . escapeshellarg($dumpFile));
$step('import into disposable copy', $code === 0, $out);
$before = $snapshot($target);
$sourceSnap = $snapshot($source);
$step('copy matches source exactly', $before === $sourceSnap);
$colsBefore = $columns($target);

// 3. Migrate
[$code, $out] = $console('doctrine:migrations:migrate');
$step('doctrine:migrations:migrate', $code === 0, $code === 0 ? '' : $out);
$after = $snapshot($target);
$diff = array_keys(array_diff_assoc($before, $after));
$step('legacy rows, ids and money totals unchanged', $diff === [], $diff === [] ? count($before) . ' facts reconciled' : implode(', ', $diff));
$newTables = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '{$target}' AND table_name IN ('audit_events','admin_privileges','moderation_cases','moderation_case_events','notification_broadcasts','admin_activity_seen','doctrine_migration_versions')")->fetchColumn();
$step('admin tables created', $newTables === 7, "{$newTables}/7");
$active = (string)$pdo->query("SELECT COUNT(*) FROM `{$target}`.facilities WHERE operating_status = 'active'")->fetchColumn();
$step('existing facilities stay active', (int)$active === $after['rows:facilities'], "{$active} active");
$admins = (int)$pdo->query("SELECT COUNT(*) FROM `{$target}`.admin_privileges")->fetchColumn();
$step('no administrator auto-promoted to privileged', $admins === 0);
[$code, $out] = $console('doctrine:migrations:migrate');
$step('re-running migrations is a no-op', $code === 0 && $snapshot($target) === $after);

// 4. Roll back to baseline
[$code, $out] = $console('doctrine:migrations:migrate ' . escapeshellarg('Picklers\Migrations\Version20261005000001'));
$step('rollback to baseline (down migrations)', $code === 0, $code === 0 ? '' : $out);
$step('schema columns back to original', $columns($target) === $colsBefore);
$step('data identical after rollback', $snapshot($target) === $before);
$left = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '{$target}' AND table_name IN ('audit_events','admin_privileges','moderation_cases','moderation_case_events','notification_broadcasts','admin_activity_seen')")->fetchColumn();
$step('admin tables removed by rollback', $left === 0);

// 5. Restore from dump
$pdo->exec("DROP DATABASE `{$target}`");
$pdo->exec("CREATE DATABASE `{$target}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
[$code, $out] = $sh(escapeshellarg($bin . '/mysql') . " {$auth} " . escapeshellarg($target) . ' < ' . escapeshellarg($dumpFile));
$step('restore from dump', $code === 0 && $snapshot($target) === $sourceSnap && $columns($target) === $colsBefore);

$pdo->exec("DROP DATABASE IF EXISTS `{$target}`");
if (!$keepDump) {
    @unlink($dumpFile);
}
echo $failures === 0 ? "OK — upgrade, rollback and restore rehearsed; source untouched\n" : "FAILED — {$failures} check(s)\n";
exit($failures === 0 ? 0 : 1);
