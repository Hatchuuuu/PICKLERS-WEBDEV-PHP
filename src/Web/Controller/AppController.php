<?php
declare(strict_types=1);

namespace Picklers\Web\Controller;

use Picklers\Repositories\FacilityRepository;
use Picklers\Repositories\OpenPlayRepository;
use Picklers\Repositories\BookingRepository;
use Picklers\Repositories\WalletRepository;
use Picklers\Repositories\OwnerRepository;
use Picklers\Repositories\NotificationRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** The player app: Play, Explore, Wallet, Bookings and Settings tabs. */
final class AppController extends AbstractWebController
{
    private const TABS = ['play', 'explore', 'wallet', 'bookings', 'settings'];

    public function __construct(
        private readonly FacilityRepository $facilities,
        private readonly OpenPlayRepository $openPlay,
        private readonly BookingRepository $bookings,
        private readonly WalletRepository $wallet,
        private readonly OwnerRepository $owners,
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
        $allFacilities = $this->facilities->getFacilities();
        $ownerFacility = null;
        foreach ($allFacilities as $f) {
            if ((string)($f['owner_id'] ?? '') === $id) {
                $ownerFacility = $f;
                break;
            }
        }
        $staffIds = $this->owners->getStaffFacilityIdsForUser($id, (string)($user['email'] ?? ''));
        if ($ownerFacility === null && $staffIds !== []) {
            foreach ($allFacilities as $f) {
                if (in_array((int)$f['id'], $staffIds, true) || in_array((string)$f['id'], $staffIds, true)) {
                    $ownerFacility = $f;
                    break;
                }
            }
        }
        if ($ownerFacility === null && !empty($user['is_admin']) && !empty($allFacilities)) {
            $ownerFacility = $allFacilities[0];
        }

        return $this->render('web/app.html.twig', [
            'currentUser' => $user,
            'activeTab' => $tab,
            'userNotifications' => $notifications,
            'unreadNotifsCount' => count(array_filter($notifications, static fn($n) => empty($n['is_read']))),
            'csrfToken' => $this->csrfToken(),
            'facilities' => $allFacilities,
            'ownerFacility' => $ownerFacility,
            'favoriteFacilityIds' => $this->facilities->getFavoriteFacilityIds($id),
            'matches' => $this->openPlay->getMatches(),
            'bookings' => $this->bookings->getBookings($id),
            'walletTransactions' => $this->wallet->getWalletTransactions($id),
            'latestApp' => $this->owners->getLatestApplicationForUser($id),
            'isStaffUser' => $staffIds !== [],
            'db' => $this->bookings,
        ]);
    }
}
