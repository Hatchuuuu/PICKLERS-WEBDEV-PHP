<?php
declare(strict_types=1);

namespace Picklers\Domain;

/**
 * Pure date, time and court-name rules shared by every booking and Open Play path.
 */
final class Schedule
{
    /**
     * Strictly normalizes court names to "Court 1", "Court 2", "Court 3", etc.
     * Strips any legacy sub-titles or extra appended names (e.g. "Court 1 · Main Arena" -> "Court 1").
     */
    public static function normalizeCourtName(?string $name): string {
        if ($name === null || trim($name) === '') return 'Court 1';
        $trimmed = trim($name);
        $clean = trim(preg_replace('/\s*[\-·•].*$/', '', $trimmed));
        if (preg_match('/\b\d+\b/', $clean, $matches)) {
            return "Court " . (int)$matches[0];
        }
        if (preg_match('/court\s*([a-z])\b/i', $clean, $matches)) {
            $num = ord(strtoupper($matches[1])) - 64;
            if ($num >= 1 && $num <= 26) {
                return "Court " . $num;
            }
        }
        if (preg_match('/^[a-z]$/i', $clean, $matches)) {
            $num = ord(strtoupper($matches[0])) - 64;
            if ($num >= 1 && $num <= 26) {
                return "Court " . $num;
            }
        }
        return 'Court 1';
    }

    /**
     * The one and only vocabulary a court's status may hold. Three different
     * call sites once wrote three different dialects into this same column
     * (uppercase 'AVAILABLE'/'UNAVAILABLE' from the owner toggle, lowercase
     * 'available'/'occupied'/'maintenance' from Admin/API, and a player-side
     * reader that only ever recognised lowercase 'available') — so an owner
     * re-enabling a court could make it permanently unbookable to players.
     * Normalizing here, at the single choke point every write passes
     * through, closes that regardless of what casing any caller uses.
     */
    public const COURT_STATUSES = ['available', 'occupied', 'maintenance', 'unavailable'];

    public static function normalizeCourtStatus(string $status): string {
        $s = strtolower(trim($status));
        return in_array($s, self::COURT_STATUSES, true) ? $s : 'unavailable';
    }

    /**
     * Whether a booking's play window has already elapsed. Nothing in this
     * codebase ever writes status='completed' on a booking — updateBookingStatus()
     * is only ever called with 'confirmed' or 'cancelled' — so the player's own
     * "Completed" Bookings tab (views/partials/app/_tab-bookings.php, filtering
     * on that exact string) has always been empty, and a booking from weeks ago
     * sits in "Upcoming" forever. This computes it the same way isMatchExpired()
     * does for Open Play sessions, so a booking moves to Completed the moment
     * its window ends — including immediately, for one flagged ended_early.
     */
    public static function isBookingPast(array $booking): bool {
        return \Picklers\Domain\BookingRules::isBookingPast($booking);
    }

    public static function getMatchTargetDate(array $match): string {
        return \Picklers\Domain\BookingRules::matchTargetDate($match);
    }

    public static function getMatchDisplayDate(array $match): string {
        $targetDate = self::getMatchTargetDate($match);
        if (!empty($targetDate) && strtotime($targetDate) !== false) {
            return date('F j, Y', strtotime($targetDate));
        }

        $dateStr = trim((string)($match['date'] ?? ''));
        if (!empty($dateStr) && strtotime($dateStr) !== false) {
            return date('F j, Y', strtotime($dateStr));
        }

        return $dateStr !== '' ? $dateStr : date('F j, Y');
    }

    public static function isMatchExpired(array $match): bool {
        $dateStr = trim((string)($match['date'] ?? ''));
        $timeStr = trim((string)($match['time'] ?? ''));

        if ($dateStr === '' || $timeStr === '') {
            return false;
        }

        $isEveryday = strcasecmp($dateStr, 'everyday') === 0 || strcasecmp($dateStr, 'daily') === 0
            || str_contains(strtolower($dateStr), 'everyday') || str_contains(strtolower($dateStr), 'daily');

        if ($isEveryday) {
            return false;
        }

        $ts = strtotime($dateStr);
        if ($ts === false) {
            return false;
        }
        $sessionDateStr = date('Y-m-d', $ts);

        $startTimeStr = '';
        $endTimeStr = '';
        if (str_contains($timeStr, '-')) {
            $parts = explode('-', $timeStr);
            $startTimeStr = trim($parts[0]);
            $endTimeStr = trim(end($parts));
        } elseif (str_contains($timeStr, '–')) {
            $parts = explode('–', $timeStr);
            $startTimeStr = trim($parts[0]);
            $endTimeStr = trim(end($parts));
        } elseif (str_contains($timeStr, 'to')) {
            $parts = explode('to', $timeStr);
            $startTimeStr = trim($parts[0]);
            $endTimeStr = trim(end($parts));
        } else {
            $endTimeStr = $timeStr;
        }

        $endTs = $endTimeStr !== '' ? strtotime($sessionDateStr . ' ' . $endTimeStr) : false;
        if ($endTs === false) {
            return $sessionDateStr < date('Y-m-d');
        }

        // A session running past midnight ("8:00 PM – 12:00 AM") ends on the
        // following day. Reading its end as the session day's own 12:00 AM
        // marked it expired the moment it was published, and a session from
        // late last night as over while it was still being played.
        $startTs = $startTimeStr !== '' ? strtotime($sessionDateStr . ' ' . $startTimeStr) : false;
        if ($startTs !== false && $endTs <= $startTs) {
            $endTs = strtotime('+1 day', $endTs);
        }

        return time() >= $endTs;
    }

    /**
     * Parse a display time range into [startMinute, endMinute] from midnight.
     * Accepts -, – and — as separators. Ranges crossing midnight are unwrapped.
     */
    public static function parseTimeRange(string $range): ?array {
        return \Picklers\Domain\BookingRules::parseTimeRange($range);
    }

    /** Half-open overlap test: [aStart,aEnd) intersects [bStart,bEnd). */
    public static function rangesOverlap(array $a, array $b): bool {
        return $a[0] < $b[1] && $b[0] < $a[1];
    }

    /**
     * Resolve a display date string ("Today", "Tomorrow", "Thu Sep 10 2026",
     * "Thu, Sep 10, 2026", "Friday", "Last Week", ...) to a canonical
     * 'Y-m-d'. Pure resolution — no future/past judgment, so it is safe to
     * use both for validating a NEW booking (validateBookingSlot() layers a
     * future-only check on top) and for backfilling historical rows, which
     * are — by definition — always in the past by the time a migration runs.
     */
    public static function resolveDisplayDate(string $date): ?string {
        return \Picklers\Domain\BookingRules::resolveDisplayDate($date);
    }

    public static function minutesToLabel(int $m): string {
        $h = intdiv($m, 60) % 24;
        $i = $m % 60;
        $suffix = $h >= 12 ? 'PM' : 'AM';
        $h12 = $h % 12;
        if ($h12 === 0) $h12 = 12;
        return sprintf('%d:%02d %s', $h12, $i, $suffix);
    }
}
