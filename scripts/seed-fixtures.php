<?php
declare(strict_types=1);

/**
 * PICKLERS — deterministic synthetic fixtures for browser and integration tests.
 *
 *   php scripts/seed-fixtures.php picklers_e2e
 *
 * Refuses to run against any database whose name does not end in `_e2e` or
 * `_test`, so it can never touch the live `picklers_db`. Every account, booking,
 * wallet movement, application and post below is synthetic. The fixture
 * password is a test value for these fixture accounts only.
 *
 * Re-running is safe: fixture rows (ids prefixed `fx_` / `usr_fx_` / `FX-`) are
 * deleted and recreated; non-fixture rows are left alone.
 */

const FIXTURE_PASSWORD = 'Fixture-Pass-2026';

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$dbName = $argv[1] ?? '';
if (!preg_match('/^[a-z0-9_]+_(e2e|test)$/', $dbName)) {
    fwrite(STDERR, "Refusing to seed \"{$dbName}\": only *_e2e or *_test databases may receive fixtures.\n");
    exit(1);
}

$root = dirname(__DIR__);
date_default_timezone_set('Asia/Manila');
$env = [];
foreach (file($root . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
    [$k, $v] = array_map('trim', explode('=', $line, 2));
    $env[$k] = trim($v, "\"'");
}

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $env['DB_HOST'] ?? '127.0.0.1', (int)($env['DB_PORT'] ?? 3306), $dbName),
    $env['DB_USERNAME'] ?? 'root',
    $env['DB_PASSWORD'] ?? '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$hash = password_hash(FIXTURE_PASSWORD, PASSWORD_DEFAULT);
$now  = new DateTimeImmutable('now');
$day  = static fn(int $offset): DateTimeImmutable => $now->setTime(0, 0)->modify(($offset >= 0 ? '+' : '') . $offset . ' days');

