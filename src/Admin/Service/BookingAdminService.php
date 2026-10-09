<?php
declare(strict_types=1);

namespace Picklers\Admin\Service;

use Doctrine\DBAL\Connection;
use Picklers\Admin\Http\AdminActionException;
use Picklers\Admin\Repository\AuditRepository;
use Picklers\Admin\Security\AdminUser;
use Picklers\Domain\BookingRules;
use Picklers\Domain\Notifier;

/**
 * Bookings & matches administration.
 *
 * Status rules (admin):
 *   pending | upcoming  → confirmed   (same rule as an owner approving: capacity, one seat)
 *   confirmed           → completed   (only once the session has started)
 *   pending | upcoming | confirmed → cancelled  (reason required; shared refund rule)
 *   completed, cancelled, declined → final (no reinstatement; refunds for a completed
 *                                     session are a separate, privileged wallet adjustment)
 *
 * Every change carries the status the operator SAW (expected_status); if the row
 * changed meanwhile the request is refused as stale instead of overwriting it.
 */
final class BookingAdminService
{
    public const STATUSES = ['pending', 'upcoming', 'confirmed', 'completed', 'cancelled', 'declined'];
    public const PAYMENTS = ['pickle_credits' => 'Pickle Credits', 'gcash' => 'GCash', 'maya' => 'Maya', 'card' => 'Card', 'venue' => 'Pay at Venue'];
    public const SORTS = ['created_at', 'booking_date', 'price'];
    public const KINDS = ['court', 'open_play'];

    private const TRANSITIONS = [
        'pending' => ['confirmed', 'cancelled'],
        'upcoming' => ['confirmed', 'cancelled'],
        'confirmed' => ['completed', 'cancelled'],
        'completed' => [],
        'cancelled' => [],
        'declined' => [],
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly BookingRules $rules,
        private readonly Notifier $notifier,
        private readonly AuditLog $audit,
        private readonly AuditRepository $auditRepository,
    ) {
    }

    /** @return list<string> */
    public static function allowedTransitions(string $status): array
    {
        return self::TRANSITIONS[$status] ?? [];
    }

    /** @return array{0:string,1:list<mixed>} */
    private function where(ListQuery $query): array
    {
        $where = ['1=1'];
        $params = [];
        if ($query->q !== '') {
            $where[] = '(b.id LIKE ? OR b.facility_name LIKE ? OR b.court_name LIKE ? OR u.name LIKE ? OR u.email LIKE ?)';
            array_push($params, ...array_fill(0, 5, $query->likePattern()));
        }
        if (($v = $query->filter('status')) !== null) {
            $where[] = 'b.status = ?';
            $params[] = $v;
        }
        if (($v = $query->filter('payment')) !== null) {
            $where[] = 'LOWER(b.payment_method) = LOWER(?)';
            $params[] = self::PAYMENTS[$v];
        }
        if (($v = $query->filter('facility')) !== null) {
            $where[] = 'b.facility_id = ?';
            $params[] = (int)$v;
        }
        if (($v = $query->filter('user')) !== null) {
            $where[] = 'b.user_id = ?';
            $params[] = $v;
        }
        if (($v = $query->filter('kind')) !== null) {
            $where[] = $v === 'open_play' ? "(b.match_id IS NOT NULL OR b.id LIKE 'PKL-OP-%')" : "(b.match_id IS NULL AND b.id NOT LIKE 'PKL-OP-%')";
        }
        if (($v = $query->filter('from')) !== null) {
            $where[] = 'b.booking_date >= ?';
            $params[] = $v;
        }
        if (($v = $query->filter('to')) !== null) {
            $where[] = 'b.booking_date <= ?';
            $params[] = $v;
        }

        return [implode(' AND ', $where), $params];
    }

