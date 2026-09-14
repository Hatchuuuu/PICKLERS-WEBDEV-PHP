<?php
declare(strict_types=1);

namespace Picklers\Services;

use Picklers\Repositories\UserRepository;

class AuthService {
    /** Applies to sign-up, password changes and admin resets alike. */
    public const PASSWORD_MIN_LENGTH = 8;

    private UserRepository $userRepo;

    public function __construct(?UserRepository $userRepo = null) {
        $this->userRepo = $userRepo ?? new UserRepository();
    }

    /**
     * The single password rule. Sign-up enforced 8 characters + a number
     * while password changes and admin resets accepted 6 of anything, so a
     * strong password could be swapped for a weak one right after sign-up.
     *
     * @return string|null An error message, or null when the password is acceptable.
     */
    public static function passwordPolicyError(string $password): ?string {
        if (strlen($password) < self::PASSWORD_MIN_LENGTH) {
            return 'Password must be at least ' . self::PASSWORD_MIN_LENGTH . ' characters long.';
        }
        if (!preg_match('/[0-9]/', $password)) {
            return 'Password must contain at least one number.';
        }
        return null;
    }

    public function getUserById(string $id): ?array {
        return $this->userRepo->findById($id);
    }

    public function getUserByEmailOrPhone(string $identifier): ?array {
        return $this->userRepo->findByEmailOrPhone($identifier);
    }

    public function createUser(array $data): array {
        return $this->userRepo->create($data);
    }

    public function updateUser(string $id, array $fields): array {
        return $this->userRepo->update($id, $fields);
    }

    public function getAllUsers(): array {
        return $this->userRepo->all();
    }

    public function deleteUser(string $id): bool {
        return $this->userRepo->delete($id);
    }

    public function authenticate(string $identifier, string $password): ?array {
        $user = $this->getUserByEmailOrPhone($identifier);
        $passwordHash = $user['password_hash'] ?? '$2y$10$invalid.hash.to.maintain.timing.consistency';

        // Always call password_verify() FIRST to maintain timing consistency
        $verified = password_verify($password, $passwordHash);

        // Then check both user existence AND verification result
        if ($user && $verified && ($user['role'] ?? '') !== 'deleted') {
            return $user;
        }
        return null;
    }

    public function changePassword(string $userId, string $currentPassword, string $newPassword): array {
        $user = $this->getUserById($userId);
        if (!$user) {
            return ['success' => false, 'error' => 'User account not found.'];
        }

        // The current password was only checked when one was supplied, so an
        // empty field skipped verification entirely: anyone holding a live
        // session (a shared or unattended device) could lock the owner out.
        $passwordHash = (string)($user['password_hash'] ?? '');
        if ($passwordHash !== '') {
            if ($currentPassword === '') {
                return ['success' => false, 'error' => 'Please enter your current password.'];
            }
            if (!password_verify($currentPassword, $passwordHash)) {
                return ['success' => false, 'error' => 'Current password is incorrect. Please try again.'];
            }
        }

        $policyError = self::passwordPolicyError($newPassword);
        if ($policyError !== null) {
            return ['success' => false, 'error' => $policyError];
        }

        $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
        $updatedUser = $this->userRepo->update($userId, ['password_hash' => $newHash]);

        return ['success' => true, 'user' => $updatedUser];
    }
}
