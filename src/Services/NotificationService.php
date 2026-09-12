<?php
declare(strict_types=1);

namespace Picklers\Services;

use Picklers\Repositories\NotificationRepository;

class NotificationService {
    private NotificationRepository $notificationRepo;

    public function __construct(?NotificationRepository $notificationRepo = null) {
        $this->notificationRepo = $notificationRepo ?? new NotificationRepository();
    }

    public function getNotifications(string $userId): array {
        return $this->notificationRepo->getNotifications($userId);
    }

    public function addNotification(string $userId, string $title, string $body, string $type = 'system'): array {
        return $this->notificationRepo->addNotification($userId, $title, $body, $type);
    }

    public function markAllAsRead(string $userId): bool {
        return $this->notificationRepo->markAllAsRead($userId);
    }

    public function deleteNotification(string $notifId, string $userId): bool {
        return $this->notificationRepo->deleteNotification($notifId, $userId);
    }
}

