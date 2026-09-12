<?php
declare(strict_types=1);

namespace Picklers\Models;

use Picklers\Services\NotificationService;

class Notification {
    private NotificationService $notificationService;

    public function __construct(?NotificationService $notificationService = null) {
        $this->notificationService = $notificationService ?? new NotificationService();
    }

    public function getForUser(string $userId): array {
        return $this->notificationService->getNotifications($userId);
    }

    public function create(string $userId, string $title, string $body, string $type = 'system'): array {
        return $this->notificationService->addNotification($userId, $title, $body, $type);
    }
}
