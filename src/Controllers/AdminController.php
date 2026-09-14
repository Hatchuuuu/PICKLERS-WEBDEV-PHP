<?php
declare(strict_types=1);

namespace Picklers\Controllers;

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

        // Only accepted bookings are revenue; a pending request may still be declined.
        $confirmedBookings = array_filter($bookings, fn($b) => in_array($b['status'] ?? '', ['confirmed', 'completed'], true));
        $grossVolume       = (float)array_sum(array_column($confirmedBookings, 'price'));

        // Map user_id -> user record so pending application cards can show a name/avatar
        // even though the applicant's own submitted name lives on the application row.
        $usersById = [];
        foreach ($users as $u) {
            $usersById[(string)($u['id'] ?? '')] = $u;
        }

        // Promo data is one panel of the console. If that table cannot be read
        // (for example a damaged tablespace), the rest of the console must still
        // load; the failure is logged and the panel renders empty.
        try {
            $promos     = Database::get()->getPromoCodes();
            $promoStats = Database::get()->getPromoStats();
        } catch (\Throwable $e) {
            error_log('[PICKLERS Admin] Promo data unavailable: ' . $e->getMessage());
            $promos     = [];
            $promoStats = ['totalActive' => 0, 'totalRedemptions' => 0, 'totalSavings' => 0.0];
        }

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

    /**
     * Stream one owner-application document (Mayor's permit / government ID)
     * to an administrator.
     *
     * These were stored under public/uploads/permits and linked directly, so
     * anyone who learned (or guessed) a filename could download another
     * person's government ID. They now live outside the web root; this is the
     * only way to read one, and only for a filename that actually belongs to
     * an application on file.
     */
    public function document(Request $request): void {
        AuthMiddleware::requireAdmin();

        $file = (string)$request->query('file', '');
        $notFound = static function (): void {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Document not found.';
        };

        if ($file === '' || $file !== basename($file) || !preg_match('/^[A-Za-z0-9_\-]+\.(pdf|jpe?g|png|webp)$/i', $file)) {
            $notFound();
            return;
        }

        $belongsToApplication = false;
        foreach (Database::get()->getOwnerApplications() as $app) {
            if ($file === (string)($app['permit_file'] ?? '') || $file === (string)($app['gov_id_file'] ?? '')) {
                $belongsToApplication = true;
                break;
            }
        }
        if (!$belongsToApplication) {
            $notFound();
            return;
        }

        $root = dirname(__DIR__, 2);
        $path = null;
        foreach ([OwnerController::DOCUMENT_STORAGE_DIR, OwnerController::LEGACY_DOCUMENT_DIR] as $dir) {
            if (is_file($root . $dir . '/' . $file)) {
                $path = $root . $dir . '/' . $file;
                break;
            }
        }
        if ($path === null) {
            $notFound();
            return;
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
        if (!in_array($mime, OwnerController::DOCUMENT_MIME_TYPES, true)) {
            $notFound();
            return;
        }

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string)filesize($path));
        header('Content-Disposition: inline; filename="' . $file . '"');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
        readfile($path);
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
                    $confirmed = array_filter($bookings, fn($b) => in_array($b['status'] ?? '', ['confirmed', 'completed'], true));
                    $cancelled = count(array_filter($bookings, fn($b) => in_array($b['status'] ?? '', ['cancelled', 'declined'], true)));

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
                    return;
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
                    if (!in_array($type, ['fixed', 'percentage', 'percent'], true)) {
                        $this->jsonError('Invalid discount type.', 400);
                        return;
                    }
                    if (($type === 'percentage' || $type === 'percent') && $value > 100) {
                        $this->jsonError('Percentage discount cannot exceed 100%.', 400);
                        return;
                    }
                    if ($type === 'fixed' && $value > \Picklers\Services\PricingService::MAX_PAYABLE) {
                        $this->jsonError('Fixed discount is above the maximum transaction amount.', 400);
                        return;
                    }
                    // promo_codes.code is VARCHAR(50), and the code is echoed into
                    // admin markup and inline handlers.
                    if ($code !== '' && !preg_match('/^[A-Z0-9_-]{3,50}$/', $code)) {
                        $this->jsonError('Promo codes must be 3–50 letters, numbers, dashes or underscores.', 400);
                        return;
                    }
                    if ($minSpend < 0 || $usageLimit < 0 || $userLimit < 0) {
                        $this->jsonError('Minimum spend and usage limits cannot be negative.', 400);
                        return;
                    }
                    if ($expiresAt !== '' && (($expiresTs = strtotime($expiresAt)) === false || $expiresTs <= time())) {
                        $this->jsonError('Expiry must be a valid future date.', 400);
                        return;
                    }
                    // createPromoCode() upserts on the code, so re-using an
                    // existing code silently rewrote that live promo's terms and
                    // re-activated it.
                    if ($code !== '' && Database::get()->getPromoCode($code) !== null) {
                        $this->jsonError("Promo code {$code} already exists.", 409);
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
                    // An admin demoting themselves could leave the platform with
                    // no administrator at all.
                    if ($targetId === (string)($currentUser['id'] ?? '')) {
                        $this->jsonError('You cannot change your own role. Ask another administrator.', 400);
                        return;
                    }
                    if (!$this->authService->getUserById($targetId)) {
                        $this->jsonError('User not found.', 404);
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
                case 'admin_deactivate_user':
                    $targetId    = (string)$request->input('user_id', '');
                    $confirmText = strtoupper(trim((string)$request->input('confirm_text', '')));
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
                    if ($confirmText === 'DELETE') {
                        $this->authService->deleteUser($targetId);
                        $this->jsonSuccess(['user_id' => $targetId], "User account has been permanently deleted.");
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

                case 'admin_hard_delete_user':
                case 'admin_delete_account':
                    $targetId    = (string)$request->input('user_id', '');
                    $confirmText = strtoupper(trim((string)$request->input('confirm_text', '')));
                    if (empty($targetId)) {
                        $this->jsonError('Missing user_id.', 400);
                        return;
                    }
                    if ($targetId === ($currentUser['id'] ?? '')) {
                        $this->jsonError('You cannot delete your own admin account.', 400);
                        return;
                    }
                    if ($confirmText !== 'DELETE') {
                        $this->jsonError('You must type DELETE to confirm account deletion.', 400);
                        return;
                    }
                    $user = $this->authService->getUserById($targetId);
                    if (!$user) {
                        $this->jsonError('User not found.', 404);
                        return;
                    }
                    $this->authService->deleteUser($targetId);
                    $this->jsonSuccess(['user_id' => $targetId], "User account has been permanently deleted.");
                    return;

                case 'admin_reset_password':
                    $targetId    = (string)$request->input('user_id', '');
                    $newPassword = (string)$request->input('new_password', '');
                    if (empty($targetId)) {
                        $this->jsonError('Missing user_id.', 400);
                        return;
                    }
                    if (($policyError = AuthService::passwordPolicyError($newPassword)) !== null) {
                        $this->jsonError($policyError, 400);
                        return;
                    }
                    if (!$this->authService->getUserById($targetId)) {
                        $this->jsonError('User not found.', 404);
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
                    $title = $title !== '' ? $title : 'Admin Notice 🔔';
                    if (mb_strlen($title) > 150) {
                        $this->jsonError('Notification titles are limited to 150 characters.', 400);
                        return;
                    }
                    if (!$this->authService->getUserById($targetId)) {
                        $this->jsonError('User not found.', 404);
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
                    $reactivateTarget = $this->authService->getUserById($targetId);
                    if (!$reactivateTarget) {
                        $this->jsonError('User not found.', 404);
                        return;
                    }
                    // Reactivation resets the role to player; on an active owner
                    // or admin that was a silent demotion.
                    if (($reactivateTarget['role'] ?? '') !== 'deleted') {
                        $this->jsonError('Only deactivated accounts can be reactivated.', 409);
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
                    if (($target['role'] ?? '') === 'deleted') {
                        $this->jsonError('Deactivated accounts cannot be signed into.', 409);
                        return;
                    }
                    // There is no "return to admin" path: the session simply becomes
                    // the target account's. The message promised otherwise, and an
                    // unused admin_origin_id rode along inside that user's session.
                    unset($_SESSION['user']);
                    AuthMiddleware::login($target['id']);

                    $this->json([
                        'success'  => true,
                        'user'     => $this->sanitizeUser($target),
                        'message'  => 'Now signed in as ' . $target['name'] . '. Sign out and sign back in with your admin account to return.',
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
                    $db = Database::get();
                    $booking = $db->getBookingById($bookingId);
                    if (!$booking) {
                        $this->jsonError('Booking not found.', 404);
                        return;
                    }
                    $current = (string)($booking['status'] ?? '');
                    if ($current === $status) {
                        $this->jsonSuccess(['booking_id' => $bookingId, 'status' => $status], "Booking is already {$status}.");
                        return;
                    }
                    // A cancelled booking was refunded / released its seat when it
                    // was cancelled; flipping it back reinstated the reservation
                    // without charging for it again.
                    if (in_array($current, ['cancelled', 'declined'], true)) {
                        $this->jsonError('A cancelled booking cannot be reinstated. Ask the player to book again.', 409);
                        return;
                    }
                    // Cancelling goes through the same path as a decline so the
                    // player is refunded and any Open Play seat is released.
                    if ($status === 'cancelled') {
                        $refunded = $db->declineBookingAtomically(
                            $bookingId,
                            (string)$booking['user_id'],
                            (float)($booking['price'] ?? 0),
                            (string)($booking['payment_method'] ?? ''),
                            (string)($booking['facility_name'] ?? 'Pickleball Facility')
                        );
                        $this->jsonSuccess(
                            ['booking_id' => $bookingId, 'status' => 'cancelled', 'refunded' => $refunded],
                            'Booking cancelled' . ($refunded ? ' and player refunded.' : '.')
                        );
                        return;
                    }
                    if (!$db->transitionBookingStatus($bookingId, [$current], $status)) {
                        $this->jsonError('This booking changed while you were editing it. Refresh and try again.', 409);
                        return;
                    }
                    // Mirrors approve_booking: an Open Play request becoming
                    // confirmed takes a seat.
                    if ($status === 'confirmed' && !empty($booking['match_id']) && in_array($current, ['pending', 'upcoming'], true)) {
                        $db->adjustMatchPlayerCount((string)$booking['match_id'], 1);
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
                    // The application id and user id were never checked against
                    // each other: approving could elevate one user while the
                    // facility was provisioned for a different applicant.
                    if ((string)($application['user_id'] ?? '') !== $userId) {
                        $this->jsonError('That application does not belong to this user.', 400);
                        return;
                    }
                    if (($application['status'] ?? '') === 'rejected') {
                        $this->jsonError('This application was rejected. The applicant needs to submit a new one.', 409);
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
                    $db = Database::get();
                    $rejectApp = $appId !== '' ? $db->getOwnerApplicationById($appId) : $db->getLatestApplicationForUser($userId);
                    if (!$rejectApp || (string)($rejectApp['user_id'] ?? '') !== $userId) {
                        $this->jsonError('No matching application found for this user.', 404);
                        return;
                    }
                    if (($rejectApp['status'] ?? '') === 'approved') {
                        $this->jsonError('This application has already been approved.', 409);
                        return;
                    }
                    $db->updateOwnerApplicationStatus((string)$rejectApp['id'], 'rejected');
                    // An already-approved owner keeps the verification they earned;
                    // only a pending applicant's review status is cleared.
                    if (empty($user['is_owner'])) {
                        $this->authService->updateUser($userId, [
                            'verification_status' => 'unverified',
                        ]);
                    }
                    $reason = mb_substr($reason, 0, 500);
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

                // ──────────────────────────────────────────────────────────────
                // Court & Facility Override
                // ──────────────────────────────────────────────────────────────

                case 'admin_update_court_status':
                    $courtId = trim((string)$request->input('court_id', ''));
                    $status  = (string)$request->input('status', 'available');
                    if ($courtId === '' || !Database::get()->getCourtById($courtId)) {
                        $this->jsonError('Court not found.', 404);
                        return;
                    }

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
        } catch (\Throwable $e) {
            error_log(sprintf('[PICKLERS Admin] action=%s failed: %s in %s:%d', $action, $e->getMessage(), $e->getFile(), $e->getLine()));
            $debug = filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $this->jsonError($debug ? 'An unexpected error occurred: ' . $e->getMessage() : 'An unexpected error occurred. Nothing was changed — please try again.', 500);
        }
    }
}
