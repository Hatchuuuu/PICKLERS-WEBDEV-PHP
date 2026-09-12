<?php
declare(strict_types=1);

namespace Picklers\Repositories;

use Picklers\Core\Database;

class UserRepository {
    private Database $db;

    public function __construct(?Database $db = null) {
        $this->db = $db ?? Database::get();
    }

    public function findById(string $id): ?array {
        return $this->db->getUserById($id);
    }

    public function findByEmailOrPhone(string $identifier): ?array {
        return $this->db->getUserByEmailOrPhone($identifier);
    }

    public function create(array $data): array {
        return $this->db->createUser($data);
    }

    public function update(string $id, array $fields): array {
        return $this->db->updateUser($id, $fields);
    }

    public function all(): array {
        return $this->db->getAllUsers();
    }
}
