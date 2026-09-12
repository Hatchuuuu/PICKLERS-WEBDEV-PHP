<?php
declare(strict_types=1);

namespace Picklers\Middleware;

use Picklers\Core\Response;
use Picklers\Core\Database;

// ==============================================================================
// PICKLERS — Authentication & Role Middleware
// ==============================================================================

class AuthMiddleware {
    private static ?array $memoizedUser = null;

    private static function initSession(): void {
        if (session_status() === PHP_SESSION_NONE) {
            if (!session_start()) {
                error_log('[PICKLERS Auth] Failed to initialize PHP session.');
            }
        }
    }

    public static function clearMemoizedUser(): void {
        self::$memoizedUser = null;
    }

    public static function login(string $userId): void {
        self::initSession();

        // Regenerate session ID to prevent session fixation attacks.
        // We do NOT delete the old session file here (false) to avoid
        // a race condition where the browser reconnects with the old
        // cookie ID before the new Set-Cookie header is processed.
        // The old session file is harmless and will expire naturally.
        if (!headers_sent()) {
            @session_regenerate_id(false);
        }

        $_SESSION['picklers_user_id'] = $userId;
        $_SESSION['picklers_last_activity'] = time();
        self::clearMemoizedUser();

        // Explicitly flush & close session so the new session data is
        // persisted to disk BEFORE the JS redirect fires on the client.
        // Without this, a fast AJAX redirect can land on app.php before
        // PHP has written the session file, causing requireAuth() to
        // find an empty session and redirect back to auth.
        session_write_close();
    }

    public static function logout(): void {
        self::initSession();
        unset($_SESSION['picklers_user_id']);
        if (isset($_SESSION['user'])) {
            unset($_SESSION['user']);
        }
        if (!headers_sent()) {
            @session_regenerate_id(true);
        }
        self::clearMemoizedUser();
    }

    public static function user(): ?array {
        if (self::$memoizedUser !== null) {
            return self::$memoizedUser;
        }

        self::initSession();
        $userId = $_SESSION['picklers_user_id'] ?? null;
        if (!$userId) {
            return null;
        }

        // Enforce 2-hour idle session timeout (7200 seconds)
        $lastActivity = (int)($_SESSION['picklers_last_activity'] ?? 0);
        if ($lastActivity > 0 && (time() - $lastActivity) > 7200) {
            self::logout();
            return null;
        }
        $_SESSION['picklers_last_activity'] = time();

        $db = Database::get();
        self::$memoizedUser = $db->getUserById($userId);
        return self::$memoizedUser;
    }

    public static function requireAuth(): array {
        $user = self::user();
        if (!$user) {
            Response::redirect('auth.php');
            exit;
        }
        return $user;
    }

    public static function requireAdmin(): array {
        $user = self::requireAuth();
        $isAdmin = (int)($user['is_admin'] ?? 0);
        $role    = (string)($user['role'] ?? '');
        if ($isAdmin !== 1 && $role !== 'admin') {
            Response::redirect('app.php?error=unauthorized');
            exit;
        }
        return $user;
    }

    public static function requireOwner(): array {
        $user = self::requireAuth();
        $isOwner = (int)($user['is_owner'] ?? 0);
        $isAdmin = (int)($user['is_admin'] ?? 0);
        $role    = (string)($user['role'] ?? '');

        if ($isOwner !== 1 && $isAdmin !== 1 && $role !== 'owner') {
            Response::redirect('owner-application.php?notice=verification_required');
            exit;
        }
        return $user;
    }
}
