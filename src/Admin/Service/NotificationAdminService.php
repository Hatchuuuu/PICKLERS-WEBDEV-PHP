<?php
declare(strict_types=1);

namespace Picklers\Admin\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Picklers\Admin\Http\AdminActionException;
use Picklers\Admin\Security\AdminUser;
use Picklers\Admin\Security\RoleMapper;

/**
 * Admin-originated in-app notices (no email/SMS integration exists).
 *
 * Individual notices and broadcasts are both recorded in notification_broadcasts
 * under a client-generated idempotency key, so a retried or double-clicked send
 * never notifies anyone twice. A broadcast must be confirmed against the audience
 * count the operator previewed: if the audience changed, it is refused and the new
 * count shown. All recipient rows + the broadcast record + the audit row commit
 * together, or nothing is sent.
 */
final class NotificationAdminService
{
    public const AUDIENCES = ['all' => 'All active accounts', 'players' => 'Players', 'owners' => 'Facility owners'];
    public const TYPES = ['system', 'booking', 'wallet', 'community'];

    public function __construct(
        private readonly Connection $db,
        private readonly AuditLog $audit,
    ) {
    }

    private function audienceSql(string $audience): string
    {
        return match ($audience) {
            'players' => "role <> 'deleted' AND is_admin = 0 AND is_owner = 0 AND role <> 'owner'",
            'owners' => "role <> 'deleted' AND (is_owner = 1 OR role = 'owner')",
            'all' => "role <> 'deleted'",
            default => throw AdminActionException::invalid('Choose an audience.', 'role_filter'),
        };
    }

    /** Legacy role_filter values (all|player|owner) map onto audiences. */
    public static function audienceFromLegacy(string $value): string
    {
        return match ($value) {
            'player', 'players' => 'players',
            'owner', 'owners' => 'owners',
            default => 'all',
        };
    }

    public function audienceCount(string $audience): int
    {
        return (int)$this->db->fetchOne('SELECT COUNT(*) FROM users WHERE ' . $this->audienceSql($audience));
    }

    /** @return array{audience:string,label:string,count:int,title:string,body:string} */
    public function preview(string $audience, string $title, string $body): array
    {
        [$title, $body] = $this->validate($title, $body);

        return ['audience' => $audience, 'label' => self::AUDIENCES[$audience] ?? $audience, 'count' => $this->audienceCount($audience), 'title' => $title, 'body' => $body];
    }

    public function broadcast(AdminUser $actor, string $audience, string $title, string $body, string $type, int $confirmCount, string $key): array
    {
        [$title, $body] = $this->validate($title, $body);
        $type = in_array($type, self::TYPES, true) ? $type : 'system';
        $this->validateKey($key);
        if (($replay = $this->replay($key)) !== null) {
            return $replay;
        }
        try {
            return $this->db->transactional(function () use ($actor, $audience, $title, $body, $type, $confirmCount, $key): array {
                $ids = $this->db->fetchFirstColumn('SELECT id FROM users WHERE ' . $this->audienceSql($audience) . ' ORDER BY id FOR UPDATE');
                if (count($ids) !== $confirmCount) {
                    throw AdminActionException::conflict('The audience changed since your preview: it is now ' . count($ids) . ' recipient(s). Review and confirm again.', ['count' => count($ids)]);
                }
                if ($ids === []) {
                    throw AdminActionException::conflict('Nobody is in this audience; nothing was sent.');
                }
                $id = $this->insertBroadcast($actor, $key, $title, $body, $type, $audience, count($ids));
                $this->insertNotifications($ids, $title, $body, $type, $id);
                $this->audit->record('notification.broadcast', 'notification', $id, ['audience' => $audience, 'recipients' => count($ids), 'title' => $title, 'body_length' => mb_strlen($body)]);

                return ['broadcast_id' => $id, 'sent_to' => count($ids), 'replayed' => false, 'message' => 'Broadcast delivered to ' . count($ids) . ' in-app inbox(es).'];
            });
        } catch (UniqueConstraintViolationException) {
            return $this->replay($key) ?? throw AdminActionException::conflict('This broadcast was already sent.');
        }
    }

