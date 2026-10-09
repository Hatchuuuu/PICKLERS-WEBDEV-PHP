<?php
declare(strict_types=1);

namespace Picklers\Services;

use Picklers\Repositories\UserRepository;

class AuthService {
    /** Applies to sign-up, password changes and admin resets alike. */
    public const PASSWORD_MIN_LENGTH = \Picklers\Domain\PasswordPolicy::MIN_LENGTH;

    public function __construct(private readonly UserRepository $userRepo) {
    }

    /**
     * The single password rule. Sign-up enforced 8 characters + a number
     * while password changes and admin resets accepted 6 of anything, so a
     * strong password could be swapped for a weak one right after sign-up.
     *
     * @return string|null An error message, or null when the password is acceptable.
     */
    public static function passwordPolicyError(string $password): ?string {
        return \Picklers\Domain\PasswordPolicy::error($password);
    }

    public function getUserById(string $id): ?array {
        return $this->userRepo->getUserById($id);
    }

    public function getUserByEmailOrPhone(string $identifier): ?array {
        return $this->userRepo->getUserByEmailOrPhone($identifier);
    }

    public function createUser(array $data): array {
        return $this->userRepo->createUser($data);
    }

    public function updateUser(string $id, array $fields): array {
        return $this->userRepo->updateUser($id, $fields);
    }

    public function getAllUsers(): array {
        return $this->userRepo->getAllUsers();
    }

    public function deleteUser(string $id): bool {
        return $this->userRepo->deleteUser($id);
    }

    public function authenticate(string $identifier, string $password): ?array {
        $user = $this->getUserByEmailOrPhone($identifier);
        $passwordHash = $user['password_hash'] ?? '$2y$10$invalid.hash.to.maintain.timing.consistency';

        // Always call password_verify() FIRST to maintain timing consistency
        $verified = password_verify($password, $passwordHash);

        // Then check both user existence AND verification result
        if ($user && $verified && ($user['role'] ?? '') !== 'deleted') {
            // Existing hashes keep working; an outdated algorithm/cost is
            // transparently upgraded while the plaintext is in hand.
            if (password_needs_rehash($passwordHash, PASSWORD_DEFAULT)) {
                try {
                    $this->userRepo->updateUser((string)$user['id'], ['password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
                } catch (\Throwable $e) {
                    error_log('[PICKLERS Auth] password rehash skipped: ' . $e->getMessage());
                }
            }
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
        // password_changed_at signs out the account's OTHER sessions; the caller
        // re-stamps the current one (AuthMiddleware::markAuthenticatedNow()).
        $updatedUser = $this->userRepo->updateUser($userId, [
            'password_hash' => $newHash,
            'password_changed_at' => date('Y-m-d H:i:s'),
        ]);

        return ['success' => true, 'user' => $updatedUser];
    }
}
