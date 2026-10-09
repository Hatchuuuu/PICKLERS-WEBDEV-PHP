<?php
declare(strict_types=1);

namespace Picklers\Admin\Service;

use Doctrine\DBAL\Connection;

/**
 * Financial ledger built only from what the database actually records.
 *
 * Two views:
 *  - Wallet movements: every `wallet_transactions` row (credits/debits of Pickle
 *    Credits). New rows carry an explicit kind, actor, booking link and reason;
 *    older rows have only a label, so their kind is DERIVED from the label and
 *    shown as such.
 *  - Booking payments: each booking's payment evidence. Only Pickle Credits
 *    payments are collected in-app (a wallet debit exists). GCash / Maya / Card
 *    bookings have no payment-provider confirmation in this platform, so they
 *    are reported as unverified; Pay at Venue is not collected in-app. A booking
 *    being "not cancelled" is never treated as settled money.
 */
final class LedgerService
{
    public const KINDS = ['top_up', 'booking_payment', 'refund', 'admin_adjustment', 'other_credit', 'other_debit'];
    public const TYPES = ['credit', 'debit'];
    public const SETTLEMENTS = ['collected_in_app', 'refunded', 'external_unverified', 'pay_at_venue', 'no_wallet_record'];
    public const WALLET_SORTS = ['created_at', 'amount'];
    public const BOOKING_SORTS = ['created_at', 'price'];

