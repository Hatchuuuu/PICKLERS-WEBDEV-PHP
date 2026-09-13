<?php
declare(strict_types=1);

namespace Picklers\Controllers;

use Picklers\Core\Database;
use Picklers\Core\Request;
use Picklers\Core\Response;
use Picklers\Middleware\AuthMiddleware;
use Picklers\Middleware\CsrfMiddleware;
use Picklers\Services\NotificationService;

class AppController extends BaseController {
    private NotificationService $notificationService;

    public function __construct(?NotificationService $notificationService = null) {
        $this->notificationService = $notificationService ?? new NotificationService();
    }

    public function index(Request $request) {
        $currentUser = AuthMiddleware::requireAuth();

        $activeTab = (string)$request->query('tab', 'play');
        if ($activeTab === 'booking') {
            $activeTab = 'bookings';
        }
        $validTabs = ['play', 'explore', 'wallet', 'bookings', 'settings'];
        if (!in_array($activeTab, $validTabs, true)) {
            $activeTab = 'play';
        }

        $userNotifications = $this->notificationService->getNotifications($currentUser['id']);
        $unreadNotifsCount = count(array_filter($userNotifications, fn($n) => empty($n['is_read'])));

        $csrfToken = $this->csrfToken();
        $db = Database::get();

        return Response::view('pages/app', [
            'currentUser' => $this->sanitizeUser($currentUser),
            'activeTab' => $activeTab,
            'userNotifications' => $userNotifications,
            'unreadNotifsCount' => $unreadNotifsCount,
            'csrfToken' => $csrfToken,
            'facilities' => $db->getFacilities(),
            'favoriteFacilityIds' => $db->getFavoriteFacilityIds((string)$currentUser['id']),
            'db' => $db
        ]);
    }
}
