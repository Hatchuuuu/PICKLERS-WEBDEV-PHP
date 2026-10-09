<?php
declare(strict_types=1);

namespace Picklers\Repositories;

/**
 * Player, owner and admin accounts.
 */
final class UserRepository
{
    private readonly \Doctrine\DBAL\Connection $db;
    private readonly \Picklers\Persistence\SchemaInstaller $schema;

    public function __construct(
        ?\Doctrine\DBAL\Connection $db = null,
        ?\Picklers\Persistence\SchemaInstaller $schema = null,
    ) {
        $this->db = $db ?? \Picklers\Core\Database::get()->dbal();
        $this->schema = $schema ?? new \Picklers\Persistence\SchemaInstaller($this->db);
    }

    public function getUserById($id) {
        $stmt = $this->db->executeQuery("SELECT * FROM users WHERE id = ?", [$id]);
        return $stmt->fetchAssociative() ?: null;
    }

    public function getUserByEmailOrPhone($identifier) {
        $identifier = (string)$identifier;
        // Phones are stored as "+63 9xx xxx xxxx" (what the app's inputs
        // produce). The identifier's canonical form is matched as well, so any
        // typing of the same number finds the account.
        $phones = [$identifier];
        $canonicalPhone = \Picklers\Helpers\Format::phMobile($identifier);
        if ($canonicalPhone !== null && $canonicalPhone !== $identifier) {
            $phones[] = $canonicalPhone;
        }
        $phonePlaceholders = implode(',', array_fill(0, count($phones), '?'));
        $stmt = $this->db->executeQuery("SELECT * FROM users WHERE email = ? OR phone IN ($phonePlaceholders) LIMIT 1", array_merge([$identifier], $phones));
        return $stmt->fetchAssociative() ?: null;
    }

    public function createUser($data) {
        $id = $data['id'] ?? ('usr_' . bin2hex(random_bytes(6)));
        $name = $data['name'] ?? 'Pickler';
        $email = $data['email'] ?? null;
        $phone = $data['phone'] ?? null;
        $password = $data['password_hash'] ?? (isset($data['password']) ? password_hash($data['password'], PASSWORD_DEFAULT) : null);
        $role = $data['role'] ?? 'player';
        // New accounts are NOT trusted by default. Verification is granted by an
        // administrator (or requested by the user), never auto-issued at signup —
        // otherwise the verified badge carries no meaning at all.
        $verification = 'unverified';
        $avatar = $data['avatar_url'] ?? '';
        $level = 'Beginner 2.5';
        $gold = 0;
        $silver = 0;
        $bronze = 0;
        // Sign-up bonus is real spending power — never minted in production regardless
        // of SIGNUP_BONUS_CREDITS, mirroring the same fail-closed gate top_up uses.
        $env    = strtolower((string)($_ENV['APP_ENV'] ?? 'production'));
        $isProd = in_array($env, ['production', 'prod', 'live'], true);
        $wallet = $isProd ? 0.0 : max(0.0, min(500.0, (float)($_ENV['SIGNUP_BONUS_CREDITS'] ?? 0)));
        $isAdmin = ($role === 'admin') ? 1 : 0;
        $isDev = ($role === 'dev' || $role === 'admin') ? 1 : 0;
        $isOwner = ($role === 'owner') ? 1 : 0;

        $this->db->executeStatement("INSERT INTO users (id, name, email, phone, password_hash, role, verification_status, avatar_url, level, gold, silver, bronze, wallet_balance, is_admin, is_dev, is_owner) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)", [$id, $name, $email, $phone, $password, $role, $verification, $avatar, $level, $gold, $silver, $bronze, $wallet, $isAdmin, $isDev, $isOwner]);

        return $this->getUserById($id);
    }

    public function updateUser($id, $fields) {
        $allowedColumns = [
            'name', 'email', 'phone', 'password_hash', 'role', 'verification_status',
            'avatar_url', 'level', 'gold', 'silver', 'bronze', 'wallet_balance',
            'is_admin', 'is_dev', 'is_owner', 'password_changed_at'
        ];
        $cleanFields = [];
        foreach ($fields as $k => $v) {
            if (in_array($k, $allowedColumns, true)) {
                $cleanFields[$k] = $v;
            }
        }
        // Added by a migration; ignored until that migration has run.
        if (array_key_exists('password_changed_at', $cleanFields) && !$this->schema->hasColumn('users', 'password_changed_at')) {
            unset($cleanFields['password_changed_at']);
        }
        if (empty($cleanFields)) {
            return $this->getUserById($id);
        }

        $set = [];
        $vals = [];
        foreach ($cleanFields as $k => $v) {
            $set[] = "`$k` = ?";
            $vals[] = $v;
        }
        $vals[] = $id;
        $sql = "UPDATE users SET " . implode(", ", $set) . " WHERE id = ?";
        $this->db->executeStatement($sql, $vals);


        return $this->getUserById($id);
    }

    public function deleteUser($id) {
        $this->db->executeStatement("DELETE FROM users WHERE id = ?", [$id]);


        return true;
    }

    public function getAllUsers() {
        $stmt = $this->db->executeQuery("SELECT id, name, email, phone, role, verification_status, avatar_url, level, wallet_balance, is_admin, is_dev, is_owner, created_at FROM users ORDER BY created_at DESC");
        return $stmt->fetchAllAssociative();
    }
}
