<?php
declare(strict_types=1);

namespace Picklers\Admin\Repository;

use Doctrine\DBAL\Connection;
use Picklers\Admin\Service\ListQuery;
use Picklers\Admin\Service\Page;

/** Read-only queries over the append-only audit trail. There is no write/delete method. */
final class AuditRepository
{
    public const OUTCOMES = ['success', 'failure', 'denied'];
    public const TARGET_TYPES = ['user', 'booking', 'application', 'facility', 'court', 'promo', 'wallet', 'moderation_case', 'notification', 'document', 'export'];
    public const SORTS = ['occurred_at'];

    public function __construct(private readonly Connection $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function historyFor(string $targetType, string $targetId, int $limit = 25): array
    {
        $limit = max(1, min(100, $limit));

        return array_map($this->decode(...), $this->db->fetchAllAssociative(
            "SELECT * FROM audit_events WHERE target_type = ? AND target_id = ? ORDER BY id DESC LIMIT {$limit}",
            [$targetType, $targetId]
        ));
    }

    /** @return Page<array<string,mixed>> */
    public function search(ListQuery $query): Page
    {
        $where = ['1=1'];
        $params = [];
        if ($query->q !== '') {
            $where[] = '(action LIKE ? OR target_id LIKE ? OR reason LIKE ? OR actor_name LIKE ? OR correlation_id = ?)';
            array_push($params, $query->likePattern(), $query->likePattern(), $query->likePattern(), $query->likePattern(), $query->q);
        }
        foreach (['outcome' => 'outcome', 'target_type' => 'target_type', 'actor' => 'actor_user_id'] as $filter => $column) {
            if (($v = $query->filter($filter)) !== null) {
                $where[] = "{$column} = ?";
                $params[] = $v;
            }
        }
        if (($v = $query->filter('action')) !== null) {
            $where[] = 'action LIKE ?';
            $params[] = addcslashes($v, '%_\\') . '%';
        }
        if (($from = $query->filter('from')) !== null) {
            $where[] = 'occurred_at >= ?';
            $params[] = $from . ' 00:00:00';
        }
        if (($to = $query->filter('to')) !== null) {
            $where[] = 'occurred_at <= ?';
            $params[] = $to . ' 23:59:59.999999';
        }
        $sql = implode(' AND ', $where);
        $total = (int)$this->db->fetchOne("SELECT COUNT(*) FROM audit_events WHERE {$sql}", $params);
        if ($total > 0 && $query->offset() >= $total) {
            $query = $query->withPage((int)ceil($total / $query->perPage));
        }
        $dir = $query->dir === 'asc' ? 'ASC' : 'DESC';
        $rows = $this->db->fetchAllAssociative(
            "SELECT * FROM audit_events WHERE {$sql} ORDER BY occurred_at {$dir}, id {$dir} LIMIT {$query->perPage} OFFSET {$query->offset()}",
            $params
        );

        return new Page(array_map($this->decode(...), $rows), $total, $query);
    }

    /** @return list<string> distinct action prefixes ("booking.cancel" → "booking") for the filter */
    public function actionGroups(): array
    {
        $rows = $this->db->fetchFirstColumn("SELECT DISTINCT SUBSTRING_INDEX(action, '.', 1) FROM audit_events ORDER BY 1");

        return array_values(array_map('strval', $rows));
    }

    public function count(): int
    {
        return (int)$this->db->fetchOne('SELECT COUNT(*) FROM audit_events');
    }

    private function decode(array $row): array
    {
        $row['changes'] = $row['changes'] !== null ? (json_decode((string)$row['changes'], true) ?? []) : [];

        return $row;
    }
}
