<?php
declare(strict_types=1);

namespace Picklers\Repositories;

/**
 * Court bookings: availability, creation, cancellation, owner decisions and end-of-session alerts.
 */
final class BookingRepository
{
    private readonly \Doctrine\DBAL\Connection $db;
    private readonly \Picklers\Domain\BookingRules $bookingRules;
    private readonly \Picklers\Domain\Notifier $notifier;
    private readonly \Picklers\Repositories\UserRepository $users;
    private readonly \Picklers\Repositories\NotificationRepository $notifications;
    private readonly \Picklers\Repositories\PromoRepository $promos;
    private readonly \Picklers\Repositories\WalletRepository $wallet;
    private readonly \Picklers\Repositories\MatchRepository $matches;
    private readonly \Picklers\Repositories\FacilityRepository $facilities;

    public function __construct(
        ?\Doctrine\DBAL\Connection $db = null,
        ?\Picklers\Domain\BookingRules $bookingRules = null,
        ?\Picklers\Domain\Notifier $notifier = null,
        ?\Picklers\Repositories\UserRepository $users = null,
        ?\Picklers\Repositories\NotificationRepository $notifications = null,
        ?\Picklers\Repositories\PromoRepository $promos = null,
        ?\Picklers\Repositories\WalletRepository $wallet = null,
        ?\Picklers\Repositories\MatchRepository $matches = null,
        ?\Picklers\Repositories\FacilityRepository $facilities = null,
    ) {
        $this->db = $db ?? \Picklers\Core\Database::get()->dbal();
        $this->bookingRules = $bookingRules ?? \Picklers\Core\Database::get()->bookingRules();
        $this->notifier = $notifier ?? \Picklers\Core\Database::get()->notifier();
        $this->users = $users ?? new \Picklers\Repositories\UserRepository($this->db);
        $this->notifications = $notifications ?? new \Picklers\Repositories\NotificationRepository($this->db);
        $this->promos = $promos ?? new \Picklers\Repositories\PromoRepository($this->db);
        $this->wallet = $wallet ?? new \Picklers\Repositories\WalletRepository($this->db);
        $this->matches = $matches ?? new \Picklers\Repositories\MatchRepository($this->db, $this->bookingRules);
        $this->facilities = $facilities ?? new \Picklers\Repositories\FacilityRepository($this->db, $this->notifier);
    }

    /**
     * Real-time "your time is up" alert for one player.
     *
     * There is no cron/daemon in this app (it's a plain request/response
     * PHP app), so this is
     * checked on every 'sync' poll (see ApiController) for whichever player
     * is actively polling. Since the player app polls every ~12s while open
     * (ux-core.js's PickSync), this surfaces within seconds of a booking's
     * actual end time without needing a scheduled task — as long as the
     * player has the app open somewhere. A real background push (rings even
     * with the app fully closed) needs Web Push infrastructure — HTTPS, a
     * service worker, VAPID keys, and something to trigger it independent of
     * any open tab — which is a separate, larger piece of work.
     *
     * Fires exactly once per booking (end_alert_sent), both as a normal
     * notification (so it's there later even if missed) and as a returned
     * payload the client uses to actually ring/vibrate/alert immediately —
     * see 'ended_sessions' in the sync response and PickSync.onSessionEnded()
     * in ux-core.js.
     *
     * Scoped to today's bookings only so shipping this feature doesn't
     * suddenly "end-alert" every confirmed booking in history the first time
     * an existing user polls.
     *
     * @return array<int,array{booking_id:string,court_name:string,facility_name:string}>
     */
    public function checkAndNotifySessionEnd(string $userId): array {
        if ($userId === '') {
            return [];
        }
        $todayStr = date('Y-m-d');
        $alerts = [];

        $stmt = $this->db->executeQuery(
            "SELECT * FROM bookings
             WHERE user_id = ? AND booking_date = ? AND status = 'confirmed'
               AND (end_alert_sent = 0 OR end_alert_sent IS NULL)",
            [$userId, $todayStr]
        );
        $candidates = $stmt->fetchAllAssociative();

        foreach ($candidates as $b) {
            if (!\Picklers\Domain\Schedule::isBookingPast($b)) {
                continue;
            }
            $bookingId = (string)($b['id'] ?? '');
            if ($bookingId === '') {
                continue;
            }

            $this->db->executeStatement("UPDATE bookings SET end_alert_sent = 1 WHERE id = ?", [$bookingId]);

            $courtName = (string)($b['court_name'] ?? 'your court');
            $facilityName = (string)($b['facility_name'] ?? 'the facility');
            $this->notifications->addNotification(
                $userId,
                '⏰ Your Time Is Up!',
                "Your session at {$courtName}, {$facilityName} has ended. Thanks for playing — see you again soon!",
                'session_ended'
            );

            $alerts[] = [
                'booking_id' => $bookingId,
                'court_name' => $courtName,
                'facility_name' => $facilityName,
            ];
        }

        return $alerts;
    }

