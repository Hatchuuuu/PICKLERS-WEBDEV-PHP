<?php
declare(strict_types=1);

namespace Picklers\Repositories;

/**
 * A user's notifications and the sync versions clients poll.
 */
final class NotificationRepository
{
    private readonly \Doctrine\DBAL\Connection $db;
    private readonly \Picklers\Domain\Notifier $notifier;
    private readonly \Picklers\Repositories\UserRepository $users;

    public function __construct(
        ?\Doctrine\DBAL\Connection $db = null,
        ?\Picklers\Domain\Notifier $notifier = null,
        ?\Picklers\Repositories\UserRepository $users = null,
    ) {
        $this->db = $db ?? \Picklers\Core\Database::get()->dbal();
        $this->notifier = $notifier ?? \Picklers\Core\Database::get()->notifier();
        $this->users = $users ?? new \Picklers\Repositories\UserRepository($this->db);
    }

    /** @return array<string,int> channel => current version */
    public function getSyncVersions(): array {
        try {
            $rows = $this->db->executeQuery("SELECT channel, version FROM sync_versions")->fetchAllAssociative();
            return array_map('intval', array_column($rows, 'version', 'channel'));
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * A compact fingerprint of one account's own bookings plus its wallet
     * balance, carried on every sync poll. The sync channels are platform-wide
     * version counters, so a player's Bookings tab and balance had no way to
     * learn that THEIR request was accepted/declined or a refund landed.
     *
     * @return array{bookings:string,wallet:float}
     */
    public function getAccountSyncState(string $userId): array {
        // CRC32 sums instead of GROUP_CONCAT, which silently truncates at
        // group_concat_max_len for an account with many bookings.
        $s = $this->db->executeQuery(
            "SELECT COUNT(*) AS n,
                    COALESCE(SUM(CRC32(CONCAT(id, ':', status, ':', COALESCE(ended_early, 0)))), 0) AS sig
               FROM bookings WHERE user_id = ?",
            [$userId]
        );
        $row = $s->fetchAssociative() ?: ['n' => 0, 'sig' => 0];
        $bookingsSig = (int)$row['n'] . ':' . (string)$row['sig'];

        $user = $this->users->getUserById($userId);
        return [
            'bookings' => $bookingsSig,
            'wallet'   => round((float)($user['wallet_balance'] ?? 0), 2),
        ];
    }

    public function getNotifications($userId) {
        $stmt = $this->db->executeQuery("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 20", [$userId]);
        $list = $stmt->fetchAllAssociative();

        foreach ($list as &$item) {
            if (!empty($item['created_at'])) {
                $ts = strtotime($item['created_at']);
                if ($ts) {
                    $diff = time() - $ts;
                    if ($diff < 60) {
                        $item['time'] = 'Just now';
                    } elseif ($diff < 3600) {
                        $item['time'] = max(1, (int)floor($diff / 60)) . 'm ago';
                    } elseif ($diff < 86400) {
                        $item['time'] = max(1, (int)floor($diff / 3600)) . 'h ago';
                    } elseif ($diff < 172800) {
                        $item['time'] = 'Yesterday';
                    } elseif ($diff < 604800) {
                        $item['time'] = max(1, (int)floor($diff / 86400)) . 'd ago';
                    } else {
                        $item['time'] = date('M j', $ts);
                    }
                }
            }
        }
        unset($item);

        return $list;
    }

    public function addNotification($userId, $title, $body, $type = 'system') {
        return $this->notifier->notify((string)$userId, (string)$title, (string)$body, (string)$type);
    }

    public function markAllNotificationsRead($userId) {
        $this->db->executeStatement("UPDATE notifications SET is_read = 1 WHERE user_id = ?", [$userId]);
        return true;
    }

    public function markAllAsRead($userId) {
        return $this->markAllNotificationsRead($userId);
    }

    public function deleteNotification($notifId, $userId) {
        $stmt = $this->db->executeQuery("DELETE FROM notifications WHERE id = ? AND user_id = ?", [$notifId, $userId]);
        return $stmt->rowCount() > 0;
    }
}