    /** @return Page<array<string,mixed>> */
    public function search(ListQuery $query): Page
    {
        [$sql, $params] = $this->where($query);
        $summary = $this->db->fetchAssociative(
            "SELECT COUNT(*) AS total, COALESCE(SUM(b.price), 0) AS value FROM bookings b LEFT JOIN users u ON u.id = b.user_id WHERE {$sql}",
            $params
        ) ?: ['total' => 0, 'value' => '0'];
        $total = (int)$summary['total'];
        if ($total > 0 && $query->offset() >= $total) {
            $query = $query->withPage((int)ceil($total / $query->perPage));
        }
        $order = match ($query->sort) {
            'booking_date' => 'b.booking_date',
            'price' => 'b.price',
            default => 'b.created_at',
        };
        $dir = $query->dir === 'asc' ? 'ASC' : 'DESC';
        $rows = $this->db->fetchAllAssociative(
            "SELECT b.*, u.name AS player_name, u.email AS player_email
               FROM bookings b LEFT JOIN users u ON u.id = b.user_id
              WHERE {$sql} ORDER BY {$order} {$dir}, b.id ASC LIMIT {$query->perPage} OFFSET {$query->offset()}",
            $params
        );
        foreach ($rows as &$row) {
            $row['transitions'] = self::allowedTransitions((string)$row['status']);
            $row['is_open_play'] = !empty($row['match_id']) || str_starts_with((string)$row['id'], 'PKL-OP-');
        }
        unset($row);

        return new Page($rows, $total, $query, ['value' => Money::toDecimal(Money::fromColumn($summary['value']))]);
    }

    /** @return array<string,mixed> */
    public function detail(string $id): array
    {
        $b = $this->db->fetchAssociative(
            'SELECT b.*, u.name AS player_name, u.email AS player_email, u.phone AS player_phone, u.role AS player_role,
                    f.owner_id, f.operating_status AS facility_status, o.name AS owner_name, s.name AS status_changed_by_name
               FROM bookings b
               LEFT JOIN users u ON u.id = b.user_id
               LEFT JOIN facilities f ON f.id = b.facility_id
               LEFT JOIN users o ON o.id = f.owner_id
               LEFT JOIN users s ON s.id = b.status_changed_by
              WHERE b.id = ?',
            [$id]
        );
        if ($b === false) {
            throw AdminActionException::notFound('Booking');
        }
        $b['transitions'] = self::allowedTransitions((string)$b['status']);
        $matchId = $this->rules->resolveMatchId($b);
        $b['match'] = null;
        if ($matchId !== null) {
            $match = $this->db->fetchAssociative('SELECT * FROM matches WHERE id = ?', [$matchId]);
            if ($match !== false) {
                $occurrence = (string)($b['booking_date'] ?? '') !== '' ? (string)$b['booking_date'] : BookingRules::matchTargetDate($match);
                $match['active_for_occurrence'] = $this->rules->countActiveMatchBookings($matchId, $occurrence);
                $match['occurrence'] = $occurrence;
                $b['match'] = $match;
            }
        }
        $b['wallet_entries'] = $this->db->fetchAllAssociative(
            "SELECT id, type, amount, label, entry_kind, created_at, idempotency_key IS NOT NULL AS keyed
               FROM wallet_transactions
              WHERE user_id = ? AND (booking_id = ? OR label LIKE ?)
              ORDER BY created_at ASC",
            [$b['user_id'], $id, '%#' . addcslashes($id, '%_\\') . '%']
        );
        $b['promo'] = $this->db->fetchAssociative('SELECT promo_code, discount_amount, created_at FROM promo_redemptions WHERE booking_id = ? LIMIT 1', [$id]) ?: null;
        $b['history'] = $this->auditRepository->historyFor('booking', $id);

        return $b;
    }

    public function changeStatus(AdminUser $actor, string $id, string $to, string $expected, string $reason): array
    {
        if ($to === 'cancelled') {
            return $this->cancel($actor, $id, $expected, $reason);
        }
        if (!in_array($to, ['confirmed', 'completed'], true)) {
            throw AdminActionException::invalid('Bookings can be moved to confirmed, completed or cancelled.', 'status');
        }
        $reason = trim($reason);

        $current = $this->lockedCurrent($id, $expected);
        if (!in_array($to, self::allowedTransitions($current['status']), true)) {
            throw AdminActionException::conflict("A {$current['status']} booking cannot be moved to {$to}.", ['status' => $current['status']]);
        }

        if ($to === 'confirmed') {
            return $this->db->transactional(function () use ($actor, $id, $expected, $reason): array {
                $this->lockedCurrent($id, $expected, true);
                $res = $this->rules->confirmRequest($id, [
                    'actor_id' => $actor->id(),
                    'reason' => $reason !== '' ? $reason : null,
                    'before_commit' => function (array $ctx) use ($id, $reason): void {
                        $this->audit->record('booking.status', 'booking', $id, [
                            'status' => ['from' => $ctx['previous_status'], 'to' => 'confirmed'],
                            'open_play_match' => $ctx['match_id'],
                        ], $reason !== '' ? $reason : null);
                    },
                ]);
                if (!$res['ok'] || $res['already']) {
                    throw new AdminActionException($res['message'], $res['code'] === 200 ? 409 : $res['code']);
                }

                return ['status' => 'confirmed', 'message' => 'Booking confirmed and the player notified.'];
            });
        }

        // confirmed → completed
        return $this->db->transactional(function () use ($actor, $id, $expected, $reason): array {
            $row = $this->lockedCurrent($id, $expected, true);
            if (!BookingRules::isBookingPast($row) && !$this->hasStarted($row)) {
                throw AdminActionException::conflict('This session has not started yet, so it cannot be marked completed.');
            }
            $this->db->update('bookings', [
                'status' => 'completed',
                'status_changed_by' => $actor->id(),
                'status_changed_at' => date('Y-m-d H:i:s'),
                'status_reason' => $reason !== '' ? $reason : null,
            ], ['id' => $id]);
            $this->audit->record('booking.status', 'booking', $id, ['status' => ['from' => 'confirmed', 'to' => 'completed']], $reason !== '' ? $reason : null);
            $this->notifier->bumpSync('bookings', 'courts');

            return ['status' => 'completed', 'message' => 'Booking marked completed.'];
        });
    }

