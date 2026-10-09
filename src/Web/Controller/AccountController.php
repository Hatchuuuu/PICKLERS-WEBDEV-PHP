<?php
declare(strict_types=1);

namespace Picklers\Web\Controller;

use Picklers\Admin\Http\ActionInput;
use Picklers\Web\Security\SessionUser;
use Picklers\Web\Service\AccountService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Section 3 — Account & notifications. Legacy names (/api?action=me,
 * change_password, update_profile, verify_identity, delete_own_account, logout,
 * mark_notifications_read, delete_notification) reach these methods through the
 * /api compatibility controller.
 */
#[Route('/api')]
final class AccountController extends AbstractWebController
{
    public function __construct(private readonly AccountService $account)
    {
    }

    #[Route('/me', name: 'web_me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        $user = $this->sessionUser();

        return $this->legacyJson([
            'success' => true,
            'user' => $user?->row(),
            'csrf_token' => $this->csrfToken(),
            'notifications' => $user ? $this->account->notifications($user->id()) : [],
        ]);
    }

    #[Route('/me/password', name: 'web_me_password', methods: ['POST'])]
    public function changePassword(Request $request): JsonResponse
    {
        $user = $this->guardMutation($request, 'change_password', 'Unauthorized: Please log in to change your password.', true, true);
        $in = new ActionInput($request);
        $this->account->changePassword($user->id(), $in->string('current_password'), $in->string('new_password'), $in->string('confirm_password'));

        return $this->legacyJson(['success' => true, 'message' => 'Password updated successfully!']);
    }

    #[Route('/me/profile', name: 'web_me_profile', methods: ['POST'])]
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $this->guardMutation($request, 'update_profile', 'Unauthorized', true, true);
        $in = new ActionInput($request);
        $fields = [];
        foreach (['name', 'email', 'phone', 'level', 'avatar_url'] as $key) {
            $fields[$key] = $in->string($key);
        }
        $updated = $this->account->updateProfile($user->row(), $fields);

        return $this->legacyJson(['success' => true, 'user' => (new SessionUser($updated))->row(), 'message' => 'Profile updated successfully!']);
    }

    #[Route('/me/verification', name: 'web_me_verification', methods: ['POST'])]
    public function requestVerification(Request $request): JsonResponse
    {
        $user = $this->guardMutation($request, 'verify_identity', 'Unauthorized', true, true);
        $result = $this->account->requestVerification($user->row());

        return $this->legacyJson([
            'success' => true,
            'status' => $result['status'],
            'user' => $result['user'] !== null ? (new SessionUser($result['user']))->row() : $user->row(),
            'message' => $result['message'],
        ]);
    }

    #[Route('/me/delete', name: 'web_me_delete', methods: ['POST'])]
    public function deleteAccount(Request $request): JsonResponse
    {
        $user = $this->guardMutation($request, 'delete_own_account', 'Unauthorized', true, true);
        $this->account->deleteOwnAccount($user->row(), (new ActionInput($request))->string('confirm'));

        return $this->legacySuccess('Your account has been deactivated. Signing you out.', ['redirect' => $request->getBaseUrl() . '/auth.php?logout=1']);
    }

    #[Route('/logout', name: 'web_logout', methods: ['GET', 'POST'])]
    public function logout(Request $request): JsonResponse
    {
        if ($request->isMethod('POST')) {
            $this->guardMutation($request, 'logout', null); // a posted sign-out needs the CSRF token, as before
        }
        $request->getSession()->invalidate();

        return $this->legacySuccess('Logged out successfully');
    }

    #[Route('/notifications/read', name: 'web_notifications_read', methods: ['POST'])]
    public function markNotificationsRead(Request $request): JsonResponse
    {
        $user = $this->guardMutation($request, 'mark_notifications_read', 'Unauthorized');
        $this->account->markAllRead($user->id());

        return $this->legacyJson(['success' => true, 'message' => 'All notifications marked as read']);
    }

    #[Route('/notifications/{id}/delete', name: 'web_notification_delete', methods: ['POST'])]
    public function deleteNotification(string $id, Request $request): JsonResponse
    {
        $user = $this->guardMutation($request, 'delete_notification', 'Unauthorized');
        $this->account->deleteNotification($user->id(), $id);

        return $this->legacyJson(['success' => true, 'message' => 'Notification removed']);
    }
}
