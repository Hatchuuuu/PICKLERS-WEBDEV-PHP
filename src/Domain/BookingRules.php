<?php
declare(strict_types=1);

namespace Picklers\Domain;

use Doctrine\DBAL\Connection;

/**
 * Booking rules shared by the owner portal, the player app and the admin console:
 * one implementation of confirming and staff-cancelling a booking, the booking
 * block (suspended venue / court in maintenance), Open Play seat accounting and
 * the date/time interpretation those rules depend on.
 */
final class BookingRules
{
    use SharedTransaction;

    public function __construct(
        private readonly Connection $db,
        private readonly Notifier $notifier,
        private readonly WalletLedger $ledger,
    ) {
    }

    /**
     * Why a NEW booking cannot be taken at this facility/court right now, or null.
     * Inside a transaction the facility row is share-locked, so a booking cannot
     * slip in while an administrator is suspending the venue.
     */
    public function blockReason(int|string $facilityId, ?string $courtId = null, ?string $courtName = null): ?string
    {
        if ($this->hasColumn('facilities', 'operating_status')) {
            $lock = $this->inTransaction() ? ' LOCK IN SHARE MODE' : '';
            $status = $this->db->fetchOne("SELECT operating_status FROM facilities WHERE id = ?{$lock}", [(int)$facilityId]);
            if ($status !== false && strtolower((string)$status) === 'suspended') {
                return 'This facility is temporarily unavailable for new bookings.';
            }
        }
        if (($courtId ?? '') !== '' || ($courtName ?? '') !== '') {
            $courtStatus = $this->db->fetchOne(
                'SELECT status FROM courts WHERE facility_id = ? AND (id = ? OR name = ?) ORDER BY (id = ?) DESC LIMIT 1',
                [(int)$facilityId, (string)$courtId, (string)$courtName, (string)$courtId]
            );
            if ($courtStatus !== false && in_array(strtolower((string)$courtStatus), ['maintenance', 'unavailable'], true)) {
                return 'This court is under maintenance and cannot be booked right now.';
            }
        }

        return null;
    }

    /** The Open Play session a booking belongs to, including legacy rows without match_id. */
    public function resolveMatchId(array $booking): ?string
    {
        if (!empty($booking['match_id']) || !self::mayBelongToMatch($booking)) {
            return self::matchIdFor($booking, []);
        }
        $matches = $this->db->fetchAllAssociative('SELECT id, type, date, time FROM matches WHERE facility_id = ?', [$booking['facility_id'] ?? 0]);

        return self::matchIdFor($booking, $matches);
    }

    /**
     * Pure part of resolveMatchId(): a booking with match_id names its session;
     * an older row is matched by court label, date and time.
     *
     * @param iterable<array<string,mixed>> $facilityMatches
     */
    public static function matchIdFor(array $booking, iterable $facilityMatches): ?string
    {
        if (!empty($booking['match_id'])) {
            return (string)$booking['match_id'];
        }
        if (!self::mayBelongToMatch($booking)) {
            return null;
        }
        foreach ($facilityMatches as $m) {
            if (($m['type'] ?? '') === ($booking['court_name'] ?? '') && ($m['date'] ?? '') === ($booking['date'] ?? '') && ($m['time'] ?? '') === ($booking['time'] ?? '')) {
                return (string)$m['id'];
            }
        }

        return null;
    }

    /** Rows without match_id: only Open Play court labels or PKL-OP- ids can belong to a session. */
    private static function mayBelongToMatch(array $booking): bool
    {
        return !empty($booking['court_name']) || str_starts_with((string)($booking['id'] ?? ''), 'PKL-OP-');
    }

    /**
     * Seats taken in one occurrence of an Open Play session. booking_date is
     * authoritative whenever it is set; the display-string/created_at fallbacks
     * apply to rows that predate it.
     */
    public function countActiveMatchBookings(string $matchId, string $targetDate): int
    {
        return (int)$this->db->fetchOne(
            "SELECT COUNT(*) FROM bookings WHERE match_id = ? AND status IN ('pending', 'confirmed')
               AND (booking_date = ? OR (booking_date IS NULL AND (date = ? OR date LIKE ? OR created_at LIKE ?)))",
            [$matchId, $targetDate, $targetDate, "%{$targetDate}%", "{$targetDate}%"]
        );
    }

