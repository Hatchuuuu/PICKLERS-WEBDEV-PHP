<?php
declare(strict_types=1);

namespace Picklers\Web\Controller;

use Picklers\Core\Database;
use Picklers\Repositories\NotificationRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** The player app: Play, Explore, Wallet, Bookings and Settings tabs. */
final class AppController extends AbstractWebController
{
    private const TABS = ['play', 'explore', 'wallet', 'bookings', 'settings'];

    public function __construct(
        private readonly Database $db,
        private readonly NotificationRepository $notifications,
    ) {
    }

    #[Route('/app', name: 'app', methods: ['GET', 'POST'])]
    #[Route('/app.php', name: 'app_php', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $user = $this->sessionUser()?->row();
        if ($user === null) {
            return $this->redirectToPath($request, 'auth.php');
        }
        $tab = (string)$request->query->get('tab', 'play');
        $tab = $tab === 'booking' ? 'bookings' : $tab;
        if (!in_array($tab, self::TABS, true)) {
            $tab = 'play';
        }
        $id = (string)$user['id'];
        $notifications = $this->notifications->getNotifications($id);

        return $this->render('web/app.html.twig', [
            'currentUser' => $user,
            'activeTab' => $tab,
            'userNotifications' => $notifications,
            'unreadNotifsCount' => count(array_filter($notifications, static fn($n) => empty($n['is_read']))),
            'csrfToken' => $this->csrfToken(),
            'facilities' => $this->db->getFacilities(),
            'favoriteFacilityIds' => $this->db->getFavoriteFacilityIds($id),
            'matches' => $this->db->getMatches(),
            'bookings' => $this->db->getBookings($id),
            'walletTransactions' => $this->db->getWalletTransactions($id),
            'latestApp' => $this->db->getLatestApplicationForUser($id),
            'isStaffUser' => $this->db->getStaffFacilityIdsForUser($id, (string)($user['email'] ?? '')) !== [],
            'db' => $this->db,
        ]);
    }
}
