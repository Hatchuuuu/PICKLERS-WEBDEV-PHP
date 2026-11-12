<?php
declare(strict_types=1);

/**
 * PICKLERS — Maintenance CLI
 *
 * Usage (from the project root):
 *   php scripts/maintenance.php report            Show data-integrity findings, change nothing
 *   php scripts/maintenance.php sweep-orphans     Delete rows referencing records that no longer exist
 *   php scripts/maintenance.php reset-trust       Reset legacy auto-granted verification badges
 *   php scripts/maintenance.php add-constraints   Apply foreign keys (requires a clean sweep first)
 *   php scripts/maintenance.php all               report -> sweep -> reset-trust -> add-constraints
 *
 * Every mutating command prints exactly what it changed.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI-only.\n");
}

$cmd = $argv[1] ?? 'report';
if (!in_array($cmd, ['report', 'sweep-orphans', 'reset-trust', 'add-constraints', 'all'], true)) {
    fwrite(STDERR, "Unknown command '{$cmd}'. Run without arguments for the report.\n");
    exit(2);
}

require dirname(__DIR__) . '/config/bootstrap.php';
$kernel = new \Picklers\Kernel(picklers_kernel_environment(), picklers_kernel_debug());
$kernel->boot();
$pdo = $kernel->getContainer()->get('doctrine')->getConnection()->getNativeConnection();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

/** Orphan definitions: child table -> the parent it must reference. */
const ORPHAN_RULES = [
    ['label' => 'courts without a facility',            'child' => 'courts',              'fk' => 'facility_id', 'parent' => 'facilities',  'pk' => 'id'],
    ['label' => 'staff without a facility',             'child' => 'staff',               'fk' => 'facility_id', 'parent' => 'facilities',  'pk' => 'id'],
    ['label' => 'matches without a facility',           'child' => 'matches',             'fk' => 'facility_id', 'parent' => 'facilities',  'pk' => 'id'],
    ['label' => 'bookings without a user',              'child' => 'bookings',            'fk' => 'user_id',     'parent' => 'users',       'pk' => 'id'],
    ['label' => 'bookings without a facility',          'child' => 'bookings',            'fk' => 'facility_id', 'parent' => 'facilities',  'pk' => 'id'],
    ['label' => 'wallet transactions without a user',   'child' => 'wallet_transactions', 'fk' => 'user_id',     'parent' => 'users',       'pk' => 'id'],
    ['label' => 'notifications without a user',         'child' => 'notifications',       'fk' => 'user_id',     'parent' => 'users',       'pk' => 'id'],
    ['label' => 'feed comments without a post',         'child' => 'feed_comments',       'fk' => 'post_id',     'parent' => 'feed_posts',  'pk' => 'id'],
    ['label' => 'feed likes without a post',            'child' => 'feed_likes',          'fk' => 'post_id',     'parent' => 'feed_posts',  'pk' => 'id'],
    ['label' => 'owner applications without a user',    'child' => 'owner_applications',  'fk' => 'user_id',     'parent' => 'users',       'pk' => 'id'],
];

/** Foreign keys applied once the data is clean. */
const FK_RULES = [
    ['name' => 'fk_courts_facility',     'sql' => "ALTER TABLE courts ADD CONSTRAINT fk_courts_facility FOREIGN KEY (facility_id) REFERENCES facilities(id) ON DELETE CASCADE"],
    ['name' => 'fk_staff_facility',      'sql' => "ALTER TABLE staff ADD CONSTRAINT fk_staff_facility FOREIGN KEY (facility_id) REFERENCES facilities(id) ON DELETE CASCADE"],
    ['name' => 'fk_matches_facility',    'sql' => "ALTER TABLE matches ADD CONSTRAINT fk_matches_facility FOREIGN KEY (facility_id) REFERENCES facilities(id) ON DELETE CASCADE"],
    // Bookings are financial records: never silently cascade them away.
    ['name' => 'fk_bookings_user',       'sql' => "ALTER TABLE bookings ADD CONSTRAINT fk_bookings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT"],
    ['name' => 'fk_bookings_facility',   'sql' => "ALTER TABLE bookings ADD CONSTRAINT fk_bookings_facility FOREIGN KEY (facility_id) REFERENCES facilities(id) ON DELETE RESTRICT"],
    // Wallet history is a financial audit trail — protect it. Accounts are
    // retired via soft-delete (see admin_delete_user), never hard-deleted.
    ['name' => 'fk_wallet_user',         'sql' => "ALTER TABLE wallet_transactions ADD CONSTRAINT fk_wallet_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT"],
    ['name' => 'fk_notif_user',          'sql' => "ALTER TABLE notifications ADD CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE"],
    ['name' => 'fk_comments_post',       'sql' => "ALTER TABLE feed_comments ADD CONSTRAINT fk_comments_post FOREIGN KEY (post_id) REFERENCES feed_posts(id) ON DELETE CASCADE"],
    ['name' => 'fk_likes_post',          'sql' => "ALTER TABLE feed_likes ADD CONSTRAINT fk_likes_post FOREIGN KEY (post_id) REFERENCES feed_posts(id) ON DELETE CASCADE"],
    ['name' => 'fk_apps_user',           'sql' => "ALTER TABLE owner_applications ADD CONSTRAINT fk_apps_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE"],
];