    public function adjustMatchPlayerCount(string $matchId, int $delta): void
    {
        $this->db->executeStatement('UPDATE matches SET current_players = GREATEST(0, current_players + ?) WHERE id = ?', [$delta, $matchId]);
    }

    /**
     * The calendar date an Open Play session refers to. "Everyday" sessions roll
     * over to tomorrow once today's session has ended.
     */
    public static function matchTargetDate(array $match): string
    {
        $dateStr = trim((string)($match['date'] ?? ''));
        $timeStr = trim((string)($match['time'] ?? ''));
        $todayStr = date('Y-m-d');

        $lower = strtolower($dateStr);
        if (!str_contains($lower, 'everyday') && !str_contains($lower, 'daily')) {
            $ts = strtotime($dateStr);

            return $ts !== false ? date('Y-m-d', $ts) : ($dateStr !== '' ? $dateStr : $todayStr);
        }

        $startTimeStr = '';
        $endTimeStr = $timeStr;
        foreach (['-', '–', 'to'] as $separator) {
            if (str_contains($timeStr, $separator)) {
                $parts = explode($separator, $timeStr);
                $startTimeStr = trim($parts[0]);
                $endTimeStr = trim(end($parts));
                break;
            }
        }

        if ($endTimeStr !== '') {
            $endTs = strtotime($todayStr . ' ' . $endTimeStr);
            $startTs = $startTimeStr !== '' ? strtotime($todayStr . ' ' . $startTimeStr) : false;
            if ($endTs !== false && $startTs !== false && $endTs <= $startTs) {
                $endTs = strtotime('+1 day', $endTs);
            }
            if ($endTs !== false && time() >= $endTs) {
                return date('Y-m-d', strtotime('+1 day'));
            }
        }

        return $todayStr;
    }

    /** "6:00 PM - 8:00 PM" → [start, end] in minutes since midnight (end past 1440 when it crosses midnight). */
    public static function parseTimeRange(string $range): ?array
    {
        $parts = preg_split('/\s*[-–—]\s*/u', trim($range));
        if (!$parts || count($parts) !== 2) {
            return null;
        }
        $toMinutes = static function (string $t): ?int {
            $t = trim($t);
            $ts = $t === '' ? false : strtotime($t);

            return $ts === false ? null : ((int)date('G', $ts) * 60) + (int)date('i', $ts);
        };
        $start = $toMinutes($parts[0]);
        $end = $toMinutes($parts[1]);
        if ($start === null || $end === null) {
            return null;
        }
        if ($end <= $start) {
            $end += 1440;
        }

        return [$start, $end];
    }

    /** "today", "tomorrow", "Oct 8, 2026" … → Y-m-d, or null when it cannot be read. */
    public static function resolveDisplayDate(string $date): ?string
    {
        $trimmed = trim($date);
        $resolved = match (strtolower($trimmed)) {
            'today', 'tonight' => date('Y-m-d'),
            'tomorrow' => date('Y-m-d', strtotime('+1 day')),
            default => null,
        };
        if ($resolved !== null) {
            return $resolved;
        }
        $ts = strtotime($trimmed);

        return $ts !== false ? date('Y-m-d', $ts) : null;
    }

    /** Whether a booking's session is over (ended early, or its date/end time has passed). */
    public static function isBookingPast(array $booking): bool
    {
        if (!empty($booking['ended_early'])) {
            return true;
        }
        if (in_array((string)($booking['status'] ?? ''), ['cancelled', 'declined'], true)) {
            return false; // its own bucket, not "past"
        }
        $dateStr = (string)($booking['booking_date'] ?? '');
        if ($dateStr === '') {
            $dateStr = self::resolveDisplayDate((string)($booking['date'] ?? '')) ?? '';
        }
        if ($dateStr === '') {
            return false; // can't tell — don't hide it
        }
        $todayStr = date('Y-m-d');
        if ($dateStr !== $todayStr) {
            return $dateStr < $todayStr;
        }
        $endMin = isset($booking['end_min']) && $booking['end_min'] !== null
            ? (int)$booking['end_min']
            : (self::parseTimeRange((string)($booking['time'] ?? ''))[1] ?? null);
        if ($endMin === null) {
            return false;
        }

        return ((int)date('G') * 60) + (int)date('i') >= $endMin;
    }

