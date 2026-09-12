<?php
declare(strict_types=1);

namespace Picklers\Repositories;

use Picklers\Core\Database;

class NotificationRepository {
    private Database $db;

    public function __construct(?Database $db = null) {
        $this->db = $db ?? Database::get();
    }

    public function getNotifications(string $userId): array {
        return $this->db->getNotifications($userId);
    }

    public function addNotification(string $userId, string $title, string $body, string $type = 'system'): array {
        return $this->db->addNotification($userId, $title, $body, $type);
    }

    public function markAllAsRead(string $userId): bool {
        return (bool)$this->db->markAllNotificationsRead($userId);
    }

    public function deleteNotification(string $notifId, string $userId): bool {
        return (bool)$this->db->deleteNotification($notifId, $userId);
    }
}

