<?php
declare(strict_types=1);

namespace Picklers\Repositories;

/**
 * Open Play: listing sessions, joining, cancelling and the owner roster.
 */
final class OpenPlayRepository
{
    private readonly \Doctrine\DBAL\Connection $db;
    private readonly \Picklers\Domain\Notifier $notifier;
    private readonly \Picklers\Repositories\UserRepository $users;
    private readonly \Picklers\Repositories\NotificationRepository $notifications;
    private readonly \Picklers\Repositories\PromoRepository $promos;
    private readonly \Picklers\Repositories\WalletRepository $wallet;
    private readonly \Picklers\Repositories\MatchRepository $matches;
    private readonly \Picklers\Repositories\FacilityRepository $facilities;
    private readonly \Picklers\Repositories\BookingRepository $bookings;

    public function __construct(
        ?\Doctrine\DBAL\Connection $db = null,
        ?\Picklers\Domain\Notifier $notifier = null,
        ?\Picklers\Repositories\UserRepository $users = null,
        ?\Picklers\Repositories\NotificationRepository $notifications = null,
        ?\Picklers\Repositories\PromoRepository $promos = null,
        ?\Picklers\Repositories\WalletRepository $wallet = null,
        ?\Picklers\Repositories\MatchRepository $matches = null,
        ?\Picklers\Repositories\FacilityRepository $facilities = null,
        ?\Picklers\Repositories\BookingRepository $bookings = null,
    ) {
        $this->db = $db ?? \Picklers\Core\Database::get()->dbal();
        $this->notifier = $notifier ?? \Picklers\Core\Database::get()->notifier();
        $this->users = $users ?? new \Picklers\Repositories\UserRepository($this->db);
        $this->notifications = $notifications ?? new \Picklers\Repositories\NotificationRepository($this->db);
        $this->promos = $promos ?? new \Picklers\Repositories\PromoRepository($this->db);
        $this->wallet = $wallet ?? new \Picklers\Repositories\WalletRepository($this->db);
        $this->matches = $matches ?? new \Picklers\Repositories\MatchRepository($this->db);
        $this->facilities = $facilities ?? new \Picklers\Repositories\FacilityRepository($this->db, $this->notifier);
        $this->bookings = $bookings ?? new \Picklers\Repositories\BookingRepository($this->db);
    }

    /**
     * Cancel Open Play session(s) at a facility. Boolean wrapper kept for
     * existing callers; see cancelOpenPlaySessions().
     */
    public function deleteMatch(string $matchId, int|string $facilityId, string $courtName = ''): bool {
        return $this->cancelOpenPlaySessions($matchId, $facilityId, $courtName)['deleted'];
    }