    public function cancel(AdminUser $actor, string $id, string $expected, string $reason): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw AdminActionException::invalid('Give a cancellation reason (at least 5 characters) — the player sees it.', 'reason');
        }
        $reason = mb_substr($reason, 0, 500);

        return $this->db->transactional(function () use ($actor, $id, $expected, $reason): array {
            $current = $this->lockedCurrent($id, $expected, true);
            if (!in_array('cancelled', self::allowedTransitions($current['status']), true)) {
                throw AdminActionException::conflict("A {$current['status']} booking cannot be cancelled. For a completed session, issue a wallet adjustment instead.", ['status' => $current['status']]);
            }
            $res = $this->rules->cancelAsStaff($id, [
                'allowed_from' => ['pending', 'upcoming', 'confirmed'],
                'actor_id' => $actor->id(),
                'reason' => $reason,
                'notify_title' => 'Booking cancelled by Picklers',
                'notify_lead' => "Your reservation (#{$id}) at " . ($current['facility_name'] ?? 'the facility') . ' was cancelled by Picklers support.',
                'refund_label' => "Refund: Booking #{$id} cancelled by Picklers support",
                'before_commit' => function (array $r) use ($id, $reason): void {
                    $this->audit->record('booking.cancel', 'booking', $id, [
                        'status' => ['from' => $r['previous_status'], 'to' => 'cancelled'],
                        'payment_method' => $r['booking']['payment_method'] ?? null,
                        'refunded_in_app' => $r['refunded'],
                        'refund_amount' => $r['refund_amount'],
                        'external_payment_unrefunded' => $r['external_payment'],
                    ], $reason);
                },
            ]);
            if (!$res['ok']) {
                throw new AdminActionException($res['message'], $res['code']);
            }
            $method = (string)($res['booking']['payment_method'] ?? '');
            $note = match (true) {
                $res['refunded'] => ' ₱' . number_format((float)$res['refund_amount'], 2) . ' refunded to the player\'s Pickle Credits.',
                $res['external_payment'] => " Paid via {$method}: no in-app refund was made (there is no payment-provider integration) — settle it with the player outside the app.",
                $method === 'Pickle Credits' => ' No refund issued: a refund for this booking already exists.',
                default => ' Nothing was charged in-app.',
            };

            return [
                'status' => 'cancelled',
                'refunded' => $res['refunded'],
                'refund_amount' => $res['refund_amount'],
                'external_payment' => $res['external_payment'],
                'message' => 'Booking cancelled.' . $note,
            ];
        });
    }

    /** Lock (when in a transaction) and verify the operator's view is current. */
    private function lockedCurrent(string $id, string $expected, bool $lock = false): array
    {
        $row = $this->db->fetchAssociative('SELECT * FROM bookings WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''), [$id]);
        if ($row === false) {
            throw AdminActionException::notFound('Booking');
        }
        $expected = trim($expected);
        if ($expected !== '' && $expected !== (string)$row['status']) {
            throw AdminActionException::conflict("This booking changed while you were viewing it — it is now {$row['status']}. Refresh and try again.", ['status' => $row['status']]);
        }

        return $row;
    }

    private function hasStarted(array $booking): bool
    {
        if (empty($booking['booking_date']) || $booking['start_min'] === null) {
            return false;
        }
        $start = strtotime((string)$booking['booking_date'] . ' 00:00:00') + ((int)$booking['start_min']) * 60;

        return $start <= time();
    }
}
