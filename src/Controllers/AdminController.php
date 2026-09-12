<?php
declare(strict_types=1);

namespace Picklers\Controllers;

use Exception;
use Picklers\Core\Database;
use Picklers\Core\Request;
use Picklers\Core\Response;
use Picklers\Middleware\AuthMiddleware;
use Picklers\Services\AuthService;
use Picklers\Services\BookingService;
use Picklers\Services\FacilityService;
use Picklers\Services\NotificationService;
use Picklers\Services\WalletService;

/**
 * AdminController
 *
 * Handles all admin console page rendering and admin-only API actions.
 * Requires authenticated user with is_admin = 1.
 */
class AdminController extends BaseController {

    private AuthService $authService;
    private FacilityService $facilityService;
    private BookingService $bookingService;
    private WalletService $walletService;
    private NotificationService $notificationService;

    public function __construct(
        ?AuthService $authService = null,
        ?FacilityService $facilityService = null,
        ?BookingService $bookingService = null,
        ?WalletService $walletService = null,
        ?NotificationService $notificationService = null
    ) {
        $this->authService          = $authService          ?? new AuthService();
        $this->facilityService      = $facilityService      ?? new FacilityService();
        $this->bookingService       = $bookingService       ?? new BookingService();
        $this->walletService        = $walletService        ?? new WalletService();
        $this->notificationService  = $notificationService  ?? new NotificationService();
    }

    // ==========================================================================
    // Page Renderer
    // ==========================================================================

    /**
     * Render the Admin Console page with full platform KPI stats.
     */
    public function index(Request $request): void {
        $currentUser = AuthMiddleware::requireAdmin();

        $users              = $this->authService->getAllUsers();
        $facilities         = $this->facilityService->getFacilities();
        $bookings           = $this->bookingService->getBookings();
        $ownerApplications  = Database::get()->getOwnerApplications();

        // Normalize wallet_balance in user list — prevents number_format() TypeError
        // when JSON-mode users lack the field (PHP 8 strict types enforcement).
        $users = array_map(function (array $u): array {
            $u['wallet_balance'] = (float)($u['wallet_balance'] ?? 0.0);
            return $u;
        }, $users);

        // ── Platform KPIs ────────────────────────────────────────────────────
        $totalUsers      = count($users);
        $verifiedUsers   = count(array_filter($users, fn($u) => ($u['verification_status'] ?? '') === 'verified'));
        $totalFacilities = count($facilities);
        $totalBookings   = count($bookings);
        $verifiedOwners  = count(array_filter($users, fn($u) => !empty($u['is_owner'])));

        $pendingApplications = array_values(array_filter(
            $ownerApplications,
            fn($a) => ($a['status'] ?? 'pending_review') === 'pending_review'
        ));

        $cancelledBookings = count(array_filter($bookings, fn($b) => ($b['status'] ?? '') === 'cancelled'));
        $disputeFreePct    = $totalBookings > 0
            ? round((1 - $cancelledBookings / $totalBookings) * 100, 1)
            : 100.0;

        $confirmedBookings = array_filter($bookings, fn($b) => ($b['status'] ?? '') !== 'cancelled');
        $grossVolume       = (float)array_sum(array_column($confirmedBookings, 'price'));

        // Map user_id -> user record so pending application cards can show a name/avatar
        // even though the applicant's own submitted name lives on the application row.
        $usersById = [];
        foreach ($users as $u) {
            $usersById[(string)($u['id'] ?? '')] = $u;
        }

        $promos     = Database::get()->getPromoCodes();
        $promoStats = Database::get()->getPromoStats();

        Response::view('pages/admin', [
            'currentUser'          => $this->sanitizeUser($currentUser),
            'users'                => $users,
            'usersById'            => $usersById,
            'facilities'           => $facilities,
            'bookings'             => $bookings,
            'ownerApplications'    => $ownerApplications,
            'pendingApplications'  => $pendingApplications,
            'totalUsers'           => $totalUsers,
            'verifiedUsers'        => $verifiedUsers,
            'totalFacilities'      => $totalFacilities,
            'totalBookings'        => $totalBookings,
            'verifiedPartners'     => $verifiedOwners,
            'disputeFreePct'       => $disputeFreePct,
            'grossVolume'          => $grossVolume,
            'promos'               => $promos,
            'promoStats'           => $promoStats,
            'activePromos'         => $promoStats['totalActive'],
            'usingMySQL'           => Database::get()->isUsingMySQL(),
        ]);
    }

