<?php
declare(strict_types=1);

namespace Picklers\Helpers;

// ==============================================================================
// PICKLERS — Security Helpers
// ==============================================================================

class Security {
    public static function escapeHtml(?string $str): string {
        if ($str === null) return '';
        return htmlspecialchars((string)$str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function hashPassword(string $password): string {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    public static function verifyPassword(string $password, string $hash): bool {
        // Always call password_verify() for timing consistency
        $dummyHash = '$2y$10$invalid.hash.to.maintain.timing.consistency';
        $effectiveHash = !empty($hash) ? $hash : $dummyHash;

        // Always call password_verify() FIRST to maintain timing consistency
        $verified = password_verify($password, $effectiveHash);

        // Then check both hash validity AND verification result
        return !empty($hash) && $verified;
    }

    public static function generateId(string $prefix = 'id_'): string {
        return $prefix . bin2hex(random_bytes(4));
    }
}