    public function sendToUser(AdminUser $actor, string $userId, string $title, string $body, string $key): array
    {
        [$title, $body] = $this->validate($title === '' ? 'Admin Notice 🔔' : $title, $body);
        $this->validateKey($key);
        if (($replay = $this->replay($key)) !== null) {
            return $replay;
        }
        try {
            return $this->db->transactional(function () use ($actor, $userId, $title, $body, $key): array {
                $user = $this->db->fetchAssociative('SELECT id, name, role FROM users WHERE id = ?', [$userId]);
                if ($user === false) {
                    throw AdminActionException::notFound('User');
                }
                if (RoleMapper::isDeactivated($user)) {
                    throw AdminActionException::conflict('This account is deactivated and cannot read notices.');
                }
                $id = $this->insertBroadcast($actor, $key, $title, $body, 'system', 'user', 1);
                $this->insertNotifications([$userId], $title, $body, 'system', $id);
                $this->audit->record('notification.send', 'user', $userId, ['broadcast_id' => $id, 'title' => $title, 'body_length' => mb_strlen($body)]);

                return ['user_id' => $userId, 'sent_to' => 1, 'replayed' => false, 'message' => 'Notice delivered to ' . $user['name'] . '’s in-app inbox.'];
            });
        } catch (UniqueConstraintViolationException) {
            return $this->replay($key) ?? throw AdminActionException::conflict('This notice was already sent.');
        }
    }

    /** @return list<array<string,mixed>> */
    public function recent(int $limit = 10): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT b.id, b.title, b.audience, b.recipient_count, b.status, b.created_at, u.name AS sender FROM notification_broadcasts b LEFT JOIN users u ON u.id = b.created_by ORDER BY b.created_at DESC LIMIT ' . max(1, min(50, $limit))
        );
    }

    private function insertBroadcast(AdminUser $actor, string $key, string $title, string $body, string $type, string $audience, int $count): string
    {
        $id = 'bc_' . bin2hex(random_bytes(6));
        $this->db->insert('notification_broadcasts', [
            'id' => $id, 'idempotency_key' => $key, 'title' => $title, 'body' => $body, 'type' => $type, 'audience' => $audience,
            'recipient_count' => $count, 'status' => 'sent', 'created_by' => $actor->id(), 'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $id;
    }

    /** @param list<string> $userIds */
    private function insertNotifications(array $userIds, string $title, string $body, string $type, string $broadcastId): void
    {
        $now = date('Y-m-d H:i:s');
        foreach (array_chunk($userIds, 200) as $chunk) {
            $values = [];
            $params = [];
            foreach ($chunk as $uid) {
                $values[] = '(?,?,?,?,?,0,?,?,?)';
                array_push($params, 'ntf_' . bin2hex(random_bytes(6)), $uid, $title, $body, $type, 'Just now', $now, $broadcastId);
            }
            $this->db->executeStatement(
                'INSERT INTO notifications (id, user_id, title, body, type, is_read, time, created_at, broadcast_id) VALUES ' . implode(',', $values),
                $params
            );
        }
    }

    private function replay(string $key): ?array
    {
        $prior = $this->db->fetchAssociative('SELECT id, recipient_count, audience FROM notification_broadcasts WHERE idempotency_key = ?', [$key]);
        if ($prior === false) {
            return null;
        }

        return ['broadcast_id' => $prior['id'], 'sent_to' => (int)$prior['recipient_count'], 'replayed' => true,
            'message' => 'Already sent to ' . (int)$prior['recipient_count'] . ' recipient(s); the duplicate submission was ignored.'];
    }

    /** @return array{0:string,1:string} */
    private function validate(string $title, string $body): array
    {
        $title = trim($title);
        $body = trim($body);
        if ($title === '' || mb_strlen($title) > 150) {
            throw AdminActionException::invalid('Title is required (up to 150 characters).', 'title');
        }
        if ($body === '' || mb_strlen($body) > 2000) {
            throw AdminActionException::invalid('Message is required (up to 2,000 characters).', 'message');
        }

        return [$title, $body];
    }

    private function validateKey(string $key): void
    {
        if (!preg_match('/^[A-Za-z0-9\-]{16,64}$/', $key)) {
            throw AdminActionException::invalid('This form expired. Close it and open it again.');
        }
    }
}
