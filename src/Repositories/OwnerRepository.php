<?php
declare(strict_types=1);

namespace Picklers\Repositories;

/**
 * Facility owners: applications, staff, earnings and payout requests.
 */
final class OwnerRepository
{
    private readonly \Doctrine\DBAL\Connection $db;
    private readonly \Picklers\Domain\FacilityProvisioner $provisioner;
    private readonly \Picklers\Repositories\FacilityRepository $facilities;

    public function __construct(
        ?\Doctrine\DBAL\Connection $db = null,
        ?\Picklers\Domain\FacilityProvisioner $provisioner = null,
        ?\Picklers\Repositories\FacilityRepository $facilities = null,
    ) {
        $this->db = $db ?? \Picklers\Core\Database::get()->dbal();
        $this->provisioner = $provisioner ?? new \Picklers\Domain\FacilityProvisioner($this->db, \Picklers\Core\Database::get()->notifier());
        $this->facilities = $facilities ?? new \Picklers\Repositories\FacilityRepository($this->db);
    }

    public function getStaffByFacility(int|string $facilityId): array {
        $stmt = $this->db->executeQuery("
            SELECT s.*, COALESCE(u.avatar_url, '') as avatar_url 
            FROM staff s 
            LEFT JOIN users u ON ((s.user_id IS NOT NULL AND s.user_id != '' AND s.user_id = u.id) OR (LOWER(s.email) = LOWER(u.email) AND s.email != '')) 
            WHERE s.facility_id = ?
        ", [$facilityId]);
        return $stmt->fetchAllAssociative();
    }

    public function getStaffFacilityIdsForUser(string $userId, string $email): array {
        $userId = trim($userId);
        $email = strtolower(trim($email));
        if ($userId === '' && $email === '') {
            return [];
        }

        $stmt = $this->db->executeQuery("
            SELECT DISTINCT facility_id 
            FROM staff 
            WHERE (user_id IS NOT NULL AND user_id != '' AND user_id = ?) 
               OR (email IS NOT NULL AND email != '' AND LOWER(email) = ?)
        ", [$userId, $email]);
        return array_column($stmt->fetchAllAssociative(), 'facility_id');
    }

    public function verifyStaffOwner(string $staffId, string $ownerUserId): bool {
        $stmt = $this->db->executeQuery(
            "SELECT COUNT(*) as c FROM staff s
             JOIN facilities f ON s.facility_id = f.id
             WHERE s.id = ? AND f.owner_id = ?",
            [$staffId, $ownerUserId]
        );
        return (int)($stmt->fetchAssociative()['c'] ?? 0) > 0;
    }

    public function insertStaff(array $staffData): array {
        $id = $staffData['id'] ?? ('stf_' . bin2hex(random_bytes(4)));

        $staff = [
            'id' => $id,
            'facility_id' => $staffData['facility_id'],
            'name' => $staffData['name'] ?? $staffData['full_name'] ?? 'Staff Member',
            'email' => $staffData['email'] ?? 'staff@facility.com',
            'role' => $staffData['role'] ?? 'Front Desk',
            'status' => $staffData['status'] ?? 'Active',
            'date_joined' => $staffData['date_joined'] ?? date('M Y')
        ];

        $this->db->executeStatement(
            "INSERT INTO staff (id, facility_id, name, email, role, status, date_joined) VALUES (?,?,?,?,?,?,?)",
            [
                $staff['id'], $staff['facility_id'], $staff['name'], $staff['email'],
                $staff['role'], $staff['status'], $staff['date_joined']
            ]
        );
        return $staff;
    }

    public function deleteStaff(string $staffId): bool {
        $stmt = $this->db->executeQuery("DELETE FROM staff WHERE id = ?", [$staffId]);
        return $stmt->rowCount() > 0;
    }

    public function createOwnerApplication(array $appData): bool {
        $this->db->executeStatement(
            "INSERT INTO owner_applications (id, user_id, facility_name, address, latitude, longitude, courts_count, court_surface, operating_hours, owner_name, business_email, phone, entity_name, reg_number, permit_file, gov_id_file, status, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
            [
                $appData['id'], $appData['user_id'], $appData['facility_name'], $appData['address'],
                $appData['latitude'] ?? null, $appData['longitude'] ?? null, $appData['courts_count'] ?? 1,
                $appData['court_surface'] ?? 'Indoor Hard', $appData['operating_hours'] ?? '6:00 AM – 11:00 PM',
                $appData['owner_name'] ?? '', $appData['business_email'] ?? '', $appData['phone'] ?? '',
                $appData['entity_name'] ?? '', $appData['reg_number'] ?? '', $appData['permit_file'] ?? '',
                $appData['gov_id_file'] ?? '', $appData['status'] ?? 'pending_review', $appData['created_at'] ?? date('Y-m-d H:i:s')
            ]
        );
        return true;
    }

    public function getOwnerApplications(?string $status = null): array {
        $sql = "SELECT * FROM owner_applications WHERE 1=1";
        $params = [];
        if ($status) {
            $sql .= " AND status = ?";
            $params[] = $status;
        }
        $sql .= " ORDER BY created_at DESC";
        $stmt = $this->db->executeQuery($sql, $params);
        return $stmt->fetchAllAssociative();
    }

    /**
     * The most recent application a user has ever submitted, regardless of
     * status. Used as a fallback when an admin approves/rejects without an
     * explicit application_id (legacy callers, or a stale queue row).
     */
    public function getLatestApplicationForUser(string $userId): ?array {
        $stmt = $this->db->executeQuery(
            "SELECT * FROM owner_applications WHERE user_id = ? ORDER BY created_at DESC LIMIT 1",
            [$userId]
        );
        return $stmt->fetchAssociative() ?: null;
    }

    /**
     * Promote an approved owner application into a real, listed facility.
     *
     * Idempotent: re-approving the same application (a double click, or a
     * retried request) finds the facility it already created by owner_id +
     * name and returns that id rather than creating a duplicate.
     *
     * @return int The new (or already-existing) facility's id.
     * @throws \Throwable if the facility cannot be created — callers must
     *         not report success to the applicant when this throws.
     */
    public function createFacilityFromApplication(array $app): int {
        $ownerId = (string)($app['user_id'] ?? '');
        $name    = trim((string)($app['facility_name'] ?? ''));
        if ($ownerId === '' || $name === '') {
            throw new \InvalidArgumentException('Application is missing a user_id or facility_name.');
        }

        $facilityId = $this->provisioner->createFromApplication($app);
        $this->facilities->invalidateReadCache();
        return $facilityId;
    }

    public function getOwnerFinancials(int|string $facilityId): array {
        $facilityIdStr = (string)$facilityId;
        $FEE_PCT = 0.05;
        $monthStart = date('Y-m-01');
        $today = date('Y-m-d');

        // Monthly gross (confirmed bookings this calendar month)
        $s = $this->db->executeQuery(
            "SELECT COALESCE(SUM(price),0) FROM bookings
             WHERE facility_id=? AND status IN ('confirmed','completed')
             AND DATE(created_at)>=?",
            [$facilityId, $monthStart]
        );
        $monthlyGross = (float)$s->fetchOne();

        // Today gross
        $s = $this->db->executeQuery(
            "SELECT COALESCE(SUM(price),0) FROM bookings
             WHERE facility_id=? AND status IN ('confirmed','completed')
             AND DATE(created_at)=?",
            [$facilityId, $today]
        );
        $todayGross = (float)$s->fetchOne();

        // Today's session count (for the "X sessions" quick stat)
        $s = $this->db->executeQuery(
            "SELECT COUNT(*) FROM bookings
             WHERE facility_id=? AND status IN ('confirmed','completed')
             AND DATE(created_at)=?",
            [$facilityId, $today]
        );
        $todaySessionsCount = (int)$s->fetchOne();

        // All-time gross (for payout totals)
        $s = $this->db->executeQuery(
            "SELECT COALESCE(SUM(price),0) FROM bookings
             WHERE facility_id=? AND status IN ('confirmed','completed')",
            [$facilityId]
        );
        $allTimeGross = (float)$s->fetchOne();

        // Active bookings (pending + confirmed)
        $s = $this->db->executeQuery(
            "SELECT COUNT(*) FROM bookings WHERE facility_id=? AND status IN ('pending','confirmed')",
            [$facilityId]
        );
        $activeCount = (int)$s->fetchOne();

        // Distinct new players today
        $s = $this->db->executeQuery(
            "SELECT COUNT(DISTINCT user_id) FROM bookings WHERE facility_id=? AND DATE(created_at)=?",
            [$facilityId, $today]
        );
        $newPlayersToday = (int)$s->fetchOne();

        // Spark: daily revenue last 7 days (scaled to chart range), plus the
        // raw (unscaled) peso amounts for the hero card's Peak Day /
        // Yesterday's Income quick stats.
        $spark = [];
        $dailyRawArr = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-{$i} days"));
            $s = $this->db->executeQuery(
                "SELECT COALESCE(SUM(price),0) FROM bookings
                 WHERE facility_id=? AND status IN ('confirmed','completed') AND DATE(created_at)=?",
                [$facilityId, $day]
            );
            $dayGross = (float)$s->fetchOne();
            $dailyRawArr[] = $dayGross;
            $spark[] = max(0, (int)round($dayGross / 100));
        }

        // Ledger: last 10 confirmed bookings with player name
        $s = $this->db->executeQuery(
            "SELECT b.id, b.court_name, b.price, b.status, b.created_at, b.user_id,
                    COALESCE(u.name,'Player') as player_name
             FROM bookings b
             LEFT JOIN users u ON b.user_id = u.id
             WHERE b.facility_id=? AND b.status IN ('confirmed','completed')
             ORDER BY b.created_at DESC LIMIT 10",
            [$facilityId]
        );
        $rawLedger = $s->fetchAllAssociative();

        // Repeater rate: users with >1 booking / total users with bookings
        $s = $this->db->executeQuery(
            "SELECT user_id, COUNT(*) as cnt FROM bookings
             WHERE facility_id=? AND status IN ('confirmed','completed')
             GROUP BY user_id",
            [$facilityId]
        );
        $userCounts = $s->fetchAllKeyValue();
        $totalUsers = count($userCounts);
        $repeaters  = count(array_filter($userCounts, fn($c) => $c > 1));
        $repeaterRate = $totalUsers > 0 ? round($repeaters / $totalUsers * 100) : 0;

        // Past-30-day daily breakdown for the Daily Income modal, which used
        // to render a hardcoded table of invented dates and revenue for every
        // owner. Dated by created_at, like every other figure here.
        $windowStart = date('Y-m-d', strtotime('-29 days'));
        $s = $this->db->executeQuery(
            "SELECT DATE(created_at) AS d, user_id, court_name, price FROM bookings
              WHERE facility_id = ? AND status IN ('confirmed','completed') AND DATE(created_at) >= ?",
            [$facilityId, $windowStart]
        );
        $windowRows = $s->fetchAllAssociative();

        $byDay = [];
        foreach ($windowRows as $r) {
            $d = (string)$r['d'];
            $byDay[$d]['revenue'] = ($byDay[$d]['revenue'] ?? 0.0) + (float)$r['price'];
            $byDay[$d]['sessions'] = ($byDay[$d]['sessions'] ?? 0) + 1;
            $byDay[$d]['players'][(string)$r['user_id']] = true;
            $court = trim((string)$r['court_name']);
            if ($court !== '') {
                $byDay[$d]['courts'][$court] = ($byDay[$d]['courts'][$court] ?? 0) + 1;
            }
        }

        $dailyBreakdown = [];
        $breakdownPeak = 0.0;
        for ($i = 0; $i < 30; $i++) {
            $d = date('Y-m-d', strtotime("-{$i} days"));
            $dayTs = strtotime($d);
            $dayRevenue = round((float)($byDay[$d]['revenue'] ?? 0), 2);
            $breakdownPeak = max($breakdownPeak, $dayRevenue);
            $dayCourts = $byDay[$d]['courts'] ?? [];
            arsort($dayCourts);
            $dailyBreakdown[] = [
                'date'         => date('M j, Y', $dayTs),
                'dow'          => date('l', $dayTs),
                'month'        => strtolower(date('F', $dayTs)),
                'is_today'     => $i === 0,
                'is_yesterday' => $i === 1,
                'is_weekend'   => (int)date('N', $dayTs) >= 6,
                'sessions'     => (int)($byDay[$d]['sessions'] ?? 0),
                'players'      => count($byDay[$d]['players'] ?? []),
                'court'        => $dayCourts ? (string)array_key_first($dayCourts) : '—',
                'revenue'      => $dayRevenue,
            ];
        }
        foreach ($dailyBreakdown as &$breakdownDay) {
            $breakdownDay['is_peak'] = $breakdownPeak > 0 && $breakdownDay['revenue'] === $breakdownPeak;
        }
        unset($breakdownDay);

        // Build ledger rows
        $ledger = [];
        foreach ($rawLedger as $row) {
            $gross = (float)($row['price'] ?? 0);
            $fee   = round($gross * $FEE_PCT, 2);
            $net   = round($gross - $fee, 2);
            $ts    = strtotime((string)($row['created_at'] ?? ''));
            if ($ts !== false) {
                $diff = time() - $ts;
                if ($diff < 3600)      $timeLabel = round($diff / 60) . 'm ago';
                elseif ($diff < 86400) $timeLabel = round($diff / 3600) . 'h ago';
                else                   $timeLabel = date('M j, g:i A', $ts);
            } else {
                $timeLabel = (string)($row['created_at'] ?? '');
            }
            $ledger[] = [
                'tx_id'  => 'tx-' . strtoupper(substr((string)($row['id'] ?? 'xxx'), -6)),
                'time'   => $timeLabel,
                'court'  => (string)($row['court_name'] ?? 'Court'),
                'player' => (string)($row['player_name'] ?? 'Player'),
                'gross'  => $gross,
                'fee'    => $fee,
                'net'    => $net,
                // "Settled" was hardcoded, implying money already paid out.
                'status' => ucfirst((string)($row['status'] ?? 'confirmed')),
            ];
        }

        $payoutsRequested = $this->getPayoutRequestedTotal($facilityId);
        $allTimeFee = round($allTimeGross * $FEE_PCT, 2);
        $allTimeNet = round($allTimeGross - $allTimeFee, 2);

        // If spark is all zeros (no confirmed bookings yet) supply a flat baseline so
        // the sparkline component renders without an empty graph.
        if (array_sum($spark) === 0) {
            $spark = [0, 0, 0, 0, 0, 0, 0];
        }

        // dailyRawArr runs oldest (index 0, 6 days ago) to newest (index 6, today),
        // so index 5 is yesterday. Daily Avg is this month's gross spread over the
        // days elapsed so far this month — the same quantity the Dashboard's hero
        // card has always labelled "Daily Avg" next to "Monthly Revenue".
        $yesterdayGross = $dailyRawArr[5] ?? 0.0;
        $peakDayGross = !empty($dailyRawArr) ? max($dailyRawArr) : 0.0;
        $daysElapsedThisMonth = max(1, (int)date('j'));
        $dailyAvgGross = $monthlyGross / $daysElapsedThisMonth;

        return [
            'monthly_gross'    => $monthlyGross,
            'today_gross'      => $todayGross,
            'today_sessions'   => $todaySessionsCount,
            'yesterday_gross'  => $yesterdayGross,
            'peak_day_gross'   => $peakDayGross,
            'daily_avg_gross'  => $dailyAvgGross,
            'active_bookings'  => $activeCount,
            'new_players_today'=> $newPlayersToday,
            'repeater_rate'    => $repeaterRate,
            'repeaters_count'  => $repeaters,
            'repeat_eligible_count' => $totalUsers,
            'spark'            => $spark,
            'gross'            => $allTimeGross,
            'fee_pct'          => $FEE_PCT,
            'fee_amount'       => $allTimeFee,
            'net'              => $allTimeNet,
            // Earlier payout requests are deducted from what is available.
            'payouts_requested'=> $payoutsRequested,
            'available'        => max(0.0, round($allTimeNet * 0.80 - $payoutsRequested, 2)),
            'ledger'           => $ledger,
            'daily_breakdown'  => $dailyBreakdown,
            'daily_breakdown_peak' => $breakdownPeak,
        ];
    }

    /** Total already requested for payout (anything not rejected/cancelled) for one facility. */
    public function getPayoutRequestedTotal(int|string $facilityId): float {
        try {
            $s = $this->db->executeQuery(
                "SELECT COALESCE(SUM(amount), 0) FROM payout_requests
                  WHERE facility_id = ? AND status NOT IN ('rejected', 'cancelled', 'failed')",
                [(string)$facilityId]
            );
            return (float)$s->fetchOne();
        } catch (\Throwable $e) {
            error_log('[DB Error] getPayoutRequestedTotal failed: ' . $e->getMessage());
            return 0.0;
        }
    }

    /**
     * Record a payout request only while it still fits the facility's
     * available balance.
     *
     * The balance check lived in the controller, separate from the insert, so
     * two submissions at once both passed it. Check and insert now happen
     * under one per-facility lock.
     *
     * @return array{success:bool, message?:string, payout?:array}
     */
    public function createPayoutRequest(string $ownerId, string $facilityId, float $amount, string $method, ?string $notes = null): array {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            return ['success' => false, 'message' => 'Payout amount must be greater than zero.'];
        }

        $lockName = 'picklers_payout_' . $facilityId;
        $locked = false;
        $lock = $this->db->executeQuery('SELECT GET_LOCK(?, 5)', [$lockName]);
        $locked = (int)$lock->fetchOne() === 1;
        if (!$locked) {
            return ['success' => false, 'message' => 'Another payout request for this facility is being processed. Please try again.'];
        }

        try {
            $available = (float)($this->getOwnerFinancials($facilityId)['available'] ?? 0.0);
            if ($amount > $available) {
                return [
                    'success' => false,
                    'message' => 'Requested amount exceeds your available balance of ₱' . number_format($available, 2) . '.',
                ];
            }

            $id  = 'pay_' . bin2hex(random_bytes(6));
            $now = date('Y-m-d H:i:s');
            $record = [
                'id'          => $id,
                'owner_id'    => $ownerId,
                'facility_id' => $facilityId,
                'amount'      => $amount,
                'method'      => $method,
                'status'      => 'pending',
                // Destination account name/number, so whoever processes this
                // payout manually knows where the money actually needs to go.
                'notes'       => $notes,
                'created_at'  => $now,
                'updated_at'  => $now,
            ];

            $this->db->executeStatement(
                "INSERT INTO payout_requests (id, owner_id, facility_id, amount, method, status, notes, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, 'pending', ?, ?, ?)",
                [$id, $ownerId, $facilityId, $amount, $method, $notes, $now, $now]
            );

            return ['success' => true, 'payout' => $record];
        } finally {
            if ($locked) {
                $this->db->executeStatement('SELECT RELEASE_LOCK(?)', [$lockName]);
            }
        }
    }
}
