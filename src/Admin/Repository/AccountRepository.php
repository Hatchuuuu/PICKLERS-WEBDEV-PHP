<?php
declare(strict_types=1);

namespace Picklers\Admin\Repository;

use Doctrine\DBAL\Connection;
use Picklers\Admin\Service\ListQuery;
use Picklers\Admin\Service\Page;

/** Read/write access to `users` and `admin_privileges` for the admin console. */
final class AccountRepository
{
    /** Columns ever returned to admin code — never password_hash. */
    private const COLUMNS = 'u.id, u.name, u.email, u.phone, u.role, u.verification_status, u.avatar_url, u.level,
        u.wallet_balance, u.is_admin, u.is_owner, u.created_at, u.password_changed_at, (ap.user_id IS NOT NULL) AS is_privileged';

    public const ROLE_FILTERS = ['player', 'owner', 'admin', 'privileged', 'deleted'];
    public const VERIFICATION_FILTERS = ['verified', 'unverified', 'pending'];
    public const SORTS = ['created_at', 'name', 'wallet_balance'];

    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array<string,mixed>|null */
    public function find(string $id, bool $forUpdate = false): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT ' . self::COLUMNS . ' FROM users u LEFT JOIN admin_privileges ap ON ap.user_id = u.id WHERE u.id = ?'
            . ($forUpdate ? ' FOR UPDATE' : ''),
            [$id]
        );

        return $row === false ? null : $this->normalize($row);
    }

    public function isPrivileged(string $id): bool
    {
        return (bool)$this->db->fetchOne('SELECT 1 FROM admin_privileges WHERE user_id = ?', [$id]);
    }

    public function countActiveAdmins(): int
    {
        return (int)$this->db->fetchOne("SELECT COUNT(*) FROM users WHERE is_admin = 1 AND role <> 'deleted'");
    }

    public function countActivePrivileged(): int
    {
        return (int)$this->db->fetchOne(
            "SELECT COUNT(*) FROM admin_privileges ap JOIN users u ON u.id = ap.user_id WHERE u.is_admin = 1 AND u.role <> 'deleted'"
        );
    }

    /** @return Page<array<string,mixed>> */
    public function search(ListQuery $query): Page
    {
        [$where, $params] = $this->where($query);
        $total = (int)$this->db->fetchOne("SELECT COUNT(*) FROM users u LEFT JOIN admin_privileges ap ON ap.user_id = u.id WHERE {$where}", $params);
        if ($total > 0 && $query->offset() >= $total) {
            $query = $query->withPage((int)ceil($total / $query->perPage));
        }
        $order = match ($query->sort) {
            'name' => 'u.name',
            'wallet_balance' => 'u.wallet_balance',
            default => 'u.created_at',
        };
        $dir = $query->dir === 'asc' ? 'ASC' : 'DESC';
        $rows = $this->db->fetchAllAssociative(
            'SELECT ' . self::COLUMNS . " FROM users u LEFT JOIN admin_privileges ap ON ap.user_id = u.id
             WHERE {$where} ORDER BY {$order} {$dir}, u.id ASC LIMIT {$query->perPage} OFFSET {$query->offset()}",
            $params
        );

        return new Page(array_map($this->normalize(...), $rows), $total, $query);
    }

    /** @return array{0:string,1:list<mixed>} */
    private function where(ListQuery $query): array
    {
        $sql = ['1=1'];
        $params = [];
        if ($query->q !== '') {
            $sql[] = '(u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ? OR u.id LIKE ?)';
            array_push($params, ...array_fill(0, 4, $query->likePattern()));
        }
        $sql[] = match ($query->filter('role')) {
            'deleted' => "u.role = 'deleted'",
            'privileged' => "u.role <> 'deleted' AND u.is_admin = 1 AND ap.user_id IS NOT NULL",
            'admin' => "u.role <> 'deleted' AND u.is_admin = 1",
            'owner' => "u.role <> 'deleted' AND (u.is_owner = 1 OR u.role = 'owner')",
            'player' => "u.role <> 'deleted' AND u.is_admin = 0 AND u.is_owner = 0 AND u.role <> 'owner'",
            default => '1=1',
        };
        if (($v = $query->filter('verification')) !== null) {
            $sql[] = 'u.verification_status = ?';
            $params[] = $v;
        }

        return [implode(' AND ', $sql), $params];
    }

    /** @return array<string,int> */
    public function roleCounts(): array
    {
        $row = $this->db->fetchAssociative(
            "SELECT
                SUM(u.role <> 'deleted') AS active,
                SUM(u.role = 'deleted') AS deactivated,
                SUM(u.role <> 'deleted' AND u.is_admin = 1) AS admins,
                SUM(u.role <> 'deleted' AND (u.is_owner = 1 OR u.role = 'owner')) AS owners,
                SUM(u.role <> 'deleted' AND u.is_admin = 0 AND u.is_owner = 0 AND u.role <> 'owner') AS players,
                SUM(u.role <> 'deleted' AND u.verification_status = 'verified') AS verified,
                COUNT(*) AS total
             FROM users u"
        ) ?: [];

        return array_map('intval', $row);
    }

    /** @param array<string,mixed> $fields */
    public function update(string $id, array $fields): void
    {
        $this->db->update('users', $fields, ['id' => $id]);
    }

    public function grantPrivilege(string $userId, ?string $grantedBy, ?string $note): void
    {
        $this->db->executeStatement(
            'INSERT INTO admin_privileges (user_id, level, granted_by, granted_at, note) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE level = VALUES(level)',
            [$userId, 'privileged', $grantedBy, date('Y-m-d H:i:s'), $note]
        );
    }

    public function revokePrivilege(string $userId): void
    {
        $this->db->delete('admin_privileges', ['user_id' => $userId]);
    }

    /** @return array<string,mixed> */
    private function normalize(array $row): array
    {
        $row['is_admin'] = (int)$row['is_admin'];
        $row['is_owner'] = (int)$row['is_owner'];
        $row['is_privileged'] = (bool)$row['is_privileged'];
        $row['wallet_balance'] = (string)($row['wallet_balance'] ?? '0.00');

        return $row;
    }
}
