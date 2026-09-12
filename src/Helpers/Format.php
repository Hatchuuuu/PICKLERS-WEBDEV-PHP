<?php
declare(strict_types=1);

namespace Picklers\Helpers;

// ==============================================================================
// PICKLERS — Formatting Helpers
// ==============================================================================

class Format {
    public static function currency(float|int|string $amount): string {
        return '₱' . number_format((float)$amount, 2);
    }

    public static function rating(float|int|string $rating): string {
        return number_format((float)$rating, 1);
    }

    public static function relativeTime(string $datetime): string {
        $timestamp = strtotime($datetime);
        if (!$timestamp) return $datetime;
        
        $diff = time() - $timestamp;
        if ($diff < 60) return 'Just now';
        if ($diff < 3600) return floor($diff / 60) . 'm ago';
        if ($diff < 86400) return floor($diff / 3600) . 'h ago';
        return date('M j', $timestamp);
    }
}