    // ==========================================================================
    // Admin API Handler  —  POST/GET  admin.php?action=...
    // ==========================================================================

    /**
     * Dispatch admin API actions.
     * All mutating requests are CSRF-protected.
     * All actions require admin session.
     */
    public function handle(Request $request): void {
        $action      = (string)($request->query('action') ?? $request->input('action', ''));
        $currentUser = AuthMiddleware::user();
        $csrfToken   = $this->csrfToken();

        // ── Auth guard ───────────────────────────────────────────────────────
        if (!$currentUser || empty($currentUser['is_admin'])) {
            $this->jsonError('Unauthorized: Admin access required.', 403);
            return;
        }

        // ── CSRF guard for mutating requests ─────────────────────────────────
        $isMutating = in_array(strtoupper($request->getMethod()), ['POST', 'PUT', 'PATCH', 'DELETE'], true);
        if ($isMutating && !$this->validateCsrf($request)) {
            $this->jsonError('CSRF security verification failed.', 403);
            return;
        }

        try {
            switch ($action) {

                // ──────────────────────────────────────────────────────────────
                // Platform Stats (real-time refresh)
                // ──────────────────────────────────────────────────────────────
                case 'admin_stats':
                    $users     = $this->authService->getAllUsers();
                    $bookings  = $this->bookingService->getBookings();
                    $confirmed = array_filter($bookings, fn($b) => ($b['status'] ?? '') !== 'cancelled');
                    $cancelled = count($bookings) - count($confirmed);

                    $this->json([
                        'success'          => true,
                        'total_users'      => count($users),
                        'verified_users'   => count(array_filter($users, fn($u) => ($u['verification_status'] ?? '') === 'verified')),
                        'total_facilities' => count($this->facilityService->getFacilities()),
                        'total_bookings'   => count($bookings),
                        'gross_volume'     => (float)array_sum(array_column($confirmed, 'price')),
                        'cancelled'        => $cancelled,
                        'dispute_free_pct' => count($bookings) > 0
                            ? round((1 - $cancelled / count($bookings)) * 100, 1)
                            : 100.0,
                    ]);
                // ──────────────────────────────────────────────────────────────
                // Promo Code Engine
                // ──────────────────────────────────────────────────────────────
                case 'admin_get_promos':
                    $promos = Database::get()->getPromoCodes();
                    $stats = Database::get()->getPromoStats();
                    $this->json(['success' => true, 'promos' => $promos, 'stats' => $stats]);
                    return;

                case 'admin_create_promo':
                    $code = strtoupper(trim((string)$request->input('code', '')));
                    $type = (string)$request->input('discount_type', 'fixed');
                    $value = (float)$request->input('discount_value', 0);
                    $minSpend = (float)$request->input('min_spend', 0);
                    $usageLimit = (int)$request->input('usage_limit', 0);
                    $userLimit = (int)$request->input('user_limit', 1);
                    $expiresAt = trim((string)$request->input('expires_at', ''));

                    if ($value <= 0) {
                        $this->jsonError('Enter a valid discount value greater than 0.', 400);
                        return;
                    }
                    if ($type === 'percentage' && $value > 100) {
                        $this->jsonError('Percentage discount cannot exceed 100%.', 400);
                        return;
                    }

                    $promo = Database::get()->createPromoCode([
                        'code' => $code,
                        'discount_type' => $type,
                        'discount_value' => $value,
                        'min_spend' => $minSpend,
                        'usage_limit' => $usageLimit,
                        'user_limit' => $userLimit,
                        'expires_at' => !empty($expiresAt) ? date('Y-m-d H:i:s', strtotime($expiresAt)) : null,
                        'status' => 'active'
                    ]);

                    $this->jsonSuccess(['promo' => $promo], "Promo code {$promo['code']} created and activated successfully.");
                    return;

                case 'admin_toggle_promo':
                    $promoId = (string)$request->input('promo_id', '');
                    if (empty($promoId)) {
                        $this->jsonError('Missing promo_id.', 400);
                        return;
                    }
                    $res = Database::get()->togglePromoStatus($promoId);
                    if ($res) {
                        $this->jsonSuccess([], 'Promo code status updated.');
                    } else {
                        $this->jsonError('Promo code not found.', 404);
                    }
                    return;

                case 'admin_delete_promo':
                    $promoId = (string)$request->input('promo_id', '');
                    if (empty($promoId)) {
                        $this->jsonError('Missing promo_id.', 400);
                        return;
                    }
                    Database::get()->deletePromoCode($promoId);
                    $this->jsonSuccess([], 'Promo code removed.');
                    return;

                // ──────────────────────────────────────────────────────────────
                // User Management
                // ──────────────────────────────────────────────────────────────

                case 'admin_get_users':
                    $users = array_map(function (array $u): array {
                        $u['wallet_balance'] = (float)($u['wallet_balance'] ?? 0.0);
                        unset($u['password_hash'], $u['is_dev']);
                        return $u;
                    }, $this->authService->getAllUsers());

                    $this->json(['success' => true, 'users' => $users]);
                    return;

                case 'admin_get_user':
                    $userId = (string)$request->input('user_id', '');
                    if (empty($userId)) {
                        $this->jsonError('Missing user_id.', 400);
                        return;
                    }
                    $user = $this->authService->getUserById($userId);
                    if (!$user) {
                        $this->jsonError('User not found.', 404);
                        return;
                    }
                    $user['wallet_balance'] = (float)($user['wallet_balance'] ?? 0.0);
                    $user['transactions']   = $this->walletService->getWalletTransactions($userId);
                    unset($user['password_hash'], $user['is_dev']);
                    $this->json(['success' => true, 'user' => $user]);
                    return;

                case 'admin_update_role':
                    $targetId = (string)$request->input('user_id', '');
                    $newRole  = (string)$request->input('role', 'player');

                    if (empty($targetId)) {
                        $this->jsonError('Missing user_id.', 400);
                        return;
                    }
                    $allowedRoles = ['player', 'owner', 'admin'];
                    if (!in_array($newRole, $allowedRoles, true)) {
                        $this->jsonError('Invalid role. Must be player, owner, or admin.', 400);
                        return;
                    }

                    $this->authService->updateUser($targetId, [
                        'role'     => $newRole,
                        'is_admin' => ($newRole === 'admin') ? 1 : 0,
                        'is_owner' => ($newRole === 'owner') ? 1 : 0,
                        'is_dev'   => 0,
                    ]);

                    $this->notificationService->addNotification(
                        $targetId,
                        'Account Role Updated 🛡️',
                        "Your account role has been updated to " . ucfirst($newRole) . " by an administrator.",
                        'system'
                    );

                    $this->jsonSuccess(
                        ['user_id' => $targetId, 'new_role' => $newRole],
                        "User role updated to {$newRole}."
                    );
                    return;

                case 'admin_toggle_verify':
                    $targetId = (string)$request->input('user_id', '');
                    if (empty($targetId)) {
                        $this->jsonError('Missing user_id.', 400);
                        return;
                    }
                    $user = $this->authService->getUserById($targetId);
                    if (!$user) {
                        $this->jsonError('User not found.', 404);
                        return;
                    }
                    $newStatus = (($user['verification_status'] ?? '') === 'verified') ? 'unverified' : 'verified';
                    $this->authService->updateUser($targetId, ['verification_status' => $newStatus]);

                    if ($newStatus === 'verified') {
                        $this->notificationService->addNotification(
                            $targetId,
                            'Identity Verified! 🛡️',
                            'Your player identity has been officially verified by the Picklers admin team.',
                            'system'
                        );
                    }

                    $this->json([
                        'success'    => true,
                        'new_status' => $newStatus,
                        'message'    => "User verification set to {$newStatus}.",
                    ]);
                    return;

                case 'admin_delete_user':
                    $targetId = (string)$request->input('user_id', '');
                    if (empty($targetId)) {
                        $this->jsonError('Missing user_id.', 400);
                        return;
                    }
                    // Prevent self-deletion
                    if ($targetId === ($currentUser['id'] ?? '')) {
                        $this->jsonError('You cannot delete your own admin account.', 400);
                        return;
                    }
                    $db   = Database::get();
                    $user = $this->authService->getUserById($targetId);
                    if (!$user) {
                        $this->jsonError('User not found.', 404);
                        return;
                    }
                    // Soft-delete: mark as deleted role and strip sensitive data
                    $this->authService->updateUser($targetId, [
                        'role'                => 'deleted',
                        'email'               => 'deleted_' . $targetId . '@picklers.invalid',
                        'is_admin'            => 0,
                        'is_owner'            => 0,
                        'is_dev'              => 0,
                        'verification_status' => 'unverified',
                    ]);
                    $this->jsonSuccess(['user_id' => $targetId], "User account has been deactivated.");
                    return;

                case 'admin_reset_password':
                    $targetId    = (string)$request->input('user_id', '');
                    $newPassword = (string)$request->input('new_password', '');
                    if (empty($targetId)) {
                        $this->jsonError('Missing user_id.', 400);
                        return;
                    }
                    if (strlen($newPassword) < 6) {
                        $this->jsonError('New password must be at least 6 characters.', 400);
                        return;
                    }
                    $this->authService->updateUser($targetId, [
                        'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                    ]);
                    $this->notificationService->addNotification(
                        $targetId,
                        'Password Reset 🔒',
                        'Your Picklers account password has been reset by an administrator. Please sign in with your new credentials.',
                        'system'
                    );
                    $this->jsonSuccess(['user_id' => $targetId], 'Password reset successfully.');
                    return;

                case 'admin_send_notification':
                    $targetId = (string)$request->input('user_id', '');
                    $title    = trim((string)$request->input('title', 'Admin Notice 🔔'));
                    $message  = trim((string)$request->input('message', ''));
                    if (empty($targetId) || empty($message)) {
                        $this->jsonError('Missing user_id or message content.', 400);
                        return;
                    }
                    $this->notificationService->addNotification($targetId, $title, $message, 'system');
                    $this->jsonSuccess(['user_id' => $targetId], 'System notice sent to user inbox.');
                    return;

                case 'admin_reactivate_user':
                    $targetId = (string)$request->input('user_id', '');
                    if (empty($targetId)) {
                        $this->jsonError('Missing user_id.', 400);
                        return;
                    }
                    $this->authService->updateUser($targetId, [
                        'role'                => 'player',
                        'verification_status' => 'unverified',
                    ]);
                    $this->notificationService->addNotification(
                        $targetId,
                        'Account Reactivated 🎉',
                        'Your Picklers account access has been restored by an administrator.',
                        'system'
                    );
                    $this->jsonSuccess(['user_id' => $targetId], 'User account reactivated successfully.');
                    return;

                case 'admin_impersonate':
                    $targetId = (string)$request->input('user_id', '');
                    if (empty($targetId)) {
                        $this->jsonError('Missing user_id.', 400);
                        return;
                    }
                    $target = $this->authService->getUserById($targetId);
                    if (!$target) {
                        $this->jsonError('Target user not found.', 404);
                        return;
                    }
                    // Store the original admin ID so they can return
                    $_SESSION['admin_origin_id'] = $currentUser['id'];
                    unset($_SESSION['user']);
                    AuthMiddleware::login($target['id']);

                    $this->json([
                        'success'  => true,
                        'user'     => $this->sanitizeUser($target),
                        'message'  => 'Now impersonating ' . $target['name'] . '. Return to admin at any time.',
                    ]);
                    return;

                // ──────────────────────────────────────────────────────────────
                // Wallet Management
                // ──────────────────────────────────────────────────────────────

                case 'admin_get_wallet':
                    $targetId = (string)$request->input('user_id', '');
                    if (empty($targetId)) {
                        $this->jsonError('Missing user_id.', 400);
                        return;
                    }
                    $user = $this->authService->getUserById($targetId);
                    if (!$user) {
                        $this->jsonError('User not found.', 404);
                        return;
                    }
                    $txs = $this->walletService->getWalletTransactions($targetId);
                    $this->json([
                        'success'      => true,
                        'user_id'      => $targetId,
                        'balance'      => (float)($user['wallet_balance'] ?? 0.0),
                        'transactions' => $txs,
                    ]);
                    return;

                case 'admin_adjust_wallet':
                    $targetId = (string)$request->input('user_id', '');
                    $amount   = (float)$request->input('amount', 0);
                    $label    = trim((string)$request->input('label', ''));
                    $type     = (string)$request->input('type', 'credit'); // 'credit' or 'debit'

                    if (empty($targetId)) {
                        $this->jsonError('Missing user_id.', 400);
                        return;
                    }
                    if ($amount <= 0) {
                        $this->jsonError('Amount must be greater than zero.', 400);
                        return;
                    }
                    if ($label === '') {
                        $this->jsonError('A label is required for audit purposes.', 400);
                        return;
                    }
                    if (!in_array($type, ['credit', 'debit'], true)) {
                        $this->jsonError("Invalid type. Must be 'credit' or 'debit'.", 400);
                        return;
                    }

                    $db = \Picklers\Core\Database::get();
                    $adjustResult = $db->adjustWalletAtomically($targetId, $type, $amount, $label);

                    if (!$adjustResult['success']) {
                        $this->jsonError($adjustResult['message'] ?? 'Wallet adjustment failed.', 400);
                        return;
                    }

                    $newBalance = (float)($adjustResult['new_balance'] ?? 0.0);

                    $notifTitle = $type === 'credit'
                        ? 'Wallet Credit Added'
                        : 'Wallet Deduction Applied';
                    $notifBody  = $type === 'credit'
                        ? "₱" . number_format($amount, 2) . " has been credited to your Pickle Credits wallet by an administrator. Label: {$label}."
                        : "₱" . number_format($amount, 2) . " has been deducted from your Pickle Credits wallet by an administrator. Label: {$label}.";

                    $this->notificationService->addNotification($targetId, $notifTitle, $notifBody, 'wallet');

                    $this->jsonSuccess([
                        'user_id'      => $targetId,
                        'type'         => $type,
                        'amount'       => $amount,
                        'new_balance'  => $newBalance,
                    ], "Wallet {$type} of ₱" . number_format($amount, 2) . " applied successfully.");
                    return;

                // ──────────────────────────────────────────────────────────────
                // Booking Management (Admin override)
                // ──────────────────────────────────────────────────────────────

                case 'admin_get_bookings':
                    $bookings = $this->bookingService->getBookings();
                    $this->json(['success' => true, 'bookings' => $bookings]);
                    return;

                case 'admin_update_booking_status':
                    $bookingId = (string)$request->input('booking_id', '');
                    $status    = (string)$request->input('status', '');
                    if (empty($bookingId) || empty($status)) {
                        $this->jsonError('Missing booking_id or status.', 400);
                        return;
                    }
                    $allowedStatuses = ['upcoming', 'confirmed', 'completed', 'cancelled'];
                    if (!in_array($status, $allowedStatuses, true)) {
                        $this->jsonError('Invalid status value. Allowed: ' . implode(', ', $allowedStatuses), 400);
                        return;
                    }
                    $result = $this->bookingService->updateBookingStatus($bookingId, $status);
                    if (!$result) {
                        $this->jsonError('Failed to update booking status. Booking may not exist.', 500);
                        return;
                    }
                    $this->jsonSuccess(
                        ['booking_id' => $bookingId, 'status' => $status],
                        "Booking status updated to {$status}."
                    );
                    return;

                case 'admin_cancel_booking':
                    $bookingId = (string)$request->input('booking_id', '');
                    if (empty($bookingId)) {
                        $this->jsonError('Missing booking_id.', 400);
                        return;
                    }
                    $db      = Database::get();
                    $booking = $db->getBookingById($bookingId);
                    if (!$booking) {
                        $this->jsonError('Booking not found.', 404);
                        return;
                    }
                    if (($booking['status'] ?? '') === 'cancelled') {
                        $this->jsonError('Booking is already cancelled.', 400);
                        return;
                    }
                    $refunded = false;
                    try {
                        $refunded = $db->declineBookingAtomically(
                            $bookingId,
                            (string)$booking['user_id'],
                            (float)($booking['price'] ?? 0),
                            (string)($booking['payment_method'] ?? ''),
                            (string)($booking['facility_name'] ?? 'Pickleball Facility')
                        );
                    } catch (\Throwable $e) {
                        $this->jsonError('Failed to cancel booking. Please try again.', 500);
                        return;
                    }
                    $this->jsonSuccess([
                        'booking_id' => $bookingId,
                        'status'     => 'cancelled',
                        'refunded'   => $refunded,
                    ], 'Booking cancelled' . ($refunded ? ' and player refunded' : '') . ' successfully.');
                    return;

                // ──────────────────────────────────────────────────────────────
                // Owner Application Management
                // ──────────────────────────────────────────────────────────────

                case 'admin_approve_owner_application':
                    $userId = (string)$request->input('user_id', '');
                    $appId  = (string)$request->input('application_id', '');
                    if (empty($userId)) {
                        $this->jsonError('Missing user_id.', 400);
                        return;
                    }
                    $user = $this->authService->getUserById($userId);
                    if (!$user) {
                        $this->jsonError('User not found.', 404);
                        return;
                    }

                    $db = Database::get();
                    $application = $appId !== '' ? $db->getOwnerApplicationById($appId) : null;
                    if (!$application) {
                        $application = $db->getLatestApplicationForUser($userId);
                    }
                    if (!$application) {
                        $this->jsonError('No application on file for this user — cannot provision a facility without one.', 404);
                        return;
                    }

                    // Provisioning the real facility happens BEFORE any status
                    // changes are committed: if it throws, the applicant must
                    // still see "pending review", not a false "approved" with
                    // no facility behind it.
                    try {
                        $facilityId = $db->createFacilityFromApplication($application);
                    } catch (\Throwable $e) {
                        error_log('[PICKLERS] createFacilityFromApplication failed: ' . $e->getMessage());
                        $this->jsonError('Could not provision the facility. Nothing was changed — please try again.', 500);
                        return;
                    }

                    $this->authService->updateUser($userId, [
                        'is_owner'            => 1,
                        'role'                => 'owner',
                        'verification_status' => 'verified',
                    ]);
                    $db->updateOwnerApplicationStatus((string)$application['id'], 'approved');

                    $this->notificationService->addNotification(
                        $userId,
                        '🎉 You’re live on Picklers!',
                        sprintf(
                            '%s is now listed in Discover Courts. Open the Owner Portal to set your rates, court tags, and photos.',
                            $application['facility_name'] ?? 'Your facility'
                        ),
                        'system'
                    );
                    $this->jsonSuccess(
                        ['user_id' => $userId, 'facility_id' => $facilityId],
                        ($application['facility_name'] ?? 'The facility') . ' has been provisioned and published for ' . ($user['name'] ?? $userId) . '.'
                    );
                    return;

                case 'admin_reject_owner_application':
                    $userId = (string)$request->input('user_id', '');
                    $appId  = (string)$request->input('application_id', '');
                    $reason = trim((string)$request->input('reason', 'Your application did not meet our current requirements.'));
                    if (empty($userId)) {
                        $this->jsonError('Missing user_id.', 400);
                        return;
                    }
                    $user = $this->authService->getUserById($userId);
                    if (!$user) {
                        $this->jsonError('User not found.', 404);
                        return;
                    }
                    $this->authService->updateUser($userId, [
                        'verification_status' => 'unverified',
                    ]);
                    $db = Database::get();
                    $rejectApp = $appId !== '' ? $db->getOwnerApplicationById($appId) : $db->getLatestApplicationForUser($userId);
                    if ($rejectApp) {
                        $db->updateOwnerApplicationStatus((string)$rejectApp['id'], 'rejected');
                    }
                    $this->notificationService->addNotification(
                        $userId,
                        'Owner Application Update',
                        "Your Court Owner application has been reviewed. Unfortunately, it was not approved at this time. Reason: {$reason}. Please contact support for more information.",
                        'system'
                    );
                    $this->jsonSuccess(['user_id' => $userId], 'Application rejected for ' . ($user['name'] ?? $userId) . '.');
                    return;

                // ──────────────────────────────────────────────────────────────
                // Audit & Notifications
                // ──────────────────────────────────────────────────────────────

                case 'admin_broadcast_notification':
                    $title   = trim((string)$request->input('title', ''));
                    $body    = trim((string)$request->input('body', ''));
                    $type    = (string)$request->input('type', 'system');
                    $roleFilter = (string)$request->input('role_filter', 'all'); // 'all', 'player', 'owner'

                    if (empty($title) || empty($body)) {
                        $this->jsonError('Title and body are required.', 400);
                        return;
                    }
                    $allowedTypes = ['system', 'booking', 'wallet', 'community'];
                    if (!in_array($type, $allowedTypes, true)) {
                        $type = 'system';
                    }

                    $users = $this->authService->getAllUsers();
                    $sent  = 0;
                    foreach ($users as $u) {
                        if ($roleFilter !== 'all' && ($u['role'] ?? 'player') !== $roleFilter) {
                            continue;
                        }
                        if (($u['role'] ?? '') === 'deleted') {
                            continue;
                        }
                        $this->notificationService->addNotification($u['id'], $title, $body, $type);
                        $sent++;
                    }

                    $this->jsonSuccess(
                        ['sent_to' => $sent],
                        "Broadcast notification sent to {$sent} user(s)."
                    );
                    return;

                case 'admin_send_notification':
                    $targetId = (string)$request->input('user_id', '');
                    $title    = trim((string)$request->input('title', ''));
                    $body     = trim((string)$request->input('body', ''));
                    $type     = (string)$request->input('type', 'system');

                    if (empty($targetId) || empty($title) || empty($body)) {
                        $this->jsonError('user_id, title, and body are required.', 400);
                        return;
                    }
                    $user = $this->authService->getUserById($targetId);
                    if (!$user) {
                        $this->jsonError('User not found.', 404);
                        return;
                    }
                    $this->notificationService->addNotification($targetId, $title, $body, $type);
                    $this->jsonSuccess(['user_id' => $targetId], 'Notification sent to ' . ($user['name'] ?? $targetId) . '.');
                    return;

                // ──────────────────────────────────────────────────────────────
                // Court & Facility Override
                // ──────────────────────────────────────────────────────────────

                case 'admin_update_court_status':
                    $courtId = $request->input('court_id', 0);
                    $status  = (string)$request->input('status', 'available');

                    $allowedStatuses = ['available', 'occupied', 'maintenance'];
                    if (!in_array($status, $allowedStatuses, true)) {
                        $this->jsonError('Invalid status. Allowed: available, occupied, maintenance.', 400);
                        return;
                    }
                    $db = Database::get();
                    $db->updateCourtStatus($courtId, $status);
                    $this->jsonSuccess(
                        ['court_id' => $courtId, 'status' => $status],
                        "Court status updated to {$status}."
                    );
                    return;

                // ──────────────────────────────────────────────────────────────
                // Default — Unknown Action
                // ──────────────────────────────────────────────────────────────
                default:
                    $this->jsonError('Unknown admin action: ' . htmlspecialchars($action), 400);
                    return;
            }
        } catch (Exception $e) {
            $this->jsonError('An unexpected error occurred: ' . $e->getMessage(), 500);
        }
    }
}
