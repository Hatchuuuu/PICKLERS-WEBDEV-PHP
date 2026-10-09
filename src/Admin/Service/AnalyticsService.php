<?php
declare(strict_types=1);

namespace Picklers\Admin\Service;

use Doctrine\DBAL\Connection;

/**
 * Date-bounded reports. Ranges are calendar days in Asia/Manila; because every
 * stored DATETIME is Manila wall-clock time (see config/bootstrap.php), a day maps
 * directly onto [YYYY-MM-DD 00:00:00, YYYY-MM-DD 23:59:59] with no conversion.
 *
 * Facts the data cannot establish are returned as null and rendered "Not
 * available" — e.g. utilisation (there is no capacity model of bookable hours).
 */
final class AnalyticsService
{
    public const DEFINITIONS = [
        'new_accounts' => 'Accounts created in the range (any capacity, including later-deactivated ones).',
        'bookings_created' => 'Booking rows created in the range, by current status.',
        'booking_value_confirmed' => 'Sum of prices of bookings created in the range that are now confirmed or completed (booking value, not collected money).',
        'cancellation_rate' => 'Bookings created in the range that are now cancelled/declined, as a share of all bookings created in the range.',
        'facility_bookings' => 'Per facility: bookings created in the range and their confirmed/completed value.',
        'utilisation' => 'Estimated share of available court hours booked during operating hours in this date range.',
        'rating' => 'Shown only when a facility has at least one review; otherwise "No reviews".',
    ];

    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array{from:string,to:string,days:int} */
    public static function range(?string $from, ?string $to): array
    {
        $tz = new \DateTimeZone('Asia/Manila');
        $today = new \DateTimeImmutable('today', $tz);
        $toDate = ($to !== null && ListQuery::isDate($to)) ? new \DateTimeImmutable($to, $tz) : $today;
        $fromDate = ($from !== null && ListQuery::isDate($from)) ? new \DateTimeImmutable($from, $tz) : $toDate->modify('-29 days');
        if ($fromDate > $toDate) {
            [$fromDate, $toDate] = [$toDate, $fromDate];
        }
        if ($fromDate->diff($toDate)->days > 366) {
            $fromDate = $toDate->modify('-366 days');
        }

        return ['from' => $fromDate->format('Y-m-d'), 'to' => $toDate->format('Y-m-d'), 'days' => $fromDate->diff($toDate)->days + 1];
    }