function orphanCountSql(array $r): string {
    return "SELECT COUNT(*) c FROM `{$r['child']}` ch
            LEFT JOIN `{$r['parent']}` p ON ch.`{$r['fk']}` = p.`{$r['pk']}`
            WHERE ch.`{$r['fk']}` IS NOT NULL AND p.`{$r['pk']}` IS NULL";
}

function cmdReport(PDO $pdo): int {
    echo "── Data integrity report ──────────────────────────────\n";
    $totalOrphans = 0;
    foreach (ORPHAN_RULES as $r) {
        $n = (int)$pdo->query(orphanCountSql($r))->fetch()['c'];
        $totalOrphans += $n;
        printf("  %-38s %s\n", $r['label'], $n > 0 ? "{$n} orphan(s)" : 'clean');
    }

    echo "\n── Trust badges ───────────────────────────────────────\n";
    $legacy = (int)$pdo->query(
        "SELECT COUNT(*) c FROM users
         WHERE verification_status = 'verified' AND is_admin = 0 AND is_owner = 0"
    )->fetch()['c'];
    printf("  %-38s %d\n", 'auto-verified plain players', $legacy);

    echo "\n── Role/flag consistency ──────────────────────────────\n";
    $mismatch = $pdo->query(
        "SELECT id, name, role, is_admin, is_owner FROM users
         WHERE (role='player' AND (is_admin=1 OR is_owner=1))
            OR (role='admin'  AND is_admin=0)
            OR (role='owner'  AND is_owner=0)"
    )->fetchAll();
    if (!$mismatch) {
        echo "  all roles agree with their flags\n";
    } else {
        foreach ($mismatch as $m) {
            printf("  %-20s role=%-7s is_admin=%d is_owner=%d\n", $m['id'], $m['role'], $m['is_admin'], $m['is_owner']);
        }
    }

    echo "\n── Foreign keys ───────────────────────────────────────\n";
    $existing = existingConstraints($pdo);
    foreach (FK_RULES as $fk) {
        printf("  %-38s %s\n", $fk['name'], in_array($fk['name'], $existing, true) ? 'present' : 'MISSING');
    }
    echo "\n";
    return $totalOrphans + count($mismatch) + count(array_diff(array_column(FK_RULES, 'name'), $existing));
}

function existingConstraints(PDO $pdo): array {
    $stmt = $pdo->query(
        "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
    );
    return array_column($stmt->fetchAll(), 'CONSTRAINT_NAME');
}

function cmdSweepOrphans(PDO $pdo): void {
    echo "── Sweeping orphans ───────────────────────────────────\n";
    $removed = 0;
    foreach (ORPHAN_RULES as $r) {
        $sql = "DELETE ch FROM `{$r['child']}` ch
                LEFT JOIN `{$r['parent']}` p ON ch.`{$r['fk']}` = p.`{$r['pk']}`
                WHERE ch.`{$r['fk']}` IS NOT NULL AND p.`{$r['pk']}` IS NULL";
        $n = $pdo->exec($sql);
        if ($n > 0) {
            printf("  %-38s removed %d\n", $r['label'], $n);
            $removed += $n;
        }
    }
    echo $removed === 0 ? "  nothing to remove\n" : "  total removed: {$removed}\n";
    echo "\n";
}

function cmdResetTrust(PDO $pdo): void {
    echo "── Resetting legacy trust badges ──────────────────────\n";
    // Every account was previously created with verification_status='verified'
    // hardcoded, so the badge carries no signal. Admins and approved facility
    // owners were vetted through their own flows and keep their status.
    $n = $pdo->exec(
        "UPDATE users SET verification_status = 'unverified'
         WHERE verification_status = 'verified' AND is_admin = 0 AND is_owner = 0"
    );
    echo "  plain players reset to 'unverified': {$n}\n";
    echo "  admins and facility owners left untouched\n\n";
}

function cmdAddConstraints(PDO $pdo): int {
    echo "── Applying foreign keys ──────────────────────────────\n";
    $existing = existingConstraints($pdo);
    $added = 0;
    $failed = 0;
    foreach (FK_RULES as $fk) {
        if (in_array($fk['name'], $existing, true)) {
            printf("  %-38s already present\n", $fk['name']);
            continue;
        }
        try {
            $pdo->exec($fk['sql']);
            printf("  %-38s added\n", $fk['name']);
            $added++;
        } catch (\Throwable $e) {
            $failed++;
            printf("  %-38s FAILED: %s\n", $fk['name'], $e->getMessage());
        }
    }
    echo "  constraints added: {$added}\n\n";
    return $failed > 0 || array_diff(array_column(FK_RULES, 'name'), existingConstraints($pdo)) !== [] ? 1 : 0;
}

$exitCode = 0;

switch ($cmd) {
    case 'report':
        $exitCode = cmdReport($pdo) > 0 ? 1 : 0;
        break;
    case 'sweep-orphans':
        cmdSweepOrphans($pdo);
        break;
    case 'reset-trust':
        cmdResetTrust($pdo);
        break;
    case 'add-constraints':
        $exitCode = cmdAddConstraints($pdo);
        break;
    case 'all':
        cmdReport($pdo);
        cmdSweepOrphans($pdo);
        cmdResetTrust($pdo);
        $exitCode = cmdAddConstraints($pdo);
        echo "── Post-run verification ──────────────────────────────\n";
        $exitCode = cmdReport($pdo) > 0 ? 1 : $exitCode;
        break;
}
exit($exitCode);
