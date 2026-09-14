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

    /**
     * Canonical "+63 9xx xxx xxxx" form of a Philippine mobile number — the
     * format the app's own phone inputs produce — or null when the input is
     * not one. Sign-in and uniqueness checks compare this form, so
     * "09171234567" and "+63 917 123 4567" are recognised as the same number.
     */
    public static function phMobile(string $input): ?string {
        $input = trim($input);
        if ($input === '' || !preg_match('/^[0-9+()\-\s]+$/', $input)) {
            return null;
        }
        $digits = (string)preg_replace('/\D/', '', $input);
        if (strlen($digits) === 12 && str_starts_with($digits, '639')) {
            $digits = substr($digits, 2);
        } elseif (strlen($digits) === 11 && str_starts_with($digits, '09')) {
            $digits = substr($digits, 1);
        }
        if (strlen($digits) !== 10 || $digits[0] !== '9') {
            return null;
        }
        return '+63 ' . substr($digits, 0, 3) . ' ' . substr($digits, 3, 3) . ' ' . substr($digits, 6);
    }
}