    /**
     * Accept a pending/upcoming booking request (owner approval or admin confirmation).
     *
     * The booking is row-locked, an Open Play session is checked for capacity for
     * THIS occurrence, the status moves by compare-and-set, the seat is taken and
     * the player is told — exactly once.
     *
     * @param array{actor_id?:?string,reason?:?string,before_commit?:callable(array):void} $opts
     * @return array{ok:bool,code:int,message:string,status:string,already:bool,booking:?array}
     */
    public function confirmRequest(string $bookingId, array $opts = []): array
    {
        $owns = $this->beginOwnTransaction();
        try {
            $booking = $this->db->fetchAssociative('SELECT * FROM bookings WHERE id = ? FOR UPDATE', [$bookingId]);
            $fail = function (int $code, string $message, string $status = '', bool $already = false) use ($owns, $booking): array {
                $this->rollBackOwn($owns);

                return ['ok' => $already, 'code' => $code, 'message' => $message, 'status' => $status, 'already' => $already, 'booking' => $booking ?: null];
            };
            if (!$booking) {
                return $fail(404, 'Booking record not found.');
            }
            $prev = (string)($booking['status'] ?? 'pending');
            if ($prev === 'confirmed') {
                return $fail(200, 'This booking is already confirmed.', 'confirmed', true);
            }
            if (!in_array($prev, ['pending', 'upcoming'], true)) {
                return $fail(409, "Only pending requests can be confirmed (this booking is {$prev}).", $prev);
            }
            if (($block = $this->blockReason($booking['facility_id'] ?? 0, $booking['court_id'] ?? null, $booking['court_name'] ?? null)) !== null
                && empty($booking['match_id']) && !str_starts_with($bookingId, 'PKL-OP-')) {
                return $fail(409, $block . ' Decline this request instead.', $prev);
            }
            $matchId = $this->resolveMatchId($booking);
            if ($matchId) {
                $match = $this->db->fetchAssociative('SELECT * FROM matches WHERE id = ? FOR UPDATE', [$matchId]);
                if ($match) {
                    $occurrence = (string)($booking['booking_date'] ?? '') !== '' ? (string)$booking['booking_date'] : self::matchTargetDate($match);
                    if ($this->countActiveMatchBookings($matchId, $occurrence) > (int)($match['max_players'] ?? 0)) {
                        return $fail(409, 'This Open Play session is already full. Decline this request or increase its capacity first.', $prev);
                    }
                }
            }
            [$set, $params] = $this->statusChange('confirmed', $opts);
            $this->db->executeStatement("UPDATE bookings SET {$set} WHERE id = ? AND status IN ('pending','upcoming')", [...$params, $bookingId]);
            if ($matchId) {
                $this->adjustMatchPlayerCount($matchId, 1);
            }
            $this->notifier->notify(
                (string)($booking['user_id'] ?? ''),
                'Booking Confirmed! 🎾',
                'Great news! Your reservation for ' . ($booking['court_name'] ?? 'your court') . ' at ' . ($booking['facility_name'] ?? 'the facility') . ' on '
                    . ($booking['date'] ?? 'the requested date') . ' (' . ($booking['time'] ?? 'the requested time') . ') has been confirmed. See you on the court!',
                'booking'
            );
            $booking['status'] = 'confirmed';
            if (isset($opts['before_commit'])) {
                ($opts['before_commit'])(['booking' => $booking, 'previous_status' => $prev, 'match_id' => $matchId]);
            }
            if ($owns) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            $this->rollBackOwn($owns);
            throw $e;
        }
        $this->notifier->bumpSync('bookings', 'courts');

        return ['ok' => true, 'code' => 200, 'message' => 'Booking confirmed.', 'status' => 'confirmed', 'already' => false, 'booking' => $booking];
    }

