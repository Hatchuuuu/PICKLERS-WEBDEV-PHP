<?php
declare(strict_types=1);

namespace Picklers\Admin\Http;

/**
 * Which requests belong to the admin console: every path under /admin (and
 * /admin.php) — including unknown ones, which get the admin 404 — plus /api and
 * /api.php calls of an admin action (`admin_*`) or the old `switch_user`
 * impersonation alias, so those entry points run the admin firewall, CSRF,
 * capability checks and audit trail.
 */
final class AdminArea
{
    public const API_ALIAS_ACTIONS = ['switch_user'];

    public static function contains(string $path, ?string $action): bool
    {
        if ($path === '/admin' || $path === '/admin.php' || str_starts_with($path, '/admin/')) {
            return true;
        }
        if ($path === '/api' || $path === '/api.php') {
            return $action !== null && (str_starts_with($action, 'admin_') || in_array($action, self::API_ALIAS_ACTIONS, true));
        }

        return false;
    }
}
