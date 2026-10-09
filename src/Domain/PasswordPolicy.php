<?php
declare(strict_types=1);

namespace Picklers\Domain;

/** The single password rule: sign-up, password changes and admin resets alike. */
final class PasswordPolicy
{
    public const MIN_LENGTH = 8;

    /** An error message, or null when the password is acceptable. */
    public static function error(string $password): ?string
    {
        if (strlen($password) < self::MIN_LENGTH) {
            return 'Password must be at least ' . self::MIN_LENGTH . ' characters long.';
        }
        if (!preg_match('/[0-9]/', $password)) {
            return 'Password must contain at least one number.';
        }

        return null;
    }
}