    public function verifyBookingOwner(string $bookingId, string $ownerUserId): bool {
        $stmt = $this->db->executeQuery(
            "SELECT COUNT(*) as c FROM bookings b 
             JOIN facilities f ON b.facility_id = f.id 
             WHERE b.id = ? AND f.owner_id = ?",
            [$bookingId, $ownerUserId]
        );
        return (int)($stmt->fetchAssociative()['c'] ?? 0) > 0;
    }

    public function getBookingById(string $bookingId): ?array {
        $stmt = $this->db->executeQuery("SELECT * FROM bookings WHERE id = ?", [$bookingId]);
        return $stmt->fetchAssociative() ?: null;
    }

    public function updateBookingStatus(string $bookingId, string $status): bool {
        $this->notifier->bumpSync('bookings');
        $this->db->beginTransaction();
        try {
            $check = $this->db->executeQuery("SELECT status FROM bookings WHERE id = ? FOR UPDATE", [$bookingId]);
            $row = $check->fetchAssociative();
            if (!$row) {
                $this->db->rollBack();
                return false;
            }
            if ((string)$row['status'] === $status) {
                $this->db->commit();
                return true; // idempotent — already in target state
            }
            $this->db->executeStatement("UPDATE bookings SET status = ? WHERE id = ?", [$status, $bookingId]);
            $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            error_log('[DB Error] updateBookingStatus failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Move a booking to $to only if it is currently in one of $from, as one
     * atomic compare-and-set.
     *
     * updateBookingStatus() treats "already in the target state" as success,
     * so two approvals of the same request (two tabs, a double-click) both got
     * true back — both incremented the Open Play seat count and both sent the
     * player a confirmation. Callers that attach side effects to a transition
     * use this and act only when it returns true.
     *
     * @param string[] $from
     */
    public function transitionBookingStatus(string $bookingId, array $from, string $to): bool {
        if ($from === []) {
            return false;
        }
        $placeholders = implode(',', array_fill(0, count($from), '?'));
        $stmt = $this->db->executeQuery("UPDATE bookings SET status = ? WHERE id = ? AND status IN ($placeholders)", array_merge([$to, $bookingId], array_values($from)));
        $changed = $stmt->rowCount() > 0;
        if ($changed) {
            $this->facilities->invalidateReadCache();
            $this->notifier->bumpSync('bookings', 'courts');
        }
        return $changed;
    }

    /**
     * Why a NEW booking cannot be taken at this facility/court right now, or null.
     * Every booking channel (court booking, Open Play join, owner-hosted session)
     * calls this so admin suspension and court maintenance apply everywhere.
     */
    public function bookingBlockReason(int|string $facilityId, ?string $courtId = null, ?string $courtName = null): ?string {
        return $this->bookingRules->blockReason($facilityId, $courtId, $courtName);
    }

    /**
     * Accept a pending/upcoming booking request (owner approval or admin
     * confirmation); see BookingRules::confirmRequest(). MySQL only.
     *
     * @return array{ok:bool,code:int,message:string,status:string,already:bool,booking:?array}
     */
    public function confirmBookingRequest(string $bookingId, array $opts = []): array {
        $result = $this->bookingRules->confirmRequest($bookingId, $opts);
        $this->facilities->invalidateReadCache();
        return $result;
    }

    /**
     * Cancel a booking on behalf of the venue or the platform (owner decline,
     * admin cancellation); see BookingRules::cancelAsStaff(). MySQL only.
     *
     * @return array{ok:bool,code:int,message:string,previous_status:?string,refunded:bool,refund_amount:string,external_payment:bool,booking:?array}
     */
    public function cancelBookingAsStaff(string $bookingId, array $opts = []): array {
        $result = $this->bookingRules->cancelAsStaff($bookingId, $opts);
        $this->facilities->invalidateReadCache();
        return $result;
    }

    public function declineBookingAtomically(string $bookingId, string $userId, float $price, string $paymentMethod, string $facilityName): bool {
        $refunded = false;
        $this->notifier->bumpSync('bookings');
        // One shared rule with the admin cancellation path (refund once,
        // release the seat, notify) — see cancelBookingAsStaff().
        $res = $this->cancelBookingAsStaff($bookingId, [
            'notify_lead' => "Your reservation (#{$bookingId}) at {$facilityName} was declined.",
        ]);
        return $res['refunded'];
    }

    /**
     * Does $requestedTime clash with any of $existingTimes on the same court/date?
     * Falls back to exact string equality when either side cannot be parsed, so an
     * unrecognised format never silently permits a clash.
     *
     * @param string[] $existingTimes
     * @return string|null The conflicting time string, or null when the slot is free.
     */
    public function findSlotConflict(string $requestedTime, array $existingTimes): ?string {
        $requested = \Picklers\Domain\Schedule::parseTimeRange($requestedTime);

        foreach ($existingTimes as $taken) {
            $taken = (string)$taken;
            $takenRange = \Picklers\Domain\Schedule::parseTimeRange($taken);

            if ($requested === null || $takenRange === null) {
                if ($taken === $requestedTime) {
                    return $taken;
                }
                continue;
            }
            if (\Picklers\Domain\Schedule::rangesOverlap($requested, $takenRange)) {
                return $taken;
            }
        }
        return null;
    }

    public function getBookings($userId = null, $status = null) {
        $sql = "SELECT * FROM bookings WHERE 1=1";
        $params = [];
        if ($userId) {
            $sql .= " AND user_id = ?";
            $params[] = $userId;
        }
        if ($status) {
            $sql .= " AND status = ?";
            $params[] = $status;
        }
        $sql .= " ORDER BY created_at DESC";
        $stmt = $this->db->executeQuery($sql, $params);
        return $stmt->fetchAllAssociative();
    }

    /**
     * The owner Dashboard's "Requests" queue, shaped exactly as
     * views/partials/owner/_tab-dashboard.php's request-card-v2 needs it.
     * Shared by OwnerController::index() (first page render) and the
     * 'get_pending_requests' API action (live re-render after a PickSync
     * 'bookings' change) so the two can never drift apart.
     */
    public function getPendingBookingRequests(int|string $facilityId): array {
        $facilityIdStr = (string)$facilityId;
        // Scoped to this facility in the query itself.
        $stmt = $this->db->executeQuery(
            "SELECT b.*, u.name AS player_name, u.avatar_url AS player_avatar FROM bookings b
               LEFT JOIN users u ON u.id = b.user_id
              WHERE b.facility_id = ? AND b.status = 'pending'
              ORDER BY b.created_at DESC",
            [$facilityId]
        );
        $pending = $stmt->fetchAllAssociative();

        $requests = [];
        foreach ($pending as $b) {
            $playerName = $b['player_name'] ?? ($b['author_name'] ?? 'Player ' . substr((string)($b['user_id'] ?? ''), -4));
            $playerAvatar = $b['player_avatar'] ?? ($b['avatar_url'] ?? ($b['avatar'] ?? null));
            $rawPm = trim((string)($b['payment_method'] ?? 'GCASH'));
            if (strcasecmp($rawPm, 'Pay at Venue') === 0 || strcasecmp($rawPm, 'payatvenue') === 0) {
                $badge = 'PAY AT VENUE';
            } elseif (strcasecmp($rawPm, 'Pickle Credits') === 0 || strcasecmp($rawPm, 'picklecredits') === 0) {
                $badge = 'CREDITS';
            } else {
                $badge = strtoupper($rawPm);
            }
            $feeNum = (float)($b['price'] ?? 0);
            $dur = (string)($b['duration'] ?? '1');
            $durFormatted = is_numeric($dur) ? ($dur . ' ' . ((int)$dur === 1 ? 'hr' : 'hrs')) : $dur;
            $bId = (string)($b['id'] ?? '');
            $rawCourt = trim((string)($b['court_name'] ?? ''));
            $isOP = (isset($b['type']) && $b['type'] === 'open_play')
                 || (isset($b['booking_type']) && $b['booking_type'] === 'open_play')
                 || (strpos($bId, 'PKL-OP-') === 0)
                 || (strpos($bId, 'op_') === 0)
                 || (stripos($rawCourt, 'open play') !== false)
                 || (stripos($rawCourt, 'king of the court') !== false)
                 || (stripos($rawCourt, 'match') !== false);

            $requests[] = [
                'id' => (string)$b['id'],
                'name' => $playerName,
                'player_avatar' => $playerAvatar,
                'badge' => $badge,
                'court_name' => (string)($b['court_name'] ?? 'Court 1'),
                'schedule' => ($b['date'] ?? 'Upcoming') . ' at ' . ($b['time'] ?? 'TBD') . ' (' . $durFormatted . ')',
                'fee' => '₱' . number_format($feeNum),
                'fee_numeric' => $feeNum,
                'is_open_play' => $isOP,
                'booking_type' => $isOP ? 'open_play' : 'court_reservation',
                'type_label' => $isOP ? 'Open Play' : 'Court Reservation',
            ];
        }
        return $requests;
    }

    public function insertBooking($b) {
        // Every other mutating method here invalidates the courts read-cache;
        // this one didn't, so a request that reads court occupancy (it's
        // computed live from each court's bookings — see
        // getCourtsByFacilityUncached()) both before AND after inserting a
        // booking within the same request could see the pre-insert, stale
        // result the second time.
        $this->facilities->invalidateReadCache();
        // booking_date/start_min/end_min are optional here: joinMatch()'s Open
        // Play bookings don't need them (that occupancy path is driven by the
        // `matches` table, not a booking's own time window), but a caller
        // constructing a real timed-slot booking outside createBooking()'s
        // own validation (e.g. a test fixture) can supply them directly.
        $this->db->executeStatement("INSERT INTO bookings (id, user_id, facility_id, court_id, match_id, facility_name, court_name, date, time, booking_date, start_min, end_min, duration, price, payment_method, status, is_new, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)", [
            $b['id'], $b['user_id'], $b['facility_id'], $b['court_id'] ?? null, $b['match_id'] ?? null, $b['facility_name'], $b['court_name'],
            $b['date'], $b['time'], $b['booking_date'] ?? null, $b['start_min'] ?? null, $b['end_min'] ?? null,
            $b['duration'], $b['price'], $b['payment_method'],
            $b['status'], $b['is_new'] ?? 1, $b['created_at'] ?? date('Y-m-d H:i:s')
        ]);
        return $b;
    }

    public function isFacilityOpen(?string $hoursStr): bool {
        if (empty($hoursStr)) {
            return true;
        }
        $raw = strtolower(trim($hoursStr));
        if (strpos($raw, '24') !== false) {
            return true;
        }

        $raw = str_replace(['–', '—', '?'], '-', $raw);
        $parts = explode('-', $raw);
        if (count($parts) < 2) {
            return true;
        }

        $parseMinutes = function(string $s): ?int {
            $s = trim($s);
            $isPM = (strpos($s, 'pm') !== false);
            $isAM = (strpos($s, 'am') !== false);
            if (!preg_match('/(\d{1,2})(?::(\d{2}))?/', $s, $m)) {
                return null;
            }
            $h = (int)$m[1];
            $mins = isset($m[2]) ? (int)$m[2] : 0;
            if ($isPM && $h < 12) $h += 12;
            if ($isAM && $h === 12) $h = 0;
            return $h * 60 + $mins;
        };

        $openMin = $parseMinutes($parts[0]);
        $closeMin = $parseMinutes($parts[1]);

        if ($openMin === null || $closeMin === null) {
            return true;
        }

        $nowH = (int)date('G');
        $nowM = (int)date('i');
        $currentMin = $nowH * 60 + $nowM;

        if ($closeMin > $openMin) {
            return $currentMin >= $openMin && $currentMin < $closeMin;
        } elseif ($closeMin < $openMin) {
            return $currentMin >= $openMin || $currentMin < $closeMin;
        }

        return true;
    }

    private function validateBookingSlot(string $date, string $time, int $duration): array {
        if ($duration < 1 || $duration > 12) {
            return ['valid' => false, 'message' => 'Duration must be between 1 and 12 hours.'];
        }

        $resolvedDate = \Picklers\Domain\Schedule::resolveDisplayDate($date);
        if (!$resolvedDate) {
            return ['valid' => false, 'message' => 'Invalid booking date format.'];
        }

        $timeParts = preg_split('/\s*[-–—]\s*/u', $time);
        $timePart = $timeParts[0] ?? '';
        $bookingDt = strtotime($resolvedDate . ' ' . $timePart);

        // Allow 5 minutes grace period for network latency when booking immediate slot
        if ($bookingDt === false || $bookingDt < (time() - 300)) {
            return ['valid' => false, 'message' => 'Booking must be for a future time slot.'];
        }

        return ['valid' => true, 'resolved_date' => $resolvedDate];
    }

    /** Unix timestamp a booking's play window starts, or null when it can't be resolved. */
    private function bookingStartTimestamp(array $booking): ?int {
        $resolvedDate = (string)($booking['booking_date'] ?? '');
        if ($resolvedDate === '') {
            $resolvedDate = (string)(\Picklers\Domain\Schedule::resolveDisplayDate((string)($booking['date'] ?? '')) ?? '');
        }
        if ($resolvedDate === '') {
            return null;
        }

        $startMin = isset($booking['start_min']) && $booking['start_min'] !== null ? (int)$booking['start_min'] : null;
        if ($startMin === null) {
            $timeStr = (string)($booking['time'] ?? '');
            $range = \Picklers\Domain\Schedule::parseTimeRange($timeStr);
            if ($range === null) {
                $first = preg_split('/\s*[-–—]\s*/u', $timeStr)[0] ?? '';
                $ts = $first !== '' ? strtotime($resolvedDate . ' ' . $first) : false;
                return $ts === false ? null : $ts;
            }
            $startMin = $range[0];
        }

        $dayStart = strtotime($resolvedDate . ' 00:00:00');
        return $dayStart === false ? null : $dayStart + $startMin * 60;
    }

    private function hasBookingStarted(array $booking): bool {
        if (!empty($booking['ended_early'])) {
            return true;
        }
        $start = $this->bookingStartTimestamp($booking);
        return $start !== null && time() >= $start;
    }

    /**
     * Whether cancelling this booking right now earns the automatic full
     * refund cancelBooking() issues: paid with Pickle Credits, and at least
     * 24 hours before play. Lets the cancel dialog state the real outcome.
     */
    public function isFullRefundEligible(array $booking): bool {
        return ($booking['payment_method'] ?? '') === 'Pickle Credits'
            && (float)($booking['price'] ?? 0) > 0
            && (($booking['status'] ?? '') === 'pending' || $this->isWithin24HourWindow($booking));
    }

    private function isWithin24HourWindow(array $booking): bool {
        // Resolved from booking_date first. An Open Play join's display date is
        // "Everyday (2026-09-14)", which never parsed, so those players were
        // refused the full refund the cancellation policy promises.
        $start = $this->bookingStartTimestamp($booking);
        return $start !== null && ($start - time()) >= 86400;
    }

    /** Booking grid: 7:00 AM to 10:00 PM, one hour per slot. */
    private const AVAILABILITY_DAY_START_MIN = 420;

    private const AVAILABILITY_DAY_END_MIN   = 1320;

    private const AVAILABILITY_SLOT_MINUTES  = 60;

    /**
     * Which of a court's fixed hourly slots on a given day are actually free.
     *
     * The server has always correctly rejected an overlapping booking at the
     * point of charge (see createBooking()'s pessimistic lock); this is what
     * lets a client show that BEFORE the player picks a time and pays,
     * instead of the previous approach of hardcoding a single fake "occupied"
     * slot and discovering any real conflict only after submitting.
     *
     * @return array<int,array{start_min:int,end_min:int,label:string,available:bool,reason:?string}>
     */
    public function getSlotAvailability(int|string $facilityId, string $courtId, string $date): array {
        $taken = [];
        if ($courtId !== '') {
            // Include legacy rows that pre-date court_id (stored by court_name only).
            $courtName = '';
            $cnStmt = $this->db->executeQuery("SELECT name FROM courts WHERE id = ? LIMIT 1", [$courtId]);
            $cnRow = $cnStmt->fetchAssociative();
            if ($cnRow) {
                $courtName = (string)($cnRow['name'] ?? '');
            }
            $stmt = $this->db->executeQuery(
                "SELECT start_min, end_min FROM bookings
                 WHERE facility_id = ? AND booking_date = ?
                   AND (court_id = ? OR (? != '' AND court_name = ?))
                   AND status NOT IN ('cancelled', 'declined')
                   AND start_min IS NOT NULL AND end_min IS NOT NULL",
                [$facilityId, $date, $courtId, $courtName, $courtName]
            );
            $taken = $stmt->fetchAllAssociative();
        }

        $isToday = ($date === date('Y-m-d'));
        $nowMin  = ((int)date('G') * 60) + (int)date('i');

        $slots = [];
        for ($m = self::AVAILABILITY_DAY_START_MIN; $m < self::AVAILABILITY_DAY_END_MIN; $m += self::AVAILABILITY_SLOT_MINUTES) {
            $end = $m + self::AVAILABILITY_SLOT_MINUTES;

            $booked = false;
            foreach ($taken as $t) {
                if (\Picklers\Domain\Schedule::rangesOverlap([$m, $end], [(int)$t['start_min'], (int)$t['end_min']])) {
                    $booked = true;
                    break;
                }
            }
            $past = $isToday && $m <= $nowMin;

            $slots[] = [
                'start_min' => $m,
                'end_min'   => $end,
                'label'     => \Picklers\Domain\Schedule::minutesToLabel($m) . ' – ' . \Picklers\Domain\Schedule::minutesToLabel($end),
                'available' => !$booked && !$past,
                'reason'    => $booked ? 'booked' : ($past ? 'past' : null),
            ];
        }

        return $slots;
    }

    /**
     * @param string $courtId Optional. When the caller can supply it (the
     *        court came from a real courts-table lookup, not a client-typed
     *        name), it becomes the row's stable identity and the canonical
     *        court name from that row is stored instead of whatever string
     *        the caller passed — this is what stops a mangled/truncated
     *        display name from ever reaching the ledger. Leave empty for
     *        legacy callers; the slot lock still works correctly by name.
     * @param string|null $promoCode Recorded as a redemption INSIDE this same
     *        transaction when both this and $promoDiscount are given, so a
     *        discount can never be granted (the booking commits) without
     *        also being counted (the redemption row exists) or vice versa.
     */
    public function createBooking(
        $userId, $facilityId, $courtName, $date, $time, $duration, $price, $paymentMethod,
        string $courtId = '', ?string $promoCode = null, float $promoDiscount = 0.0
    ) {
        $val = $this->validateBookingSlot((string)$date, (string)$time, (int)$duration);
        if (!$val['valid']) {
            return ['success' => false, 'message' => $val['message']];
        }
        $this->notifier->bumpSync('bookings');
        // Canonical Y-m-d, resolved once and used for the lock itself, so
        // "Today", "Thu Sep 10 2026" and "Thu, Sep 10, 2026" collide as the
        // same day.
        $bookingDate = $val['resolved_date'];
        $range = \Picklers\Domain\Schedule::parseTimeRange((string)$time);
        // validateBookingSlot() only ever checked the START of the range, so
        // "8:00 AM - <anything>" was accepted: the booking stored no end
        // minute (never blocking the slot for anyone else), fell back to
        // string-equality collision checks, and carried arbitrary text into
        // every screen that renders the time. A real slot has a real end.
        if ($range === null) {
            return ['success' => false, 'message' => 'Please choose a valid time slot (for example "6:00 PM - 7:00 PM").'];
        }
        $startMin = $range[0];
        $endMin   = $range[1];

        $facility = $this->facilities->getFacility($facilityId);
        $facilityName = $facility ? $facility['name'] : 'Pickleball Facility';

        // If an id was supplied, it is authoritative: use the court's own
        // stored name rather than trust whatever string the caller sent.
        if ($courtId !== '') {
            foreach ($this->facilities->getCourtsByFacility($facilityId) as $c) {
                if ((string)($c['id'] ?? '') === $courtId) {
                    $courtName = (string)($c['name'] ?? $courtName);
                    break;
                }
            }
        }

        $booking = null;

        try {
            $this->db->beginTransaction();

            // Admin suspension / court maintenance applies to every channel.
            if (($blocked = $this->bookingBlockReason($facilityId, $courtId !== '' ? $courtId : null, (string)$courtName)) !== null) {
                $this->db->rollBack();
                return ['success' => false, 'message' => $blocked];
            }

            // Pessimistic lock scoped to the CANONICAL date (not whatever
            // display string the client happened to send), then evaluate
            // real time-range overlap. Matches by court_id OR court_name
            // so a legacy row that predates court_id (still NULL — see
            // the migration backfill) is never skipped and a real
            // conflict never goes undetected; idx_booking_date_lock keeps
            // this narrow either way.
            if ($courtId !== '') {
                $chk = $this->db->executeQuery(
                    "SELECT time, start_min, end_min FROM bookings
                     WHERE facility_id = ? AND booking_date = ?
                       AND (court_id = ? OR court_name = ?)
                       AND status NOT IN ('cancelled', 'declined')
                     FOR UPDATE",
                    [$facilityId, $bookingDate, $courtId, $courtName]
                );
            } else {
                $chk = $this->db->executeQuery(
                    "SELECT time, start_min, end_min FROM bookings
                     WHERE facility_id = ? AND booking_date = ? AND court_name = ?
                       AND status NOT IN ('cancelled', 'declined')
                     FOR UPDATE",
                    [$facilityId, $bookingDate, $courtName]
                );
            }
            $existingRows = $chk->fetchAllAssociative();

            $conflict = null;
            foreach ($existingRows as $row) {
                $rowStart = $row['start_min'] !== null ? (int)$row['start_min'] : null;
                $rowEnd   = $row['end_min']   !== null ? (int)$row['end_min']   : null;
                if ($startMin !== null && $endMin !== null && $rowStart !== null && $rowEnd !== null) {
                    if (\Picklers\Domain\Schedule::rangesOverlap([$startMin, $endMin], [$rowStart, $rowEnd])) {
                        $conflict = (string)$row['time'];
                        break;
                    }
                    continue;
                }
                // Either side didn't parse into minutes (an unusual time
                // format) — fall back to the original string-overlap
                // check for just that one row rather than assume clear.
                if ($this->findSlotConflict((string)$time, [(string)$row['time']]) !== null) {
                    $conflict = (string)$row['time'];
                    break;
                }
            }

            if ($conflict !== null) {
                $this->db->rollBack();
                return [
                    'success' => false,
                    'message' => "That court is already booked for {$conflict}. Please choose a different time."
                ];
            }

            // Atomic wallet check and deduction if Pickle Credits
            if ($paymentMethod === 'Pickle Credits') {
                $userStmt = $this->db->executeQuery("SELECT wallet_balance FROM users WHERE id = ? FOR UPDATE", [$userId]);
                $userRow = $userStmt->fetchAssociative();
                if (!$userRow || (float)($userRow['wallet_balance'] ?? 0) < (float)$price) {
                    $this->db->rollBack();
                    return ['success' => false, 'message' => 'Insufficient Pickle Credits. Please top up your wallet!'];
                }
                $this->db->executeStatement(
                    "UPDATE users SET wallet_balance = ROUND(wallet_balance - ?, 2) WHERE id = ?",
                    [$price, $userId]
                );
            }

            $bookingId = 'PKL-' . strtoupper(bin2hex(random_bytes(3)));
            $this->db->executeStatement(
                "INSERT INTO bookings
                   (id, user_id, facility_id, court_id, facility_name, court_name,
                    date, time, booking_date, start_min, end_min, duration, price,
                    payment_method, status, is_new, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                [
                    $bookingId, $userId, (int)$facilityId, ($courtId !== '' ? $courtId : null),
                    $facilityName, $courtName, $date, $time, $bookingDate, $startMin, $endMin,
                    $duration, (float)$price, $paymentMethod, 'pending', 1, date('Y-m-d H:i:s')
                ]
            );

            if ($paymentMethod === 'Pickle Credits') {
                $payStr = !empty($paymentMethod) ? " via $paymentMethod" : '';
                $this->wallet->addTransaction($userId, 'debit', $price, "Booking #$bookingId at $facilityName$payStr", ['entry_kind' => 'booking_payment', 'booking_id' => $bookingId]);
            }

            // Recorded in the SAME transaction as the booking itself: a
            // promo discount that was granted (the row below commits)
            // can never end up uncounted (no redemption row), and a
            // redemption can never be recorded against a booking that
            // ultimately didn't happen (the whole transaction rolls back
            // together on any failure above).
            if ($promoCode !== null && $promoCode !== '' && $promoDiscount > 0) {
                $this->promos->recordPromoRedemption($promoCode, $userId, $bookingId, $promoDiscount);
            }

            $this->db->commit();

            $booking = [
                'id' => $bookingId,
                'user_id' => $userId,
                'facility_id' => (int)$facilityId,
                'court_id' => $courtId !== '' ? $courtId : null,
                'facility_name' => $facilityName,
                'court_name' => $courtName,
                'date' => $date,
                'time' => $time,
                'booking_date' => $bookingDate,
                'start_min' => $startMin,
                'end_min' => $endMin,
                'duration' => $duration,
                'price' => (float)$price,
                'payment_method' => $paymentMethod,
                'status' => 'pending',
                'is_new' => 1,
                'created_at' => date('Y-m-d H:i:s')
            ];
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            if ($e instanceof \Picklers\Exceptions\PromoUnavailableException) {
                return ['success' => false, 'message' => $e->getMessage() . ' Nothing was charged — please book again without it.'];
            }
            error_log('[DB Error] createBooking failed: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Reservation failed due to a system error. Please try again.'];
        }

        // This is a REQUEST awaiting the facility owner's approval, not a
        // confirmed reservation yet — the actual "Booking Confirmed!"
        // notification is sent from 'approve_booking' in ApiController once
        // the owner accepts it. Telling the player it's "confirmed" here,
        // before anyone at the facility has reviewed it, is misleading.
        $this->notifications->addNotification(
            $userId,
            'Reservation Requested ⏳',
            "Your request for $courtName at $facilityName on $date ($time) has been sent to the facility for confirmation. We'll notify you once it's approved.",
            'booking'
        );

        // The player has always been told a booking landed. The facility
        // owner never was — their only way to learn about a new reservation
        // was noticing it in the dashboard's request queue on their own.
        $ownerId = $this->facilities->getFacilityOwnerId($facilityId);
        if ($ownerId !== null && $ownerId !== $userId) {
            $bookerName = $this->users->getUserById($userId)['name'] ?? 'A player';
            $this->notifications->addNotification(
                $ownerId,
                'New reservation 🎾',
                sprintf(
                    '%s booked %s at %s for %s (%s) — ₱%s.',
                    $bookerName, $courtName, $facilityName, $date, $time, number_format((float)$price, 2)
                ),
                'booking'
            );
        }

        return ['success' => true, 'booking' => $booking, 'message' => 'Court booked successfully!'];
    }

    public function cancelBooking($bookingId, $userId) {
        $refundAmount = 0;
        $this->notifier->bumpSync('bookings');
        try {
            $this->db->beginTransaction();

            $stmt = $this->db->executeQuery("SELECT * FROM bookings WHERE id = ? FOR UPDATE", [$bookingId]);
            $booking = $stmt->fetchAssociative();

            if (!$booking) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'Booking not found'];
            }
            if ((string)$booking['user_id'] !== (string)$userId) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'Access denied: You cannot cancel another player\'s booking'];
            }
            if ($booking['status'] === 'cancelled') {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'Booking is already cancelled'];
            }
            // Only block cancellation if the booking was CONFIRMED/ACCEPTED by the owner.
            // If it is still PENDING (unaccepted), the player can always cancel/withdraw their request!
            if (($booking['status'] ?? '') !== 'pending' && $this->hasBookingStarted($booking)) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'This session has already started or finished, so it can no longer be cancelled.'];
            }

            $prevStatus = $booking['status'] ?? 'pending';

            // 1. Status update FIRST (idempotency anchor)
            $this->db->executeStatement("UPDATE bookings SET status = 'cancelled' WHERE id = ?", [$bookingId]);

            if ($prevStatus === 'confirmed') {
                $matchId = $booking['match_id'] ?? null;
                if (!$matchId && (!empty($booking['court_name']) || str_starts_with($bookingId, 'PKL-OP-'))) {
                    $matches = $this->matches->getMatchesByFacility($booking['facility_id'] ?? 0);
                    foreach ($matches as $m) {
                        if (($m['type'] ?? '') === ($booking['court_name'] ?? '') && ($m['date'] ?? '') === ($booking['date'] ?? '') && ($m['time'] ?? '') === ($booking['time'] ?? '')) {
                            $matchId = $m['id'];
                            break;
                        }
                    }
                }
                if ($matchId) {
                    $this->matches->adjustMatchPlayerCount($matchId, -1);
                }
            }

            // 2. Refund SECOND (inside same transaction)
            $isSafeWindow = $this->isWithin24HourWindow($booking);
            $isPending = ($prevStatus === 'pending');
            if (($isPending || $isSafeWindow) && ($booking['payment_method'] ?? '') === 'Pickle Credits' && (float)($booking['price'] ?? 0) > 0) {
                // Idempotent: a booking already refunded by any path (owner
                // decline, admin cancellation) is never refunded again.
                $refundAmount = $this->wallet->creditWalletOnce(
                    (string)$userId,
                    number_format((float)$booking['price'], 2, '.', ''),
                    "Full Refund for Cancelled Booking #$bookingId",
                    'refund:booking:' . $bookingId,
                    ['entry_kind' => 'refund', 'booking_id' => (string)$bookingId]
                ) ? (float)$booking['price'] : 0;
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            error_log('[DB Error] cancelBooking failed: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Cancellation failed. Please try again.'];
        }

        $paidWith = (string)($booking['payment_method'] ?? '');
        if ($refundAmount > 0) {
            $msg = "Booking cancelled. ₱" . number_format($refundAmount, 2) . " has been fully refunded to your Pickle Credits!";
        } elseif ($paidWith === 'Pickle Credits' && (float)($booking['price'] ?? 0) > 0) {
            $msg = "Booking cancelled. Since cancellation occurred within 24 hours of play, no refund is provided per venue policy.";
        } elseif ($paidWith === 'Pay at Venue') {
            $msg = "Booking cancelled. Nothing was charged for this Pay at Venue booking.";
        } else {
            // Only Pickle Credits can be refunded in-app.
            $msg = "Booking cancelled. It was paid via " . ($paidWith !== '' ? $paidWith : 'another method')
                . ", so please contact the facility about your payment.";
        }
        $this->notifications->addNotification($userId, 'Booking Cancelled', $msg, 'booking');

        return ['success' => true, 'message' => $msg, 'refund_amount' => $refundAmount];
    }
}
