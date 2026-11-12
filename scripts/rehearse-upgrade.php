<?php
declare(strict_types=1);

/**
 * Upgrade + rollback + restore rehearsal on a DISPOSABLE copy of a database.
 *
 *   php scripts/rehearse-upgrade.php [source_db]        (default: picklers_db)
 *
 * 1. Read-only dump of the source (mysqldump --single-transaction) into var/rehearsal/.
 * 2. Import into `picklers_upgrade_rehearsal` and snapshot legacy row counts,
 *    selected id sets, money totals and booking statuses; rehearse tournament JSON.
 * 3. Install base schema and run every Doctrine migration (prod mode: no demo seeding), then reconcile:
 *    the measured facts stay unchanged; new tables are present.
 * 4. Roll back to the baseline migration and verify the schema is back to the
 *    installed base columns with the measured data facts unchanged.
 * 5. Restore the copy from the dump and verify again.
 * The source database is only ever READ. The dump is deleted at the end
 * (it contains personal data); pass --keep-dump to keep it.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$root = dirname(__DIR__);
require $root . '/config/bootstrap.php';
$source = $argv[1] ?? 'picklers_db';
$keepDump = in_array('--keep-dump', $argv, true);
if (str_starts_with($source, '--')) {
    $source = 'picklers_db';
}
$target = 'picklers_upgrade_rehearsal';
if (!preg_match('/^[A-Za-z0-9_]+$/D', $source) || $source === $target) {
    fwrite(STDERR, "Source must be a valid database identifier different from the disposable rehearsal target.\n");
    exit(1);
}
$stateDir = $root . '/var/rehearsal/state';
$dumpFile = $root . '/var/rehearsal/' . $source . '-' . date('Ymd-His') . '.sql';
@mkdir($stateDir, 0750, true);

$env = $_ENV;
$host = $env['DB_HOST'] ?? '127.0.0.1';
$port = (int)($env['DB_PORT'] ?? 3306);
$user = $env['DB_USERNAME'] ?? 'root';
$pass = $env['DB_PASSWORD'] ?? '';
$bin = getenv('MYSQL_BIN') ?: (PHP_OS_FAMILY === 'Windows' ? 'C:/xampp/mysql/bin' : '/usr/bin');
$auth = sprintf('-h %s -P %d -u %s %s', escapeshellarg($host), $port, escapeshellarg($user), $pass !== '' ? '-p' . escapeshellarg($pass) : '');

$failures = 0;
$pdo = null;
$step = static function (string $label, bool $ok, string $detail = '') use (&$failures): void {
    printf("  %-62s %s%s\n", $label, $ok ? 'PASS' : 'FAIL', $detail !== '' ? "  ({$detail})" : '');
    if (!$ok) {
        $failures++;
        throw new RuntimeException($label . ' failed.');
    }
};
$sh = static function (string $cmd): array {
    exec($cmd . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
};

$legacyTables = ['users', 'facilities', 'courts', 'staff', 'matches', 'bookings', 'wallet_transactions', 'feed_posts', 'feed_likes',
    'facility_favorites', 'feed_comments', 'direct_messages', 'notifications', 'owner_applications', 'promo_codes', 'promo_redemptions',
    'court_images', 'amenities', 'facility_amenities', 'court_attributes', 'court_attribute_map', 'payout_requests'];
$snapshot = static function (string $db) use (&$pdo, $legacyTables): array {
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
$columns = static function (string $db) use (&$pdo, $legacyTables): string {
    $in = "'" . implode("','", $legacyTables) . "'";
    return md5(implode('|', $pdo->query("SELECT CONCAT(table_name,'.',column_name) FROM information_schema.columns WHERE table_schema = '{$db}' AND table_name IN ({$in}) ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN)));
};
$console = static function (string $args) use ($root, $target, $sh): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/console') . " {$args} -n --env=prod --database={$target} --data-path=var/rehearsal/state";
    return $sh($cmd);
};

echo "Upgrade rehearsal: {$source} (read-only) -> {$target}\n";

$targetTouched = false;
try {
require_once $root . '/vendor/autoload.php';
$pdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
array_map('unlink', glob($stateDir . '/{,.}[!.]*', GLOB_BRACE) ?: []);

// 1. Dump
[$code, $out] = $sh(escapeshellarg($bin . '/mysqldump') . " {$auth} --single-transaction --routines --triggers --hex-blob --default-character-set=utf8mb4 " . escapeshellarg($source) . ' > ' . escapeshellarg($dumpFile));
$dumpSize = is_file($dumpFile) ? filesize($dumpFile) : 0;
$step('read-only dump of source', $code === 0 && $dumpSize > 0, $code === 0 ? round($dumpSize / 1024) . ' KiB' : $out);

// 2. Import into the disposable copy
$targetTouched = true;
$pdo->exec("DROP DATABASE IF EXISTS `{$target}`");
$pdo->exec("CREATE DATABASE `{$target}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
[$code, $out] = $sh(escapeshellarg($bin . '/mysql') . " {$auth} " . escapeshellarg($target) . ' < ' . escapeshellarg($dumpFile));
$step('import into disposable copy', $code === 0, $out);
$before = $snapshot($target);
$sourceSnap = $snapshot($source);
$step('copy matches measured source counts, ids and totals', $before === $sourceSnap);
$colsBefore = $columns($target);
$catalogBefore = $pdo->query("SELECT * FROM `{$target}`.court_attributes ORDER BY id")->fetchAll();
$mapBefore = $pdo->query("SELECT * FROM `{$target}`.court_attribute_map ORDER BY court_id, attribute_id")->fetchAll();
$untaggedCourts = $pdo->query("SELECT c.id, c.type, c.surface, f.type AS facility_type FROM `{$target}`.courts c
    JOIN `{$target}`.facilities f ON f.id = c.facility_id
    LEFT JOIN `{$target}`.court_attribute_map m ON m.court_id = c.id WHERE m.court_id IS NULL")->fetchAll();

// A SQL dump cannot include the legacy tournament file; use an isolated copy.
$sourceJson = $root . '/database/tournaments.json';
$tournamentJson = $stateDir . '/tournaments.json';
$json = is_file($sourceJson) ? file_get_contents($sourceJson) : json_encode([
    ['id' => 'rehearsal_tournament', 'facility_id' => '1', 'title' => 'Rehearsal fixture',
        'teams' => [['id' => 'team_a', 'players' => ['Player A', 'Player B']]],
        'bracket' => ['rounds' => [['winner' => 'team_a', 'score' => [11, 7]]]]],
]);
$records = json_decode((string)$json, true, 512, JSON_THROW_ON_ERROR);
$step('tournament JSON is a record list', is_array($records) && array_is_list($records));
$step('isolated tournament JSON copied', file_put_contents($tournamentJson, $json) !== false,
    is_file($sourceJson) ? 'source file copied read-only' : 'synthetic nested fixture');
$expectedTournaments = [];
foreach ($records as $record) {
    $id = is_array($record) ? (string)($record['id'] ?? '') : '';
    if ($id !== '' && !isset($expectedTournaments[$id])) $expectedTournaments[$id] = $record;
}
// A retry preserves existing SQL rows, then imports file records still missing.
$hasTournamentData = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = '{$target}' AND table_name = 'tournaments' AND column_name = 'data'")->fetchColumn();
if ($hasTournamentData) {
    $existingTournaments = [];
    foreach ($pdo->query("SELECT data FROM `{$target}`.tournaments ORDER BY seq")->fetchAll(PDO::FETCH_COLUMN) as $row) {
        $record = json_decode($row, true, 512, JSON_THROW_ON_ERROR);
        $existingTournaments[(string)$record['id']] = $record;
    }
    $expectedTournaments = $existingTournaments + $expectedTournaments;
}
$expectedTournaments = array_values($expectedTournaments);

// 3. Install the base schema, then migrate; production mode never adds demo seeds.
[$code, $out] = $console('picklers:schema:install');
$step('picklers:schema:install', $code === 0, $code === 0 ? '' : $out);
$installed = $snapshot($target);
$installDiff = array_keys(array_diff_assoc($before, $installed));
$unexpectedDiff = array_values(array_diff($installDiff, ['rows:court_attributes', 'rows:court_attribute_map']));
$step('base installation preserves measured non-attribute facts', $unexpectedDiff === [],
    $unexpectedDiff === [] ? (count($before) - 2) . ' facts reconciled' : count($unexpectedDiff) . ' changed facts: ' . implode(', ', $unexpectedDiff));
$catalogAfter = $pdo->query("SELECT * FROM `{$target}`.court_attributes ORDER BY id")->fetchAll();
$mapAfter = $pdo->query("SELECT * FROM `{$target}`.court_attribute_map ORDER BY court_id, attribute_id")->fetchAll();
$catalogById = array_column($catalogAfter, null, 'id');
$originalAttributesKept = true;
foreach ($catalogBefore as $row) {
    if (($catalogById[$row['id']] ?? null) !== $row) $originalAttributesKept = false;
}
$mapKeys = static fn(array $rows): array => array_map(static fn($row) => $row['court_id'] . '|' . $row['attribute_id'], $rows);
$expectedMapKeys = $mapKeys($mapBefore);
$slugIds = array_column($catalogAfter, 'id', 'slug');
foreach ($untaggedCourts as $court) {
    foreach (\Picklers\Persistence\SchemaInstaller::deriveCourtAttributeSlugsFromLegacyFields($court['type'], $court['surface'], $court['facility_type']) as $slug) {
        if (isset($slugIds[$slug])) $expectedMapKeys[] = $court['id'] . '|' . $slugIds[$slug];
    }
}
$actualMapKeys = $mapKeys($mapAfter);
sort($expectedMapKeys);
sort($actualMapKeys);
$originalCatalogIds = array_column($catalogBefore, 'id');
$newCatalogExpected = true;
foreach ($catalogAfter as $row) {
    if (!in_array($row['id'], $originalCatalogIds, true)
        && !in_array($row['slug'], ['indoor', 'outdoor', 'covered', 'open-air', 'air-conditioned', 'night-lighting', 'show-court'], true)) $newCatalogExpected = false;
}
$step('attribute originals retained; only expected tags added', $originalAttributesKept && $newCatalogExpected && $expectedMapKeys === $actualMapKeys,
    (count($catalogAfter) - count($catalogBefore)) . ' catalog additions, ' . (count($mapAfter) - count($mapBefore)) . ' derived court tags');
$before = $installed;
$colsInstalled = $columns($target);
[$code, $out] = $console('doctrine:migrations:migrate');
$step('doctrine:migrations:migrate', $code === 0, $code === 0 ? '' : $out);
$importedTournaments = array_map(static fn($row) => json_decode($row, true, 512, JSON_THROW_ON_ERROR),
    $pdo->query("SELECT data FROM `{$target}`.tournaments ORDER BY seq")->fetchAll(PDO::FETCH_COLUMN));
$step('tournament records preserved in SQL', $importedTournaments === $expectedTournaments,
    count($expectedTournaments) . ' records, including nested fields');
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
$step('tournament rollback restores JSON records', json_decode((string)file_get_contents($tournamentJson), true, 512, JSON_THROW_ON_ERROR) === $expectedTournaments);
$step('schema columns back to installed base', $columns($target) === $colsInstalled);
$step('measured data facts unchanged after rollback', $snapshot($target) === $before);
$left = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '{$target}' AND table_name IN ('audit_events','admin_privileges','moderation_cases','moderation_case_events','notification_broadcasts','admin_activity_seen')")->fetchColumn();
$step('admin tables removed by rollback', $left === 0);

// 5. Restore from dump
$pdo->exec("DROP DATABASE `{$target}`");
$pdo->exec("CREATE DATABASE `{$target}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
[$code, $out] = $sh(escapeshellarg($bin . '/mysql') . " {$auth} " . escapeshellarg($target) . ' < ' . escapeshellarg($dumpFile));
$step('restore from dump', $code === 0 && $snapshot($target) === $sourceSnap && $columns($target) === $colsBefore);
$step('source counts, ids, totals and columns remain unchanged', $snapshot($source) === $sourceSnap && $columns($source) === $colsBefore);

} catch (Throwable $e) {
    if ($failures === 0) $failures++;
    fwrite(STDERR, 'Rehearsal stopped: ' . $e->getMessage() . "\n");
} finally {
    if ($targetTouched && $pdo !== null) {
        try {
            $pdo->exec("DROP DATABASE IF EXISTS `{$target}`");
        } catch (Throwable $e) {
            $failures++;
            fwrite(STDERR, 'Could not remove disposable database: ' . $e->getMessage() . "\n");
        }
    }
    if (!$keepDump && is_file($dumpFile) && !unlink($dumpFile)) {
        $failures++;
        fwrite(STDERR, "Could not remove dump: {$dumpFile}\n");
    }
    foreach (glob($stateDir . '/{,.}[!.]*', GLOB_BRACE) ?: [] as $stateFile) {
        if (is_file($stateFile) && !unlink($stateFile)) {
            $failures++;
            fwrite(STDERR, "Could not remove rehearsal state: {$stateFile}\n");
        }
    }
}
echo $failures === 0 ? "OK — upgrade, rollback and restore rehearsed; source untouched\n" : "FAILED — {$failures} check(s)\n";
exit($failures === 0 ? 0 : 1);
