<?php
declare(strict_types=1);

namespace Picklers\Admin\Service;

use Doctrine\DBAL\Connection;

/**
 * The ONE definition of every Control Center KPI. The first render, the
 * `admin_stats` refresh and the analytics totals all call summary(), so the
 * numbers cannot drift apart (A04). Every figure is a direct count/sum over
 * persisted rows; nothing is estimated, and labels say exactly what is counted.
 */
final class MetricsService
{
    /** Human-readable definitions, rendered next to the numbers and in docs. */
    public const DEFINITIONS = [
        'active_accounts' => 'Accounts that are not deactivated (players, owners and administrators).',
        'players' => 'Active accounts that are neither administrators nor facility owners.',
        'facility_owners' => 'Active accounts holding the owner capacity.',
        'pending_applications' => 'Partner applications with status "pending review".',
        'facilities' => 'Facility listings in the database (any status).',
        'suspended_facilities' => 'Facilities an administrator has suspended from new bookings.',
        'bookings_total' => 'Every booking row, any status.',
        'bookings_pending' => 'Booking requests waiting for the venue (pending/upcoming).',
        'booking_value_confirmed' => 'Sum of booking prices for confirmed + completed bookings. This is booking VALUE, not money collected — see the Financial Ledger.',
        'cancellation_rate' => 'Cancelled or declined bookings as a share of all bookings.',
        'redeemable_promos' => 'Promo codes that are active, not archived, not expired and not exhausted.',
        'open_moderation_cases' => 'Moderation cases still open.',
    ];

    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array<string,int|string|float|null> */
    public function summary(): array
    {
        $users = $this->db->fetchAssociative(
            "SELECT
                SUM(role <> 'deleted') AS active_accounts,
                SUM(role <> 'deleted' AND is_admin = 0 AND is_owner = 0 AND role <> 'owner') AS players,
                SUM(role <> 'deleted' AND (is_owner = 1 OR role = 'owner')) AS facility_owners
             FROM users"
        ) ?: [];
        $bookings = $this->db->fetchAssociative(
            "SELECT COUNT(*) AS total,
                    SUM(status IN ('pending','upcoming')) AS pending,
                    SUM(status IN ('cancelled','declined')) AS cancelled,
                    COALESCE(SUM(CASE WHEN status IN ('confirmed','completed') THEN price END), 0) AS confirmed_value
             FROM bookings"
        ) ?: [];
        $total = (int)($bookings['total'] ?? 0);

        return [
            'active_accounts' => (int)($users['active_accounts'] ?? 0),
            'players' => (int)($users['players'] ?? 0),
            'facility_owners' => (int)($users['facility_owners'] ?? 0),
            'pending_applications' => (int)$this->db->fetchOne("SELECT COUNT(*) FROM owner_applications WHERE status = 'pending_review'"),
            'facilities' => (int)$this->db->fetchOne('SELECT COUNT(*) FROM facilities'),
            'suspended_facilities' => (int)$this->db->fetchOne("SELECT COUNT(*) FROM facilities WHERE operating_status = 'suspended'"),
            'bookings_total' => $total,
            'bookings_pending' => (int)($bookings['pending'] ?? 0),
            'booking_value_confirmed' => Money::toDecimal(Money::fromColumn($bookings['confirmed_value'] ?? '0')),
            // null (shown as "Not available") rather than a fabricated 0% / 100%.
            'cancellation_rate' => $total > 0 ? round(((int)($bookings['cancelled'] ?? 0) / $total) * 100, 1) : null,
            'redeemable_promos' => (int)$this->db->fetchOne(
                "SELECT COUNT(*) FROM promo_codes
                  WHERE status = 'active' AND archived_at IS NULL
                    AND (expires_at IS NULL OR expires_at >= NOW())
                    AND (usage_limit <= 0 OR times_used < usage_limit)"
            ),
            'open_moderation_cases' => (int)$this->db->fetchOne("SELECT COUNT(*) FROM moderation_cases WHERE status = 'open'"),
        ];
    }

    /**
     * Recent platform activity with drill-down targets, newest first.
     *
     * @return list<array{kind:string,title:string,detail:string,at:string,tab:string,query:array<string,string>}>
     */
    public function recentActivity(int $limit = 8): array
    {
        $limit = max(1, min(30, $limit));
        $rows = $this->db->fetchAllAssociative(
            "(SELECT 'application' AS kind, id AS ref, facility_name AS a, owner_name AS b, status AS c, created_at AS at FROM owner_applications ORDER BY created_at DESC LIMIT {$limit})
             UNION ALL
             (SELECT 'booking', id, facility_name, court_name, status, created_at FROM bookings ORDER BY created_at DESC LIMIT {$limit})
             UNION ALL
             (SELECT 'moderation', id, content_type, category, status, opened_at FROM moderation_cases ORDER BY opened_at DESC LIMIT {$limit})
             ORDER BY at DESC LIMIT {$limit}"
        );

        return array_map(static function (array $r): array {
            return match ($r['kind']) {
                'application' => [
                    'kind' => 'application',
                    'title' => 'Partner application · ' . str_replace('_', ' ', (string)$r['c']),
                    'detail' => trim(($r['a'] ?? 'Unnamed facility') . ' — ' . ($r['b'] ?? 'Applicant')),
                    'at' => (string)$r['at'],
                    'tab' => 'applications',
                    'query' => ['q' => (string)$r['ref']],
                ],
                'booking' => [
                    'kind' => 'booking',
                    'title' => 'Booking #' . $r['ref'] . ' · ' . $r['c'],
                    'detail' => trim(($r['a'] ?? 'Facility') . ' · ' . ($r['b'] ?? '')),
                    'at' => (string)$r['at'],
                    'tab' => 'bookings',
                    'query' => ['q' => (string)$r['ref']],
                ],
                default => [
                    'kind' => 'moderation',
                    'title' => 'Moderation case · ' . $r['c'],
                    'detail' => ucfirst((string)$r['a']) . ' flagged: ' . str_replace('_', ' ', (string)$r['b']),
                    'at' => (string)$r['at'],
                    'tab' => 'moderation',
                    'query' => ['q' => (string)$r['ref']],
                ],
            };
        }, $rows);
    }

    /** Items newer than this admin's last "seen" marker (the bell's unread state). */
    public function unseenCount(string $adminId): int
    {
        $seen = $this->db->fetchOne('SELECT seen_at FROM admin_activity_seen WHERE user_id = ?', [$adminId]);
        $since = $seen !== false ? (string)$seen : '1970-01-01 00:00:00';

        return (int)$this->db->fetchOne(
            "SELECT (SELECT COUNT(*) FROM owner_applications WHERE created_at > ?)
                  + (SELECT COUNT(*) FROM bookings WHERE created_at > ?)
                  + (SELECT COUNT(*) FROM moderation_cases WHERE opened_at > ?)",
            [$since, $since, $since]
        );
    }

    public function markSeen(string $adminId): void
    {
        $this->db->executeStatement(
            'INSERT INTO admin_activity_seen (user_id, seen_at) VALUES (?, ?) ON DUPLICATE KEY UPDATE seen_at = VALUES(seen_at)',
            [$adminId, date('Y-m-d H:i:s')]
        );
    }
}