    /**
     * Cancel a booking on behalf of the venue or the platform (owner decline,
     * admin cancellation) — one rule for both:
     *  - the booking row is locked and must be in an allowed status;
     *  - a confirmed Open Play seat is released;
     *  - Pickle Credits payments are refunded in full, once (idempotency key
     *    refund:booking:<id>); external payments are never "refunded" in-app —
     *    the result says so instead;
     *  - the player is notified with the given wording and reason.
     *
     * @param array{allowed_from?:list<string>,actor_id?:?string,reason?:?string,notify_title?:string,notify_lead?:string,refund_label?:string,before_commit?:callable(array):void} $opts
     * @return array{ok:bool,code:int,message:string,previous_status:?string,refunded:bool,refund_amount:string,external_payment:bool,booking:?array}
     */
    public function cancelAsStaff(string $bookingId, array $opts = []): array
    {
        $allowed = $opts['allowed_from'] ?? ['pending', 'upcoming', 'confirmed', 'completed'];
        $owns = $this->beginOwnTransaction();
        try {
            $b = $this->db->fetchAssociative('SELECT * FROM bookings WHERE id = ? FOR UPDATE', [$bookingId]);
            $result = ['ok' => false, 'code' => 404, 'message' => 'Booking record not found.', 'previous_status' => null, 'refunded' => false, 'refund_amount' => '0.00', 'external_payment' => false, 'booking' => $b ?: null];
            if (!$b) {
                $this->rollBackOwn($owns);

                return $result;
            }
            $prev = (string)($b['status'] ?? 'pending');
            $result['previous_status'] = $prev;
            if (in_array($prev, ['cancelled', 'declined'], true)) {
                $this->rollBackOwn($owns);

                return ['code' => 409, 'message' => 'This booking is already cancelled.'] + $result;
            }
            if (!in_array($prev, $allowed, true)) {
                $this->rollBackOwn($owns);

                return ['code' => 409, 'message' => "A {$prev} booking cannot be cancelled here."] + $result;
            }

            [$set, $params] = $this->statusChange('cancelled', $opts);
            $this->db->executeStatement("UPDATE bookings SET {$set} WHERE id = ?", [...$params, $bookingId]);
            if ($prev === 'confirmed' && ($matchId = $this->resolveMatchId($b))) {
                $this->adjustMatchPlayerCount($matchId, -1);
            }

            $userId = (string)($b['user_id'] ?? '');
            $method = (string)($b['payment_method'] ?? '');
            $price = number_format((float)($b['price'] ?? 0), 2, '.', '');
            if ($method === 'Pickle Credits' && (float)$price > 0) {
                $result['refunded'] = $this->ledger->creditOnce(
                    $userId, $price,
                    $opts['refund_label'] ?? "Refund: Booking #{$bookingId} declined by facility owner",
                    'refund:booking:' . $bookingId,
                    ['entry_kind' => 'refund', 'booking_id' => $bookingId, 'actor_user_id' => $opts['actor_id'] ?? null, 'reason' => $opts['reason'] ?? null]
                );
                $result['refund_amount'] = $result['refunded'] ? $price : '0.00';
            } elseif ((float)$price > 0 && $method !== 'Pay at Venue') {
                $result['external_payment'] = true;
            }

            $lead = $opts['notify_lead'] ?? "Your reservation (#{$bookingId}) at " . ($b['facility_name'] ?? 'the facility') . ' was declined.';
            $body = $lead
                . (!empty($opts['reason']) ? ' Reason: ' . $opts['reason'] . '.' : '')
                . ($result['refunded'] ? ' ₱' . number_format((float)$price, 2) . ' has been refunded to your wallet.' : '')
                . ($result['external_payment'] ? " This booking was paid via {$method}; contact the facility or Picklers support about that payment." : '');
            $this->notifier->notify($userId, $opts['notify_title'] ?? 'Booking Declined ⚠️', $body, 'booking');

            $b['status'] = 'cancelled';
            $result = ['ok' => true, 'code' => 200, 'message' => 'Booking cancelled.', 'booking' => $b] + $result;
            if (isset($opts['before_commit'])) {
                ($opts['before_commit'])($result);
            }
            if ($owns) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            $this->rollBackOwn($owns);
            error_log('[DB Error] cancelBookingAsStaff failed: ' . $e->getMessage());
            throw $e;
        }
        $this->notifier->bumpSync('bookings', 'courts');

        return $result;
    }

    /**
     * SET clause and its parameters for a status change, recording who/when/why
     * when those columns exist.
     *
     * @return array{0:string,1:list<?string>}
     */
    private function statusChange(string $status, array $opts): array
    {
        if (!$this->hasColumn('bookings', 'status_changed_by')) {
            return ["status = '{$status}'", []];
        }

        return [
            "status = '{$status}', status_changed_by = ?, status_changed_at = ?, status_reason = ?",
            [$opts['actor_id'] ?? null, date('Y-m-d H:i:s'), $opts['reason'] ?? null],
        ];
    }
}