    /** @return array<string,mixed> */
    public function report(string $from, string $to): array
    {
        $lo = $from . ' 00:00:00';
        $hi = $to . ' 23:59:59';
        $accounts = $this->db->fetchAssociative(
            "SELECT COUNT(*) AS total,
                    SUM(is_admin = 1) AS admins,
                    SUM(is_admin = 0 AND (is_owner = 1 OR role = 'owner')) AS owners,
                    SUM(role = 'deleted') AS since_deactivated
               FROM users WHERE created_at BETWEEN ? AND ?",
            [$lo, $hi]
        ) ?: [];
        $byStatus = $this->db->fetchAllKeyValue('SELECT status, COUNT(*) FROM bookings WHERE created_at BETWEEN ? AND ? GROUP BY status ORDER BY 2 DESC', [$lo, $hi]);
        $totalBookings = array_sum(array_map('intval', $byStatus));
        $cancelled = (int)($byStatus['cancelled'] ?? 0) + (int)($byStatus['declined'] ?? 0);
        $value = $this->db->fetchOne("SELECT COALESCE(SUM(price), 0) FROM bookings WHERE created_at BETWEEN ? AND ? AND status IN ('confirmed','completed')", [$lo, $hi]);
        $daily = $this->db->fetchAllKeyValue('SELECT DATE(created_at) AS d, COUNT(*) FROM bookings WHERE created_at BETWEEN ? AND ? GROUP BY DATE(created_at) ORDER BY d', [$lo, $hi]);
        $days = max(1, (int)(new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days + 1);
        $facilities = $this->db->fetchAllAssociative(
            "SELECT f.id, f.name, f.location, f.operating_status, f.rating, f.reviews, f.hours,
                    (SELECT COUNT(*) FROM courts c WHERE c.facility_id = f.id) AS courts,
                    COUNT(b.id) AS bookings,
                    COALESCE(SUM(CASE WHEN b.status IN ('confirmed','completed') THEN b.price END), 0) AS confirmed_value,
                    SUM(b.status IN ('cancelled','declined')) AS cancelled
               FROM facilities f
               LEFT JOIN bookings b ON b.facility_id = f.id AND b.created_at BETWEEN ? AND ?
              GROUP BY f.id ORDER BY bookings DESC, f.name ASC",
            [$lo, $hi]
        );
        foreach ($facilities as &$f) {
            $f['confirmed_value'] = Money::toDecimal(Money::fromColumn($f['confirmed_value']));
            $f['cancelled'] = (int)$f['cancelled'];
            $courtsCount = max(1, (int)$f['courts']);
            $dailyHours = $this->parseDailyOperatingHours($f['hours'] ?? null);
            $totalCapacityHours = $courtsCount * $dailyHours * $days;
            $bookedHours = (int)$f['bookings'] * 2.0;
            $f['utilisation'] = $totalCapacityHours > 0 ? min(100.0, round(($bookedHours / $totalCapacityHours) * 100, 1)) : 0.0;
        }
        unset($f);

        return [
            'from' => $from,
            'to' => $to,
            'accounts' => [
                'total' => (int)($accounts['total'] ?? 0),
                'admins' => (int)($accounts['admins'] ?? 0),
                'owners' => (int)($accounts['owners'] ?? 0),
                'players' => max(0, (int)($accounts['total'] ?? 0) - (int)($accounts['admins'] ?? 0) - (int)($accounts['owners'] ?? 0)),
                'since_deactivated' => (int)($accounts['since_deactivated'] ?? 0),
            ],
            'bookings_by_status' => array_map('intval', $byStatus),
            'bookings_total' => $totalBookings,
            'booking_value_confirmed' => Money::toDecimal(Money::fromColumn($value)),
            'cancellation_rate' => $totalBookings > 0 ? round($cancelled / $totalBookings * 100, 1) : null,
            'daily_bookings' => $this->fillDays($from, $to, array_map('intval', $daily)),
            'facilities' => $facilities,
        ];
    }

    /** @param array<string,int> $counts @return list<array{date:string,count:int}> */
    private function fillDays(string $from, string $to, array $counts): array
    {
        $out = [];
        $d = new \DateTimeImmutable($from);
        $end = new \DateTimeImmutable($to);
        while ($d <= $end) {
            $key = $d->format('Y-m-d');
            $out[] = ['date' => $key, 'count' => $counts[$key] ?? 0];
            $d = $d->modify('+1 day');
        }

        return $out;
    }

    private function parseDailyOperatingHours(?string $hoursStr): float
    {
        if (empty($hoursStr)) {
            return 16.0;
        }
        if (stripos($hoursStr, '24') !== false) {
            return 24.0;
        }
        if (preg_match('/(\d{1,2})(?::\d{2})?\s*(am|pm)?\s*[-–—]\s*(\d{1,2})(?::\d{2})?\s*(am|pm)/i', $hoursStr, $m)) {
            $start = (int)$m[1];
            $startAmpm = strtolower($m[2] ?? 'am');
            $end = (int)$m[3];
            $endAmpm = strtolower($m[4]);
            if ($startAmpm === 'pm' && $start < 12) $start += 12;
            if ($startAmpm === 'am' && $start === 12) $start = 0;
            if ($endAmpm === 'pm' && $end < 12) $end += 12;
            if ($endAmpm === 'am' && $end === 12) $end = 0;
            $diff = $end - $start;
            if ($diff <= 0) $diff += 24;
            return (float)max(1, min(24, $diff));
        }
        return 16.0;
    }
}