    private const KIND_SQL = "COALESCE(wt.entry_kind, CASE
            WHEN wt.label LIKE '[Admin]%' THEN 'admin_adjustment'
            WHEN wt.type = 'credit' AND LOWER(wt.label) LIKE '%refund%' THEN 'refund'
            WHEN wt.type = 'debit' AND (wt.label LIKE 'Booking #%' OR wt.label LIKE 'Open Play #%') THEN 'booking_payment'
            WHEN wt.type = 'credit' AND (LOWER(wt.label) LIKE '%top%up%' OR LOWER(wt.label) IN ('gcash','gcash ph','maya','card','bdo','bpi','grabpay')) THEN 'top_up'
            WHEN wt.type = 'credit' THEN 'other_credit'
            ELSE 'other_debit' END)";
    private const BOOKING_REF_SQL = "COALESCE(wt.booking_id, NULLIF(SUBSTRING(REGEXP_SUBSTR(wt.label, '#[A-Za-z0-9-]+'), 2), ''))";
    private const METHOD_SQL = "CASE
            WHEN b.payment_method = 'Pickle Credits' THEN 'pickle_credits'
            WHEN LOWER(b.payment_method) IN ('gcash','maya','card') THEN 'external'
            WHEN b.payment_method = 'Pay at Venue' THEN 'venue'
            ELSE 'other' END";

    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array{0:string,1:list<mixed>} */
    private function walletWhere(ListQuery $query): array
    {
        $where = ['1=1'];
        $params = [];
        if ($query->q !== '') {
            $where[] = '(wt.id = ? OR wt.label LIKE ? OR u.name LIKE ? OR u.email LIKE ? OR wt.reason LIKE ?)';
            array_push($params, $query->q, $query->likePattern(), $query->likePattern(), $query->likePattern(), $query->likePattern());
        }
        if (($v = $query->filter('type')) !== null) {
            $where[] = 'wt.type = ?';
            $params[] = $v;
        }
        if (($v = $query->filter('kind')) !== null) {
            $where[] = self::KIND_SQL . ' = ?';
            $params[] = $v;
        }
        if (($v = $query->filter('user')) !== null) {
            $where[] = 'wt.user_id = ?';
            $params[] = $v;
        }
        if (($v = $query->filter('facility')) !== null) {
            $where[] = 'b.facility_id = ?';
            $params[] = (int)$v;
        }
        if (($v = $query->filter('from')) !== null) {
            $where[] = 'wt.created_at >= ?';
            $params[] = $v . ' 00:00:00';
        }
        if (($v = $query->filter('to')) !== null) {
            $where[] = 'wt.created_at <= ?';
            $params[] = $v . ' 23:59:59';
        }

        return [implode(' AND ', $where), $params];
    }

    /** @return Page<array<string,mixed>> */
    public function walletEntries(ListQuery $query): Page
    {
        [$sql, $params] = $this->walletWhere($query);
        $from = 'FROM wallet_transactions wt LEFT JOIN users u ON u.id = wt.user_id LEFT JOIN bookings b ON b.id = ' . self::BOOKING_REF_SQL
            . ' LEFT JOIN users a ON a.id = wt.actor_user_id';
        $sum = $this->db->fetchAssociative(
            "SELECT COUNT(*) AS n,
                    COALESCE(SUM(CASE WHEN wt.type = 'credit' THEN wt.amount END), 0) AS credits,
                    COALESCE(SUM(CASE WHEN wt.type = 'debit' THEN wt.amount END), 0) AS debits
             {$from} WHERE {$sql}",
            $params
        ) ?: ['n' => 0, 'credits' => '0', 'debits' => '0'];
        $total = (int)$sum['n'];
        if ($total > 0 && $query->offset() >= $total) {
            $query = $query->withPage((int)ceil($total / $query->perPage));
        }
        $order = $query->sort === 'amount' ? 'wt.amount' : 'wt.created_at';
        $dir = $query->dir === 'asc' ? 'ASC' : 'DESC';
        $rows = $this->db->fetchAllAssociative(
            'SELECT wt.id, wt.user_id, wt.type, wt.amount, wt.label, wt.created_at, wt.reason, wt.balance_after, wt.actor_user_id,
                    wt.idempotency_key IS NOT NULL AS keyed, wt.entry_kind IS NULL AS kind_derived, ' . self::KIND_SQL . ' AS kind,
                    ' . self::BOOKING_REF_SQL . " AS booking_ref, b.facility_name, u.name AS user_name, u.email AS user_email, a.name AS actor_name
             {$from} WHERE {$sql} ORDER BY {$order} {$dir}, wt.id ASC LIMIT {$query->perPage} OFFSET {$query->offset()}",
            $params
        );
        $credits = Money::fromColumn($sum['credits']);
        $debits = Money::fromColumn($sum['debits']);

        return new Page($rows, $total, $query, [
            'credits' => Money::toDecimal($credits),
            'debits' => Money::toDecimal($debits),
            'net' => Money::toDecimal($credits - $debits),
        ]);
    }

    /** @return array{0:string,1:list<mixed>} */
    private function bookingWhere(ListQuery $query): array
    {
        $where = ['1=1'];
        $params = [];
        if ($query->q !== '') {
            $where[] = '(b.id LIKE ? OR b.facility_name LIKE ? OR u.name LIKE ?)';
            array_push($params, $query->likePattern(), $query->likePattern(), $query->likePattern());
        }
        if (($v = $query->filter('settlement')) !== null) {
            $where[] = self::settlementSql() . ' = ?';
            $params[] = $v;
        }
        if (($v = $query->filter('status')) !== null) {
            $where[] = 'b.status = ?';
            $params[] = $v;
        }
        if (($v = $query->filter('facility')) !== null) {
            $where[] = 'b.facility_id = ?';
            $params[] = (int)$v;
        }
        if (($v = $query->filter('user')) !== null) {
            $where[] = 'b.user_id = ?';
            $params[] = $v;
        }
        if (($v = $query->filter('from')) !== null) {
            $where[] = 'b.created_at >= ?';
            $params[] = $v . ' 00:00:00';
        }
        if (($v = $query->filter('to')) !== null) {
            $where[] = 'b.created_at <= ?';
            $params[] = $v . ' 23:59:59';
        }

        return [implode(' AND ', $where), $params];
    }

    private static function settlementSql(): string
    {
        $debit = "EXISTS (SELECT 1 FROM wallet_transactions d WHERE d.user_id = b.user_id AND d.type = 'debit' AND (d.booking_id = b.id OR d.label LIKE CONCAT('%#', b.id, '%')))";
        $refund = "EXISTS (SELECT 1 FROM wallet_transactions r WHERE r.user_id = b.user_id AND r.type = 'credit' AND (r.booking_id = b.id OR (r.label LIKE CONCAT('%#', b.id, '%') AND LOWER(r.label) LIKE '%refund%')))";

        return "(CASE
            WHEN b.payment_method = 'Pickle Credits' AND {$refund} THEN 'refunded'
            WHEN b.payment_method = 'Pickle Credits' AND {$debit} THEN 'collected_in_app'
            WHEN b.payment_method = 'Pickle Credits' THEN 'no_wallet_record'
            WHEN LOWER(b.payment_method) IN ('gcash','maya','card') THEN 'external_unverified'
            WHEN b.payment_method = 'Pay at Venue' THEN 'pay_at_venue'
            ELSE 'external_unverified' END)";
    }

    /** @return Page<array<string,mixed>> */
    public function bookingPayments(ListQuery $query): Page
    {
        [$sql, $params] = $this->bookingWhere($query);
        $from = 'FROM bookings b LEFT JOIN users u ON u.id = b.user_id';
        $settlement = self::settlementSql();
        $sum = $this->db->fetchAssociative(
            "SELECT COUNT(*) AS n,
                COALESCE(SUM(CASE WHEN b.status IN ('confirmed','completed') THEN b.price END), 0) AS gross,
                COALESCE(SUM(CASE WHEN {$settlement} = 'collected_in_app' THEN b.price END), 0) AS collected,
                COALESCE(SUM(CASE WHEN {$settlement} = 'refunded' THEN b.price END), 0) AS refunded,
                COALESCE(SUM(CASE WHEN {$settlement} = 'external_unverified' AND b.status NOT IN ('cancelled','declined') THEN b.price END), 0) AS unverified,
                COALESCE(SUM(CASE WHEN {$settlement} = 'pay_at_venue' AND b.status NOT IN ('cancelled','declined') THEN b.price END), 0) AS venue
             {$from} WHERE {$sql}",
            $params
        ) ?: [];
        $total = (int)($sum['n'] ?? 0);
        if ($total > 0 && $query->offset() >= $total) {
            $query = $query->withPage((int)ceil($total / $query->perPage));
        }
        $order = $query->sort === 'price' ? 'b.price' : 'b.created_at';
        $dir = $query->dir === 'asc' ? 'ASC' : 'DESC';
        $rows = $this->db->fetchAllAssociative(
            "SELECT b.id, b.user_id, b.facility_id, b.facility_name, b.court_name, b.date, b.time, b.price, b.payment_method, b.status, b.created_at,
                    u.name AS player_name, {$settlement} AS settlement
             {$from} WHERE {$sql} ORDER BY {$order} {$dir}, b.id ASC LIMIT {$query->perPage} OFFSET {$query->offset()}",
            $params
        );

        return new Page($rows, $total, $query, array_map(
            static fn($v) => Money::toDecimal(Money::fromColumn($v)),
            ['gross' => $sum['gross'] ?? 0, 'collected' => $sum['collected'] ?? 0, 'refunded' => $sum['refunded'] ?? 0, 'unverified' => $sum['unverified'] ?? 0, 'venue' => $sum['venue'] ?? 0]
        ));
    }

    /** @return list<array{id:int,name:string}> */
    public function facilityOptions(): array
    {
        return $this->db->fetchAllAssociative('SELECT id, name FROM facilities ORDER BY name');
    }
}