    /**
     * Remove Open Play session(s) and settle the players who joined them.
     *
     * Targets a session by id or title ($matchRef) and/or every session on one
     * court ($courtName), compared EXACTLY (case-insensitive). The previous
     * matcher used substring containment in both directions, so cancelling
     * "Court 1" also removed "Court 10"'s session (and an empty type matched
     * everything). It then cancelled EVERY non-cancelled booking whose
     * court_name matched — ordinary court reservations on any date included —
     * with no refund and no notice to anyone.
     *
     * Now only the removed sessions' own join bookings are touched, and only
     * the ones not yet played: each is cancelled, a Pickle Credits payment is
     * refunded to the wallet, and the player is notified.
     *
     * @return array{deleted:bool, sessions:int, cancelled_bookings:int, refunded_amount:float}
     */
    public function cancelOpenPlaySessions(string $matchRef, int|string $facilityId, string $courtName = ''): array {
        $matchRef  = trim($matchRef);
        $courtName = trim($courtName);
        $result = ['deleted' => false, 'sessions' => 0, 'cancelled_bookings' => 0, 'refunded_amount' => 0.0];
        if ($matchRef === '' && $courtName === '') {
            return $result;
        }

        $targets = [];
        foreach ($this->matches->getMatchesByFacility($facilityId) as $m) {
            $mId    = (string)($m['id'] ?? '');
            $mTitle = trim((string)($m['title'] ?? ''));
            $mCourt = trim((string)($m['court_name'] ?? $m['type'] ?? ''));
            $byRef   = $matchRef !== '' && ($mId === $matchRef || ($mTitle !== '' && strcasecmp($mTitle, $matchRef) === 0));
            $byCourt = $courtName !== '' && $mCourt !== '' && strcasecmp($mCourt, $courtName) === 0;
            if ($mId !== '' && ($byRef || $byCourt)) {
                $targets[$mId] = $m;
            }
        }

        $courtsToReset = [];
        if ($courtName !== '') {
            $courtsToReset[strtolower($courtName)] = $courtName;
        }
        foreach ($targets as $m) {
            $cn = trim((string)($m['type'] ?? ''));
            if ($cn !== '') {
                $courtsToReset[strtolower($cn)] = $cn;
            }
        }

        if ($targets === []) {
            foreach ($courtsToReset as $cn) {
                $this->facilities->updateCourtStatusByNameOrId((int)$facilityId, $cn, 'available');
            }
            return $result;
        }

        $matchIds = array_keys($targets);
        $sessionCourts = array_values(array_filter(array_map(fn($m) => trim((string)($m['type'] ?? '')), $targets)));
        $settled = []; // bookings actually cancelled, for notifications after commit

        $settle = function (array $booking) use (&$result, &$settled): ?array {
            if (\Picklers\Domain\Schedule::isBookingPast($booking)) {
                return null; // already played — history stays as it was
            }
            $price = (float)($booking['price'] ?? 0);
            $refund = (($booking['payment_method'] ?? '') === 'Pickle Credits' && $price > 0) ? $price : 0.0;
            $result['cancelled_bookings']++;
            $result['refunded_amount'] = round($result['refunded_amount'] + $refund, 2);
            $settled[] = ['booking' => $booking, 'refund' => $refund];
            return ['refund' => $refund];
        };

        $this->db->beginTransaction();
        try {
            $idPh = implode(',', array_fill(0, count($matchIds), '?'));
            $sql = "SELECT * FROM bookings WHERE facility_id = ? AND status IN ('pending', 'confirmed')
                      AND (match_id IN ($idPh)";
            $params = array_merge([$facilityId], $matchIds);
            if ($sessionCourts !== []) {
                // Legacy join rows written before bookings carried match_id.
                $cPh = implode(',', array_fill(0, count($sessionCourts), '?'));
                $sql .= " OR (match_id IS NULL AND id LIKE 'PKL-OP-%' AND court_name IN ($cPh))";
                $params = array_merge($params, $sessionCourts);
            }
            $sql .= ") FOR UPDATE";
            $stmt = $this->db->executeQuery($sql, $params);

            $cancelSql = "UPDATE bookings SET status = 'cancelled' WHERE id = ?";
            foreach ($stmt->fetchAllAssociative() as $booking) {
                $outcome = $settle($booking);
                if ($outcome === null) {
                    continue;
                }
                $this->db->executeStatement($cancelSql, [$booking['id']]);
                if ($outcome['refund'] > 0) {
                    $this->wallet->creditWalletOnce(
                        (string)$booking['user_id'],
                        number_format((float)$outcome['refund'], 2, '.', ''),
                        "Refund: Open Play #{$booking['id']} cancelled by facility",
                        'refund:booking:' . $booking['id'],
                        ['entry_kind' => 'refund', 'booking_id' => (string)$booking['id']]
                    );
                }
            }

            $del = $this->db->executeQuery("DELETE FROM matches WHERE facility_id = ? AND id IN ($idPh)", array_merge([$facilityId], $matchIds));
            $result['sessions'] = $del->rowCount();

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            error_log('[DB Error] cancelOpenPlaySessions failed: ' . $e->getMessage());
            throw $e;
        }

        $result['deleted'] = $result['sessions'] > 0;

        foreach ($settled as $s) {
            $b = $s['booking'];
            $body = sprintf(
                'The Open Play session %s at %s (%s) was cancelled by the facility.',
                $b['court_name'] ?? '',
                $b['facility_name'] ?? 'the facility',
                trim(($b['booking_date'] ?? $b['date'] ?? '') . ' ' . ($b['time'] ?? ''))
            );
            $body .= $s['refund'] > 0
                ? ' ₱' . number_format($s['refund'], 2) . ' has been refunded to your Pickle Credits.'
                : ' Please contact the facility about your ' . ($b['payment_method'] ?? '') . ' payment.';
            $this->notifications->addNotification((string)$b['user_id'], 'Open Play Cancelled', $body, 'booking');
        }

        foreach ($courtsToReset as $cn) {
            $this->facilities->updateCourtStatusByNameOrId((int)$facilityId, $cn, 'available');
        }

        $this->facilities->invalidateReadCache();
        $this->notifier->bumpSync('matches', 'bookings', 'courts');

        return $result;
    }

    public function getOpenPlayRoster(int|string $facilityId, string $courtName, string $courtId = ''): array {
        $courtName = trim($courtName);
        $courtId = trim($courtId);

        // The roster is the join requests of the session currently running on
        // this exact court, aligned with getCourtsByFacilityUncached matching rules.
        $session = null;
        $allCourts = $this->facilities->getCourtsByFacility($facilityId);
        foreach ($this->matches->getMatchesByFacility($facilityId) as $m) {
            if (\Picklers\Domain\Schedule::isMatchExpired($m)) continue;
            $mType = trim((string)($m['type'] ?? ''));
            $mCourtId = trim((string)($m['court_id'] ?? ''));
            $mCourtName = trim((string)($m['court_name'] ?? ''));

            $isMatchForCourt = false;
            if ($courtId !== '' && $mCourtId !== '' && $mCourtId === $courtId) {
                $isMatchForCourt = true;
            } elseif ($courtName !== '' && $mCourtName !== '' && strcasecmp($mCourtName, $courtName) === 0) {
                $isMatchForCourt = true;
            } elseif ($courtName !== '' && $mType !== '' && strcasecmp($mType, $courtName) === 0) {
                $isMatchForCourt = true;
            } elseif (count($allCourts) === 1) {
                $isMatchForCourt = true;
            }

            if ($isMatchForCourt) {
                $session = $m;
                break;
            }
        }
        if ($session === null) {
            return [];
        }

        $sessionId = (string)($session['id'] ?? '');
        $sessionCourt = trim((string)($session['court_name'] ?? $session['type'] ?? $courtName));
        $sessionTitle = trim((string)($session['title'] ?? $session['type'] ?? ''));
        $sessionDate = strtolower(trim((string)($session['date'] ?? '')));
        $isRecurring = str_contains($sessionDate, 'everyday') || str_contains($sessionDate, 'daily');
        $targetDate = \Picklers\Domain\Schedule::getMatchTargetDate($session);

        $stmt = $this->db->executeQuery(
            "SELECT b.id, b.user_id, b.status, b.booking_date, b.payment_method, b.price, b.created_at, b.court_name, b.date,
                    u.name, u.email, u.level, u.avatar_url
               FROM bookings b
          LEFT JOIN users u ON b.user_id = u.id
              WHERE b.facility_id = ? AND b.status IN ('pending', 'confirmed', 'completed')
                AND (
                     (b.match_id IS NOT NULL AND b.match_id != '' AND b.match_id = ?)
                  OR (b.id LIKE 'PKL-OP-%' AND (b.court_name = ? OR b.court_name = ? OR b.court_name = ?))
                )
           ORDER BY b.created_at DESC",
            [$facilityId, $sessionId, $sessionCourt, $courtName, $sessionTitle]
        );
        $rows = $stmt->fetchAllAssociative();

        $roster = [];
        $seenPlayers = [];
        foreach ($rows as $r) {
            // A recurring session is never recreated day to day: only the
            // current occurrence's players belong on today's roster.
            if ($isRecurring) {
                $rawDate = (string)($r['booking_date'] ?? $r['date'] ?? '');
                $day = '';
                if ($rawDate !== '') {
                    $day = \Picklers\Domain\Schedule::resolveDisplayDate($rawDate) ?? (strtotime($rawDate) !== false ? date('Y-m-d', strtotime($rawDate)) : '');
                } elseif (!empty($r['created_at'])) {
                    $day = date('Y-m-d', strtotime((string)$r['created_at']));
                }
                if ($day !== '' && $day !== $targetDate) continue;
            }

            $playerKey = (string)($r['user_id'] ?? '') !== '' ? (string)$r['user_id'] : strtolower((string)($r['email'] ?? $r['name'] ?? ''));
            if ($playerKey === '' || isset($seenPlayers[$playerKey])) continue;
            $seenPlayers[$playerKey] = true;

            $method = (string)($r['payment_method'] ?? '');
            $roster[] = [
                'id' => $r['id'],
                'name' => !empty($r['name']) ? $r['name'] : 'Registered Player',
                'email' => $r['email'] ?? '',
                'level' => !empty($r['level']) ? $r['level'] : 'Player',
                'avatar_url' => !empty($r['avatar_url']) ? $r['avatar_url'] : ($r['avatar'] ?? null),
                'status' => (string)($r['status'] ?? 'pending'),
                'payment_status' => strcasecmp($method, 'Pay at Venue') === 0 ? 'Pay at Venue' : 'Paid via ' . ($method !== '' ? $method : 'GCash'),
                'fee' => (float)($r['price'] ?? 0),
                'time_ago' => !empty($r['created_at']) ? date('M j, g:i A', strtotime((string)$r['created_at'])) : 'Recent',
            ];
        }

        // Fallback for session host / initial registered spot when current_players > 0
        if (empty($roster) && (!empty($session['host']) || (int)($session['current_players'] ?? 0) > 0)) {
            $hostName = !empty($session['host']) ? trim((string)$session['host']) : 'Session Host';
            $hostKey = strtolower($hostName);
            if (!isset($seenPlayers[$hostKey])) {
                $hostAvatar = !empty($session['host_avatar']) ? $session['host_avatar'] : ($session['user_avatar'] ?? ($session['avatar_url'] ?? ($session['avatar'] ?? null)));
                $hostLevel = !empty($session['level']) ? $session['level'] : 'Host / Organizer';
                $roster[] = [
                    'id' => 'host_' . $sessionId,
                    'name' => $hostName,
                    'email' => '',
                    'level' => $hostLevel,
                    'avatar_url' => $hostAvatar,
                    'status' => 'confirmed',
                    'payment_status' => 'Session Host',
                    'fee' => (float)($session['price'] ?? 0),
                    'time_ago' => 'Session Host',
                ];
            }
        }

        return $roster;
    }

    public function getMatches($level = 'All', $facilitySearch = '') {
        $sql = "SELECT m.*, COALESCE(NULLIF(f.name, ''), m.facility_name) as facility_name
                FROM matches m
                LEFT JOIN facilities f ON m.facility_id = f.id
                WHERE 1=1";
        $params = [];
        if ($level !== 'All') {
            $sql .= " AND m.level = ?";
            $params[] = $level;
        }
        if (!empty($facilitySearch)) {
            $sql .= " AND (m.facility_name LIKE ? OR f.name LIKE ? OR m.location LIKE ?)";
            $params[] = "%$facilitySearch%";
            $params[] = "%$facilitySearch%";
            $params[] = "%$facilitySearch%";
        }
        $sql .= " ORDER BY m.id DESC";
        $stmt = $this->db->executeQuery($sql, $params);
        $rows = $stmt->fetchAllAssociative();

        // Filter expired matches and deduplicate by match ID & facility/court combo
        $active = array_values(array_filter($rows, fn($m) => !\Picklers\Domain\Schedule::isMatchExpired($m)));
        $seen = [];
        $unique = [];
        foreach ($active as $m) {
            $mId = (string)($m['id'] ?? '');
            $facId = (string)($m['facility_id'] ?? '');
            $cName = \Picklers\Domain\Schedule::normalizeCourtName($m['court_name'] ?? $m['type'] ?? '');
            $key = $mId !== '' ? $mId : ($facId . '_' . $cName);

            if (!isset($seen[$key])) {
                $seen[$key] = true;

                $rawDate = trim((string)($m['date'] ?? ''));
                $targetDate = \Picklers\Domain\Schedule::getMatchTargetDate($m);
                $displayDate = \Picklers\Domain\Schedule::getMatchDisplayDate($m);
                $m['date'] = $displayDate;
                $m['target_date'] = $targetDate;

                $isEveryday = strcasecmp($rawDate, 'everyday') === 0 || strcasecmp($rawDate, 'daily') === 0 || str_contains(strtolower($rawDate), 'everyday') || str_contains(strtolower($rawDate), 'daily');

                $activeCount = $this->matches->countConfirmedMatchBookings($mId, $targetDate);
                if ($isEveryday) {
                    $m['current_players'] = $activeCount;
                } else {
                    $m['current_players'] = max((int)($m['current_players'] ?? 0), $activeCount);
                }

                $unique[] = $m;
            }
        }

        // Ensure newly created / hosted matches (e.g. op_...) appear at the top of the feed
        usort($unique, function($a, $b) {
            $aId = (string)($a['id'] ?? '');
            $bId = (string)($b['id'] ?? '');
            $aIsOp = str_starts_with($aId, 'op_') ? 1 : 0;
            $bIsOp = str_starts_with($bId, 'op_') ? 1 : 0;
            if ($aIsOp !== $bIsOp) {
                return $bIsOp <=> $aIsOp;
            }
            return strcmp($bId, $aId);
        });

        return $unique;
    }

    public function joinMatch($matchId, $userId, $paymentMethod = 'GCash', $promoCode = null) {
        $match = null;
        $pricing = new \Picklers\Services\PricingService($this);

        try {
            $this->db->beginTransaction();

            $stmt = $this->db->executeQuery("SELECT * FROM matches WHERE id = ? FOR UPDATE", [$matchId]);
            $match = $stmt->fetchAssociative();
            if (!$match) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'Match not found'];
            }
            // Discover hides ended sessions, but the id is still valid —
            // a stale tab or a direct request could pay into one.
            if (\Picklers\Domain\Schedule::isMatchExpired($match)) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'This Open Play session has already ended.'];
            }
            // matches.type carries the court name the session is hosted on.
            if (($blocked = $this->bookings->bookingBlockReason($match['facility_id'] ?? 0, null, (string)($match['type'] ?? ''))) !== null) {
                $this->db->rollBack();
                return ['success' => false, 'message' => $blocked];
            }
            // Gate on pending + confirmed requests, not just approved seats.
            // The FOR UPDATE lock above serializes concurrent joinMatch() calls
            // for this match, so the count is atomic relative to them.
            $targetDate = \Picklers\Domain\Schedule::getMatchTargetDate($match);
            if ($this->matches->countActiveMatchBookings((string)$matchId, $targetDate) >= (int)$match['max_players']) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'This open play match is already full!'];
            }

            $alreadyChk = $this->db->executeQuery("SELECT id FROM bookings WHERE user_id = ? AND facility_id = ? AND court_name = ? AND (date = ? OR date LIKE ? OR booking_date = ?) AND time = ? AND status != 'cancelled'", [$userId, $match['facility_id'], $match['type'], $targetDate, "%$targetDate%", $targetDate, $match['time']]);
            if ($alreadyChk->fetchAssociative()) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'You have already joined this Open Play session!'];
            }

            // Entry fee is derived from the match record, never from the client.
            $quote = $pricing->quoteMatchJoin($match, $promoCode, $userId);
            if (empty($quote['success'])) {
                $this->db->rollBack();
                return ['success' => false, 'message' => (string)($quote['message'] ?? 'Unable to price this session.')];
            }
            $finalPrice = (float)$quote['total'];

            // Charge the wallet atomically when paying with Pickle Credits.
            if ($paymentMethod === 'Pickle Credits' && $finalPrice > 0) {
                $walletStmt = $this->db->executeQuery("SELECT wallet_balance FROM users WHERE id = ? FOR UPDATE", [$userId]);
                $walletRow = $walletStmt->fetchAssociative();
                if (!$walletRow || (float)($walletRow['wallet_balance'] ?? 0) < $finalPrice) {
                    $this->db->rollBack();
                    return ['success' => false, 'message' => 'Insufficient Pickle Credits. Please top up your wallet!'];
                }
                $this->db->executeStatement(
                    "UPDATE users SET wallet_balance = ROUND(wallet_balance - ?, 2) WHERE id = ?",
                    [$finalPrice, $userId]
                );
            }

            $bookingId = 'PKL-OP-' . strtoupper(bin2hex(random_bytes(3)));
            $isEveryday = strcasecmp($match['date'] ?? '', 'everyday') === 0 || strcasecmp($match['date'] ?? '', 'daily') === 0;
            $bookingDateDisplay = $isEveryday ? date('D, M j, Y', strtotime($targetDate)) : $match['date'];
            $booking = [
                'id' => $bookingId,
                'user_id' => $userId,
                'facility_id' => $match['facility_id'],
                'facility_name' => $match['facility_name'],
                'court_name' => $match['type'] ?? 'Open Play Session',
                'date' => $bookingDateDisplay,
                'booking_date' => $targetDate,
                'time' => $match['time'],
                'duration' => '2 Hours',
                'price' => $finalPrice,
                'payment_method' => $paymentMethod ?? 'GCash',
                'status' => 'pending',
                'is_new' => 1,
                'created_at' => date('Y-m-d H:i:s'),
                'match_id' => $matchId
            ];
            $this->bookings->insertBooking($booking);

            // NOTE: For MySQL, current_players is incremented by the owner
            // when they confirm the booking (see ApiController::confirm_booking).
            // This preserves the pending-approval flow where seats are only
            // officially counted after owner acceptance.

            // Record promo usage so per-user and total limits are enforced.
            // Signature: (code, user, booking, discount).
            if ($promoCode !== null && $promoCode !== '' && (float)($quote['discount'] ?? 0) > 0) {
                $this->promos->recordPromoRedemption(
                    strtoupper(trim($promoCode)),
                    (string)$userId,
                    $bookingId,
                    (float)$quote['discount']
                );
            }

            if ($paymentMethod === 'Pickle Credits' && $finalPrice > 0) {
                $this->wallet->addTransaction(
                    $userId,
                    'debit',
                    $finalPrice,
                        "Open Play #$bookingId at " . ($match['facility_name'] ?? 'Pickleball Facility'),
                    ['entry_kind' => 'booking_payment', 'booking_id' => $bookingId]
                );
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            if ($e instanceof \Picklers\Exceptions\PromoUnavailableException) {
                return ['success' => false, 'message' => $e->getMessage() . ' Nothing was charged — please join again without it.'];
            }
            error_log('[DB Error] joinMatch transaction failed: ' . $e->getMessage());
            return ['success' => false, 'message' => 'System transaction error. Please try again.'];
        }

        // Notify user — this is a REQUEST, not a confirmed seat, until the
        // facility owner approves it (see 'approve_booking' in ApiController,
        // which sends the actual "Booking Confirmed!" notification).
        $this->notifications->addNotification(
            $userId,
            'Open Play Request Sent 🔥',
            'Your request to join ' . ($match['type'] ?? 'Open Play') . ' at ' . ($match['facility_name'] ?? 'Facility') . ' is awaiting the facility\'s confirmation. We\'ll notify you once it\'s approved.',
            'community'
        );

        // createBooking() tells the owner about a new court request; an Open
        // Play join request needs the same owner approval but never did.
        $ownerId = $this->facilities->getFacilityOwnerId($match['facility_id'] ?? 0);
        if ($ownerId !== null && $ownerId !== (string)$userId) {
            $joinerName = $this->users->getUserById((string)$userId)['name'] ?? 'A player';
            $this->notifications->addNotification(
                $ownerId,
                'New Open Play request 🔥',
                sprintf(
                    '%s requested to join %s at %s (%s). Review it in your Requests queue.',
                    $joinerName,
                    $match['type'] ?? 'Open Play',
                    $match['facility_name'] ?? 'your facility',
                    $match['time'] ?? ''
                ),
                'booking'
            );
        }

        return [
            'success' => true,
            'message' => 'Join request sent to facility owner for approval!',
            'booking' => $booking
        ];
    }
}
