<?php
declare(strict_types=1);

namespace Picklers\Admin\Security;

/**
 * One mapping from the legacy `users` columns to Symfony roles and display labels.
 *
 * Policy (A05): an account is an administrator iff `is_admin = 1` and it is not
 * deactivated. The free-text `role` column is legacy display data and may
 * disagree (the live admin row reads role=player); it never grants admin access
 * on its own. Privileged administrators are recorded separately in
 * `admin_privileges` and are never inferred from legacy columns.
 */
final class RoleMapper
{
    public static function isDeactivated(array $row): bool
    {
        return ($row['role'] ?? '') === 'deleted';
    }

    public static function isAdmin(array $row): bool
    {
        return !self::isDeactivated($row) && (int)($row['is_admin'] ?? 0) === 1;
    }

    public static function isOwner(array $row): bool
    {
        return !self::isDeactivated($row)
            && ((int)($row['is_owner'] ?? 0) === 1 || ($row['role'] ?? '') === 'owner');
    }

    /** @return list<string> */
    public static function roles(array $row, bool $privileged): array
    {
        if (self::isDeactivated($row)) {
            return [];
        }
        $roles = ['ROLE_USER'];
        if (self::isAdmin($row)) {
            $roles[] = 'ROLE_ADMIN';
            if ($privileged) {
                $roles[] = 'ROLE_PRIVILEGED_ADMIN';
            }
        }
        $roles[] = self::isOwner($row) ? 'ROLE_OWNER' : 'ROLE_PLAYER';

        return $roles;
    }

    /**
     * Truthful labels for every capacity an account holds, e.g. ["Admin", "Owner"].
     *
     * @return list<string>
     */
    public static function labels(array $row, bool $privileged): array
    {
        if (self::isDeactivated($row)) {
            return ['Deactivated'];
        }
        $labels = [];
        if (self::isAdmin($row)) {
            $labels[] = $privileged ? 'Privileged admin' : 'Admin';
        }
        if (self::isOwner($row)) {
            $labels[] = 'Owner';
        }
        if ($labels === []) {
            $labels[] = 'Player';
        }

        return $labels;
    }

    /** Primary role key used for filtering: privileged|admin|owner|player|deleted. */
    public static function primary(array $row, bool $privileged): string
    {
        if (self::isDeactivated($row)) {
            return 'deleted';
        }
        if (self::isAdmin($row)) {
            return $privileged ? 'privileged' : 'admin';
        }

        return self::isOwner($row) ? 'owner' : 'player';
    }
}
