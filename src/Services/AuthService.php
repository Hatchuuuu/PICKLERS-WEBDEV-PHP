<?php
declare(strict_types=1);

namespace Picklers\Services;

use Picklers\Repositories\UserRepository;

class AuthService {
    private UserRepository $userRepo;

    public function __construct(?UserRepository $userRepo = null) {
        $this->userRepo = $userRepo ?? new UserRepository();
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

    public function authenticate(string $identifier, string $password): ?array {
        $user = $this->getUserByEmailOrPhone($identifier);
        $passwordHash = $user['password_hash'] ?? '$2y$10$invalid.hash.to.maintain.timing.consistency';

        // Always call password_verify() FIRST to maintain timing consistency
        $verified = password_verify($password, $passwordHash);

        // Then check both user existence AND verification result
        if ($user && $verified) {
            return $user;
        }
        return null;
    }

    public function changePassword(string $userId, string $currentPassword, string $newPassword): array {
        $user = $this->getUserById($userId);
        if (!$user) {
            return ['success' => false, 'error' => 'User account not found.'];
        }

        $passwordHash = $user['password_hash'] ?? '';
        if ($currentPassword !== '' && $passwordHash !== '' && !password_verify($currentPassword, $passwordHash)) {
            return ['success' => false, 'error' => 'Current password is incorrect. Please try again.'];
        }

        if (strlen($newPassword) < 6) {
            return ['success' => false, 'error' => 'New password must be at least 6 characters long.'];
        }

        $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
        $updatedUser = $this->userRepo->update($userId, ['password_hash' => $newHash]);

        return ['success' => true, 'user' => $updatedUser];
    }
}
