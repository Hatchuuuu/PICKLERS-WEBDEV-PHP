<?php
declare(strict_types=1);

namespace Picklers\Models;

use Picklers\Services\AuthService;

class User {
    private AuthService $authService;

    public function __construct(?AuthService $authService = null) {
        $this->authService = $authService ?? new AuthService();
    }

    public function find(string $id): ?array {
        return $this->authService->getUserById($id);
    }

    public function findByIdentifier(string $identifier): ?array {
        return $this->authService->getUserByEmailOrPhone($identifier);
    }

    public function create(array $data): array {
        return $this->authService->createUser($data);
    }

    public function update(string $id, array $fields): array {
        return $this->authService->updateUser($id, $fields);
    }

    public function all(): array {
        return $this->authService->getAllUsers();
    }

    public function authenticate(string $identifier, string $password): ?array {
        return $this->authService->authenticate($identifier, $password);
    }
}
