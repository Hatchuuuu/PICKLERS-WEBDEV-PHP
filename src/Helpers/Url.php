<?php
declare(strict_types=1);

namespace Picklers\Helpers;

// ==============================================================================
// PICKLERS — Dynamic URL & Asset Resolver Helper
// Works identically across subdirectories (XAMPP), virtual hosts, and domains
// ==============================================================================

class Url {
    private static ?string $baseUrl = null;

    /**
     * Dynamically detect base URL prefix (e.g. '/PICKLERS WEBDEV PROJECT' or '')
     */
    public static function base(): string {
        if (self::$baseUrl !== null) {
            return self::$baseUrl;
        }

        $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
        $scriptDir = str_replace('\\', '/', $scriptDir);

        // Strip trailing /public if accessed through public/ directly
        $scriptDir = preg_replace('#/public$#', '', $scriptDir);

        if ($scriptDir === '' || $scriptDir === '.' || $scriptDir === '/' || $scriptDir === '\\') {
            self::$baseUrl = '';
        } else {
            self::$baseUrl = '/' . trim($scriptDir, '/');
        }
        return self::$baseUrl;
    }

    /**
     * Generate an absolute path URL for a route
     */
    public static function to(string $path = ''): string {
        $base = self::base();
        $cleanPath = '/' . ltrim($path, '/');

        // Prevent duplicate directory prepending if path already contains $base
        if (!empty($base) && (str_starts_with($cleanPath, $base . '/') || $cleanPath === $base)) {
            return $cleanPath;
        }
        return $base . $cleanPath;
    }

    /**
     * Generate an absolute path URL for a public asset with intelligent directory resolution and versioning
     */
    public static function asset(string $assetPath, bool $versioned = false): string {
        $base = self::base();
        $clean = ltrim($assetPath, '/');

        if (str_starts_with($clean, 'assets/')) {
            $relativePath = $clean;
        } elseif (defined('PUBLIC_PATH') && file_exists(PUBLIC_PATH . '/' . $clean)) {
            $relativePath = $clean;
        } elseif (defined('PUBLIC_PATH') && file_exists(PUBLIC_PATH . '/assets/' . $clean)) {
            $relativePath = 'assets/' . $clean;
        } elseif (defined('PUBLIC_PATH') && file_exists(PUBLIC_PATH . '/assets/css/' . $clean)) {
            $relativePath = 'assets/css/' . $clean;
        } elseif (defined('PUBLIC_PATH') && file_exists(PUBLIC_PATH . '/assets/images/' . $clean)) {
            $relativePath = 'assets/images/' . $clean;
        } elseif (defined('PUBLIC_PATH') && file_exists(PUBLIC_PATH . '/assets/brand-logos/' . $clean)) {
            $relativePath = 'assets/brand-logos/' . $clean;
        } else {
            $relativePath = 'assets/' . $clean;
        }

        $url = $base . '/' . $relativePath;

        if ($versioned) {
            $fullPath = defined('PUBLIC_PATH') ? PUBLIC_PATH . '/' . $relativePath : null;
            $token = ($fullPath && file_exists($fullPath)) ? (string)filemtime($fullPath) : (string)time();
            $url .= '?v=' . urlencode($token);
        }

        return $url;
    }
}