$pdo->beginTransaction();
try {
    // ---- Clean previous fixture rows ------------------------------------
    $pdo->exec("DELETE FROM users WHERE id LIKE 'usr_fx_%'");
    $pdo->exec("DELETE FROM bookings WHERE id LIKE 'FX-%'");
    $pdo->exec("DELETE FROM wallet_transactions WHERE id LIKE 'fx_%'");
    $pdo->exec("DELETE FROM owner_applications WHERE id LIKE 'fx_%'");
    $pdo->exec("DELETE FROM feed_posts WHERE id LIKE 'fx_%'");
    $pdo->exec("DELETE FROM feed_comments WHERE id LIKE 'fx_%'");
    $pdo->exec("DELETE FROM notifications WHERE id LIKE 'fx_%'");
    $pdo->exec("DELETE FROM promo_codes WHERE id LIKE 'fx_%'");
    $pdo->exec("DELETE FROM promo_redemptions WHERE id LIKE 'fx_%'");
    $pdo->exec("DELETE FROM courts WHERE facility_id IN (SELECT id FROM facilities WHERE owner_id LIKE 'usr_fx_%')");
    $pdo->exec("DELETE FROM facilities WHERE owner_id LIKE 'usr_fx_%'");

    // ---- Users -------------------------------------------------------------
    $users = [
        // id, name, email, role, is_admin, is_owner, verification, wallet
        ['usr_fx_admin',  'Fixture Admin',          'admin@fixture.test',   'admin',  1, 0, 'verified',   0.00],
        ['usr_fx_root',   'Fixture Privileged',     'root@fixture.test',    'admin',  1, 0, 'verified',   0.00],
        ['usr_fx_owner1', 'Olivia Fixture-Owner',   'owner1@fixture.test',  'owner',  0, 1, 'verified', 250.00],
        ['usr_fx_owner2', 'Oscar Fixture-Owner',    'owner2@fixture.test',  'owner',  0, 1, 'verified',   0.00],
        ['usr_fx_p1',     'Paula Player',           'p1@fixture.test',      'player', 0, 0, 'verified', 1200.00],
        ['usr_fx_p2',     'Pedro Player',           'p2@fixture.test',      'player', 0, 0, 'unverified', 80.50],
        ['usr_fx_p3',     'Maria Concepcion Dela Cruz-Villanueva de los Santos', 'p3@fixture.test', 'player', 0, 0, 'pending', 0.00],
        ['usr_fx_p4',     'Rico Applicant',         'p4@fixture.test',      'player', 0, 0, 'unverified', 0.00],
        ['usr_fx_p5',     'Sara Applicant',         'p5@fixture.test',      'player', 0, 0, 'unverified', 15.00],
        ['usr_fx_gone',   'Deactivated Fixture',    'deleted_usr_fx_gone@picklers.invalid', 'deleted', 0, 0, 'unverified', 0.00],
    ];
    $insUser = $pdo->prepare(
        "INSERT INTO users (id, name, email, phone, password_hash, role, verification_status, avatar_url, level, wallet_balance, is_admin, is_dev, is_owner, created_at)
         VALUES (?,?,?,?,?,?,?,'', 'Intermediate 3.5', ?, ?, 0, ?, ?)"
    );
    foreach ($users as $i => [$id, $name, $email, $role, $isAdmin, $isOwner, $ver, $wallet]) {
        $insUser->execute([$id, $name, $email, sprintf('+63 917 000 %04d', $i), $hash, $role, $ver, $wallet, $isAdmin, $isOwner, $day(-30 + $i)->format('Y-m-d 09:00:00')]);
    }

    // ---- Facilities & courts owned by fixture owners -------------------------
    $insFac = $pdo->prepare(
        "INSERT INTO facilities (owner_id, name, location, rating, reviews, price, price_numeric, type, hours, transit, image, courts_count, is_verified)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1)"
    );
    $insFac->execute(['usr_fx_owner1', 'Fixture Arena Dumaguete', 'Rizal Blvd, Dumaguete City', 4.6, 12, '₱300', 300.00, 'Indoor', '6:00 AM - 10:00 PM', '', 'assets/images/facilities/overhead_dumaguete.jpg', 3]);
    $fac1 = (int)$pdo->lastInsertId();
    $insFac->execute(['usr_fx_owner2', 'Fixture Courts Valencia', 'Valencia, Negros Oriental', 0.0, 0, '₱0', 0.00, 'Outdoor', '7:00 AM - 9:00 PM', '', 'assets/images/facilities/pickle_park_dumaguete.jpg', 1]);
    $fac2 = (int)$pdo->lastInsertId();

    $insCourt = $pdo->prepare("REPLACE INTO courts (id, facility_id, name, surface, type, price, status) VALUES (?,?,?,?,?,?,?)");
    $insCourt->execute(["crt_{$fac1}_1", $fac1, 'Court 1', 'Hard Court', 'Indoor', 300.00, 'available']);
    $insCourt->execute(["crt_{$fac1}_2", $fac1, 'Court 2', 'Hard Court', 'Indoor', 350.00, 'available']);
    $insCourt->execute(["crt_{$fac1}_3", $fac1, 'Court 3', 'Cushion',    'Indoor', 400.00, 'maintenance']);
    $insCourt->execute(["crt_{$fac2}_1", $fac2, 'Court 1', 'Hard Court', 'Outdoor', 250.00, 'available']);

    // ---- Bookings (all channels / statuses / payment methods) --------------
    $insBk = $pdo->prepare(
        "INSERT INTO bookings (id, user_id, facility_id, court_id, facility_name, court_name, date, time, booking_date, start_min, end_min, duration, price, payment_method, status, is_new, created_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,'1 Hour',?,?,?,0,?)"
    );
    $bookings = [
        // id, user, facility, court#, dayOffset, startHour, price, method, status, createdOffset
        ['FX-PEND01', 'usr_fx_p1', $fac1, 1,  3,  8, '300.00', 'Pickle Credits', 'pending',   -1],
        ['FX-PEND02', 'usr_fx_p2', $fac1, 2,  4, 18, '350.00', 'GCash',          'pending',   -1],
        ['FX-CONF01', 'usr_fx_p1', $fac1, 1,  5, 10, '300.00', 'Pickle Credits', 'confirmed', -2],
        ['FX-CONF02', 'usr_fx_p3', $fac1, 2,  6, 16, '350.00', 'Pay at Venue',   'confirmed', -2],
        ['FX-CONF03', 'usr_fx_p2', $fac2, 1,  2,  7, '250.00', 'Maya',           'confirmed', -3],
        ['FX-DONE01', 'usr_fx_p1', $fac1, 1, -5, 9,  '300.00', 'Pickle Credits', 'completed', -7],
        ['FX-DONE02', 'usr_fx_p3', $fac2, 1, -4, 17, '250.00', 'gcash',          'completed', -6],
        ['FX-CANC01', 'usr_fx_p2', $fac1, 2, -2, 19, '350.00', 'Pickle Credits', 'cancelled', -9],
        ['FX-CANC02', 'usr_fx_p1', $fac2, 1,  1, 20, '250.00', 'GCash',          'cancelled', -8],
    ];
    foreach ($bookings as [$id, $uid, $fid, $courtNo, $off, $h, $price, $method, $status, $created]) {
        $d = $day($off);
        $time = sprintf('%d:00 %s - %d:00 %s', (($h + 11) % 12) + 1, $h < 12 ? 'AM' : 'PM', (($h + 12) % 12) + 1, ($h + 1) < 12 ? 'AM' : 'PM');
        $fname = $fid === $fac1 ? 'Fixture Arena Dumaguete' : 'Fixture Courts Valencia';
        $insBk->execute([$id, $uid, $fid, "crt_{$fid}_{$courtNo}", $fname, "Court {$courtNo}", $d->format('D, M j, Y'), $time,
            $d->format('Y-m-d'), $h * 60, ($h + 1) * 60, $price, $method, $status, $day($created)->format('Y-m-d 14:30:00')]);
    }

    // ---- Wallet movements ---------------------------------------------------
    $insTx = $pdo->prepare("INSERT INTO wallet_transactions (id, user_id, type, amount, label, date, created_at) VALUES (?,?,?,?,?,?,?)");
    $txs = [
        ['fx_tx01', 'usr_fx_p1', 'credit', '2000.00', 'GCash Top-Up',                                 -20],
        ['fx_tx02', 'usr_fx_p1', 'debit',  '300.00',  'Booking #FX-DONE01 at Fixture Arena Dumaguete via Pickle Credits', -7],
        ['fx_tx03', 'usr_fx_p1', 'debit',  '300.00',  'Booking #FX-CONF01 at Fixture Arena Dumaguete via Pickle Credits', -2],
        ['fx_tx04', 'usr_fx_p1', 'debit',  '300.00',  'Booking #FX-PEND01 at Fixture Arena Dumaguete via Pickle Credits', -1],
        ['fx_tx05', 'usr_fx_p2', 'credit', '500.00',  'Maya',                                         -15],
        ['fx_tx06', 'usr_fx_p2', 'debit',  '350.00',  'Booking #FX-CANC01 at Fixture Arena Dumaguete via Pickle Credits', -9],
        ['fx_tx07', 'usr_fx_p2', 'credit', '350.00',  'Full Refund for Cancelled Booking #FX-CANC01',  -8],
        ['fx_tx08', 'usr_fx_p2', 'debit',  '419.50',  '[Admin] Correction for duplicate top-up',       -5],
        ['fx_tx09', 'usr_fx_p5', 'credit', '15.00',   '[Admin] Goodwill credit',                       -3],
    ];
    foreach ($txs as [$id, $uid, $type, $amt, $label, $off]) {
        $d = $day($off);
        $insTx->execute([$id, $uid, $type, $amt, $label, $d->format('M j, Y'), $d->format('Y-m-d 11:15:00')]);
    }

    // ---- Owner applications (documents live in isolated fixture storage) ----
    $insApp = $pdo->prepare(
        "INSERT INTO owner_applications (id, user_id, facility_name, address, latitude, longitude, courts_count, court_surface, operating_hours, owner_name, business_email, phone, entity_name, reg_number, permit_file, gov_id_file, status, created_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
    );
    $apps = [
        ['fx_app_pending1', 'usr_fx_p4', 'Rico Smash Hub', 'Bacong, Negros Oriental', 9.2465, 123.2950, 2, 'Hard Court', '6:00 AM – 10:00 PM', 'Rico Applicant', 'rico@fixture.test', '+63 917 555 0101', 'Rico Sports Inc.', 'DTI-FX-0001', 'fx_permit_rico.png', 'fx_govid_rico.png', 'pending_review', -2],
        ['fx_app_pending2', 'usr_fx_p5', 'Sara Pickle Barn', 'Sibulan, Negros Oriental', null, null, 4, 'Cushion', '7:00 AM – 9:00 PM', 'Sara Applicant', 'sara@fixture.test', '+63 917 555 0102', 'Sara Leisure OPC', 'SEC-FX-0002', 'fx_permit_missing.pdf', '', 'pending_review', -1],
        ['fx_app_rejected', 'usr_fx_p2', 'Pedro Court Lot', 'Tanjay City', null, null, 1, 'Concrete', '8:00 AM – 5:00 PM', 'Pedro Player', 'p2@fixture.test', '+63 917 555 0103', 'Pedro Courts', 'DTI-FX-0003', '', '', 'rejected', -12],
        ['fx_app_approved', 'usr_fx_owner1', 'Fixture Arena Dumaguete', 'Rizal Blvd, Dumaguete City', null, null, 3, 'Hard Court', '6:00 AM – 10:00 PM', 'Olivia Fixture-Owner', 'owner1@fixture.test', '+63 917 555 0104', 'Olivia Arenas Corp.', 'SEC-FX-0004', '', '', 'approved', -25],
    ];
    foreach ($apps as $a) {
        $a[17] = $day($a[17])->format('Y-m-d 10:00:00');
        $insApp->execute($a);
    }

    // ---- Community content ----------------------------------------------------
    $insPost = $pdo->prepare("INSERT INTO feed_posts (id, author_id, author_name, author_avatar, author_level, content, post_type, like_count, comment_count, created_at) VALUES (?,?,?,'','Intermediate 3.5',?, 'text', 0, ?, ?)");
    $insPost->execute(['fx_post1', 'usr_fx_p1', 'Paula Player', 'Looking for doubles partners this Saturday at Fixture Arena!', 1, $day(-1)->format('Y-m-d 08:00:00')]);
    $insPost->execute(['fx_post2', 'usr_fx_p2', 'Pedro Player', 'Selling used paddles cheap — DM me, totally legit, wire money first.', 1, $day(-1)->format('Y-m-d 09:00:00')]);
    $insPost->execute(['fx_post3', 'usr_fx_p3', 'Maria Concepcion Dela Cruz-Villanueva de los Santos', 'Great session today, thanks everyone.', 0, $day(0)->format('Y-m-d 07:30:00')]);
    $insCom = $pdo->prepare("INSERT INTO feed_comments (id, post_id, user_id, author_name, author_avatar, comment, created_at) VALUES (?,?,?,?, '', ?, ?)");
    $insCom->execute(['fx_com1', 'fx_post1', 'usr_fx_p3', 'Maria Concepcion Dela Cruz-Villanueva de los Santos', 'Count me in!', $day(-1)->format('Y-m-d 08:30:00')]);
    $insCom->execute(['fx_com2', 'fx_post2', 'usr_fx_p1', 'Paula Player', 'This looks like a scam, careful everyone.', $day(-1)->format('Y-m-d 09:30:00')]);

    // ---- Promo codes ----------------------------------------------------------
    $insPromo = $pdo->prepare("INSERT INTO promo_codes (id, code, discount_type, discount_value, min_spend, usage_limit, times_used, user_limit, expires_at, status, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
    $insPromo->execute(['fx_promo_live',  'FXLIVE50',  'fixed',      '50.00', '200.00', 10, 1, 1, $day(30)->format('Y-m-d 23:59:59'), 'active', $day(-10)->format('Y-m-d 10:00:00')]);
    $insPromo->execute(['fx_promo_full',  'FXFULL10',  'percentage', '10.00', '0.00',   1,  1, 1, null, 'active', $day(-10)->format('Y-m-d 10:00:00')]);
    $insPromo->execute(['fx_promo_old',   'FXOLD20',   'fixed',      '20.00', '0.00',   0,  0, 1, $day(-1)->format('Y-m-d 23:59:59'), 'active', $day(-40)->format('Y-m-d 10:00:00')]);
    $insRd = $pdo->prepare("INSERT INTO promo_redemptions (id, promo_id, promo_code, user_id, booking_id, discount_amount, created_at) VALUES (?,?,?,?,?,?,?)");
    $insRd->execute(['fx_rdm1', 'fx_promo_live', 'FXLIVE50', 'usr_fx_p1', 'FX-DONE01', '50.00', $day(-7)->format('Y-m-d 14:30:00')]);
    $insRd->execute(['fx_rdm2', 'fx_promo_full', 'FXFULL10', 'usr_fx_p3', 'FX-CONF02', '35.00', $day(-2)->format('Y-m-d 14:30:00')]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Fixture seeding failed: ' . $e->getMessage() . "\n");
    exit(1);
}

// Synthetic private documents in the ISOLATED fixture store only.
$docDir = $root . '/database/.' . (str_ends_with($dbName, '_e2e') ? 'e2e' : 'test') . '-state/documents';
if (!is_dir($docDir)) {
    mkdir($docDir, 0750, true);
}
// 1x1 PNG (synthetic), clearly not a real document.
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
file_put_contents($docDir . '/fx_permit_rico.png', $png);
file_put_contents($docDir . '/fx_govid_rico.png', $png);
// fx_permit_missing.pdf is deliberately absent: exercises the missing-file state.

echo "Fixtures seeded into {$dbName} (" . count($users) . " users, " . count($bookings) . " bookings).\n";
