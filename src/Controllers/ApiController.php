<?php
declare(strict_types=1);

namespace Picklers\Controllers;

use Picklers\Core\Request;
use Picklers\Core\Response;
use Picklers\Services\AuthService;
use Picklers\Services\BookingService;
use Picklers\Services\CommunityService;
use Picklers\Services\FacilityService;
use Picklers\Services\MatchService;
use Picklers\Services\NotificationService;
use Picklers\Services\PricingService;
use Picklers\Services\WalletService;

class ApiController extends BaseController {

    /** Payment methods the platform accepts. Anything else is rejected outright. */
    public const ALLOWED_PAYMENT_METHODS = ['Pickle Credits', 'GCash', 'Maya', 'Card', 'Pay at Venue'];

    /**
     * Labels the checkout UI sends for an accepted method. The player app's
     * "Cash on Site" option was rejected as unsupported, so every cash court
     * booking and cash Open Play join failed.
     */
    public const PAYMENT_METHOD_ALIASES = ['Cash on Site' => 'Pay at Venue'];

    /** Ceiling for a stored avatar. A 256px re-encode lands well under this. */
    public const MAX_AVATAR_BYTES = 200000;

    private AuthService $authService;
    private FacilityService $facilityService;
    private BookingService $bookingService;
    private WalletService $walletService;
    private CommunityService $communityService;
    private MatchService $matchService;
    private NotificationService $notificationService;
    private PricingService $pricingService;

    public function __construct(
        ?AuthService $authService = null,
        ?FacilityService $facilityService = null,
        ?BookingService $bookingService = null,
        ?WalletService $walletService = null,
        ?CommunityService $communityService = null,
        ?MatchService $matchService = null,
        ?NotificationService $notificationService = null,
        ?PricingService $pricingService = null
    ) {
        $this->authService = $authService ?? new AuthService();
        $this->facilityService = $facilityService ?? new FacilityService();
        $this->bookingService = $bookingService ?? new BookingService();
        $this->walletService = $walletService ?? new WalletService();
        $this->communityService = $communityService ?? new CommunityService();
        $this->matchService = $matchService ?? new MatchService();
        $this->notificationService = $notificationService ?? new NotificationService();
        $this->pricingService = $pricingService ?? new PricingService();
    }

    /**
     * Simulated (gateway-less) money movement is only permitted outside production.
     * Set PAYMENTS_SIMULATED=true in .env to keep the demo top-up flow working locally.
     */
    private function simulatedPaymentsAllowed(): bool {
        $env = strtolower((string)($_ENV['APP_ENV'] ?? 'production'));
        if (in_array($env, ['production', 'prod', 'live'], true)) {
            return false;
        }
        return filter_var($_ENV['PAYMENTS_SIMULATED'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    private ?\Picklers\Services\TournamentService $tournamentServiceInstance = null;

    private function tournamentService(): \Picklers\Services\TournamentService {
        return $this->tournamentServiceInstance ??= new \Picklers\Services\TournamentService();
    }

    /** Owners host tournaments; admins may act on any of them for support. */
    private function isOwnerAccount(?array $user): bool {
        return $user !== null && (!empty($user['is_owner']) || !empty($user['is_admin']));
    }

    /**
     * The player app's Support chat addresses the reserved id 'usr_admin',
     * which no account holds in a provisioned database, so support messages
     * were stored against nobody and never read. Route that reserved id to a
     * real, active administrator when one exists.
     */
    private function resolveMessagePartnerId(string $partnerId): string {
        $partnerId = trim($partnerId);
        if ($partnerId !== 'usr_admin' || $this->authService->getUserById('usr_admin')) {
            return $partnerId;
        }
        foreach ($this->authService->getAllUsers() as $u) {
            if (!empty($u['is_admin']) && ($u['role'] ?? '') !== 'deleted') {
                return (string)$u['id'];
            }
        }
        return $partnerId;
    }

    /**
     * Facilities this account actually holds. Admins see all of them, which is
     * what makes admin support possible without a separate code path.
     *
     * @return array<int,array<string,mixed>>
     */
    private function ownerFacilities(?array $user): array {
        if ($user === null) {
            return [];
        }
        $all = $this->facilityService->getFacilities();
        if (!empty($user['is_admin'])) {
            return $all;
        }
        return array_values(array_filter(
            $all,
            fn($f) => isset($f['owner_id']) && (string)$f['owner_id'] === (string)$user['id']
        ));
    }

    /**
     * One facility to act on: the requested one when the caller holds it,
     * otherwise their only/first one. Never invents an id.
     */
    private function ownerFacility(?array $user, string $requestedId = ''): ?array {
        $facilities = $this->ownerFacilities($user);
        if ($facilities === []) {
            return null;
        }
        if ($requestedId !== '') {
            foreach ($facilities as $f) {
                if ((string)$f['id'] === $requestedId) {
                    return $f;
                }
            }
            return null; // asked for a facility this account does not hold
        }
        return $facilities[0];
    }

    /**
     * Load a tournament only if this account may act on it.
     *
     * A tournament belonging to someone else returns the same "not found" as one
     * that does not exist, so the endpoint never confirms another venue's ids.
     *
     * @return array{tournament:?array,error:?string,status:int}
     */
    private function requireOwnedTournament(?array $user, string $tournamentId): array {
        if (!$this->isOwnerAccount($user)) {
            return ['tournament' => null, 'error' => 'Unauthorized: owner access required.', 'status' => 403];
        }
        if ($tournamentId === '') {
            return ['tournament' => null, 'error' => 'Tournament id is required.', 'status' => 400];
        }

        $tournament = $this->tournamentService()->find($tournamentId);
        if ($tournament === null) {
            return ['tournament' => null, 'error' => 'Tournament not found.', 'status' => 404];
        }

        if (!empty($user['is_admin'])) {
            return ['tournament' => $tournament, 'error' => null, 'status' => 200];
        }

        foreach ($this->ownerFacilities($user) as $facility) {
            if ((string)$facility['id'] === (string)$tournament['facility_id']) {
                return ['tournament' => $tournament, 'error' => null, 'status' => 200];
            }
        }

        return ['tournament' => null, 'error' => 'Tournament not found.', 'status' => 404];
    }

    public function handle(Request $request) {
        $action = (string)($request->query('action') ?? $request->input('action', ''));
        $currentUser = $this->currentUser();
        $csrfToken = $this->csrfToken();

        // CSRF guard is action-based, not method-based, so a crafted GET URL
        // cannot trigger a mutating action without a valid token.
        static $csrfMutatingActions = [
            'book_court', 'join_match', 'cancel_booking', 'top_up',
            'approve_booking', 'decline_booking', 'update_court_status', 'owner_update_court',
            'change_password', 'update_profile', 'verify_identity', 'delete_own_account', 'switch_user',
            'mark_notifications_read', 'delete_notification',
            'create_post', 'send_message', 'like_post', 'add_comment', 'toggle_favorite_facility',
            'create_tournament', 'update_tournament', 'delete_tournament',
            'add_tournament_team', 'add_tournament_player', 'add_tournament_entrant',
            'update_tournament_team', 'remove_tournament_team', 'remove_tournament_player',
            'mix_tournament_teams', 'generate_tournament_bracket', 'reset_tournament_bracket',
            'report_tournament_match', 'reset_tournament_match',
            'admin_update_role', 'admin_toggle_verify',
            'admin_approve_owner_application', 'admin_reject_owner_application',
        ];
        $isMutating = in_array(strtoupper($request->getMethod()), ['POST', 'PUT', 'PATCH', 'DELETE'], true)
                   || in_array($action, $csrfMutatingActions, true);
        if ($isMutating && !$this->validateCsrf($request)) {
            return $this->jsonError('CSRF security verification failed.', 403);
        }

        // Deliberately excludes report_tournament_match and the add_tournament_*
        // actions: an owner running a 32-team draw legitimately fires those in
        // bursts (62 matches, 32 registrations), and throttling them would stall
        // a live event. They are already gated by auth, CSRF and per-tournament
        // ownership, and they only write to that owner's own record.
        $mutatingRateLimitedActions = [
            'book_court', 'join_match', 'cancel_booking',
            'create_post', 'send_message', 'like_post', 'add_comment',
            'top_up', 'change_password', 'update_profile',
            'verify_identity', 'delete_own_account',
            'create_tournament', 'update_tournament', 'delete_tournament',
            'generate_tournament_bracket', 'mix_tournament_teams',
            'add_tournament_team', 'add_tournament_player', 'add_tournament_entrant',
            'report_tournament_match',
        ];
        if (in_array($action, $mutatingRateLimitedActions, true)) {
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $identity = ($currentUser['id'] ?? 'guest') . '|' . $ip;
            if (\Picklers\Middleware\RateLimitMiddleware::tooMany("api.$action", 20, 60, $identity)) {
                return $this->jsonError('Too many requests. Please slow down.', 429);
            }
        }

        try {
            switch ($action) {
                // ----------------------------------------------------------------------
                // Auth & User Profile
                // ----------------------------------------------------------------------
                case 'me':
                    return $this->json([
                        'success' => true,
                        'user' => $currentUser,
                        'csrf_token' => $csrfToken,
                        'notifications' => $currentUser ? $this->notificationService->getNotifications($currentUser['id']) : []
                    ]);

                case 'change_password':
                    if (!$currentUser) {
                        return $this->jsonError('Unauthorized: Please log in to change your password.', 401);
                    }
                    $currentPass = (string)$request->input('current_password', '');
                    $newPass = (string)$request->input('new_password', '');
                    $confirmPass = (string)$request->input('confirm_password', '');

                    if ($newPass === '') {
                        return $this->jsonError('Please enter a new password.', 400);
                    }
                    if ($confirmPass !== '' && $newPass !== $confirmPass) {
                        return $this->jsonError('New passwords do not match. Please re-check.', 400);
                    }

                    $changeRes = $this->authService->changePassword($currentUser['id'], $currentPass, $newPass);
                    if (!$changeRes['success']) {
                        return $this->jsonError($changeRes['error'] ?? 'Failed to update password.', 400);
                    }

                    return $this->json([
                        'success' => true,
                        'message' => 'Password updated successfully!'
                    ]);

                case 'sync':
                    // A cheap poll target: a client compares these version
                    // numbers against what it already has and only re-fetches
                    // the actual facilities/courts/bookings list when one has
                    // moved — the same perceived immediacy as a push channel
                    // (an owner listing a court reaches Discover, a player's
                    // booking reaches the owner's queue) without holding a
                    // persistent connection open per browser tab.
                    $syncPayload = [
                        'success'     => true,
                        'versions'    => \Picklers\Core\Database::get()->getSyncVersions(),
                        'server_time' => time(),
                    ];
                    if ($currentUser) {
                        // Real-time "your time is up" alert: checked on this same
                        // poll a logged-in player is already making every ~12s
                        // (see PickSync in ux-core.js), so a booking that just
                        // ended surfaces within seconds without a server cron —
                        // see Database::checkAndNotifySessionEnd()'s doc comment.
                        $syncPayload['ended_sessions'] = \Picklers\Core\Database::get()->checkAndNotifySessionEnd((string)$currentUser['id']);

                        $unread = $this->notificationService->getNotifications($currentUser['id']);
                        $syncPayload['unread_notifications'] = count(array_filter($unread, fn($n) => empty($n['is_read'])));
                        // This player's own bookings and wallet, so an owner accepting or
                        // declining a request (or a refund) reaches their screen live.
                        $syncPayload['account'] = \Picklers\Core\Database::get()->getAccountSyncState((string)$currentUser['id']);
                    }
                    return $this->json($syncPayload);

                case 'get_open_play_roster':
                    if (!$this->isOwnerAccount($currentUser)) {
                        return $this->jsonError('Owner access required.', 403);
                    }
                    $courtName = trim((string)($request->query('court_name') ?? $request->input('court_name', '')));
                    $courtId = trim((string)($request->query('court_id') ?? $request->input('court_id', '')));
                    $facilityId = trim((string)($request->query('facility_id') ?? $request->input('facility_id', '')));
                    $facility = $this->ownerFacility($currentUser, $facilityId);
                    if ($facility === null) {
                        return $this->jsonError('No facility context found.', 403);
                    }
                    $roster = \Picklers\Core\Database::get()->getOpenPlayRoster($facility['id'], $courtName, $courtId);
                    return $this->json([
                        'success' => true,
                        'roster' => $roster,
                        'court_name' => $courtName,
                        'data' => ['roster' => $roster]
                    ]);

                case 'get_pending_requests':
                    // Backs the owner Dashboard's live "Requests" queue refresh:
                    // PickSync notices the 'bookings' version moved and re-fetches
                    // this instead of just telling the owner to reload the page.
                    if (!$this->isOwnerAccount($currentUser)) {
                        return $this->jsonError('Owner access required.', 403);
                    }
                    $facilityId = trim((string)($request->query('facility_id') ?? $request->input('facility_id', '')));
                    $facility = $this->ownerFacility($currentUser, $facilityId);
                    if ($facility === null) {
                        return $this->jsonError('No facility context found.', 403);
                    }
                    $pendingRequests = \Picklers\Core\Database::get()->getPendingBookingRequests($facility['id']);
                    return $this->json([
                        'success' => true,
                        'requests' => $pendingRequests,
                    ]);

                case 'verify_checkin':
                    // Looks the scanned code up against a real booking and only
                    // reports success for one that belongs to this facility, is
                    // confirmed, and is for today's session.
                    if (!$this->isOwnerAccount($currentUser)) {
                        return $this->jsonError('Owner access required.', 403);
                    }
                    $rawCode = trim((string)($request->query('code') ?? $request->input('code', '')));
                    if ($rawCode === '') {
                        return $this->jsonError('No code provided.', 400);
                    }
                    // Accept either the raw booking id, or the "PICKLERS:<id>:..."
                    // payload embedded in the receipt's own QR code.
                    $scanBookingId = $rawCode;
                    if (str_starts_with($rawCode, 'PICKLERS:')) {
                        $scanParts = explode(':', $rawCode);
                        $scanBookingId = $scanParts[1] ?? '';
                    }
                    $scanBookingId = trim($scanBookingId);
                    if ($scanBookingId === '') {
                        return $this->jsonError('Unrecognized code format.', 400);
                    }

                    $db = \Picklers\Core\Database::get();
                    $scanBooking = $db->getBookingById($scanBookingId);
                    if (!$scanBooking) {
                        return $this->jsonError('No booking found for this code.', 404);
                    }
                    if (empty($currentUser['is_admin']) && !$db->verifyBookingOwner($scanBookingId, $currentUser['id'])) {
                        return $this->jsonError('This pass is not for a booking at your facility.', 403);
                    }

                    $scanPlayer = $db->getUserById((string)($scanBooking['user_id'] ?? ''));
                    $scanDetails = [
                        'booking_id' => $scanBookingId,
                        'player_name' => $scanPlayer['name'] ?? ($scanBooking['author_name'] ?? 'Registered Player'),
                        'court_name' => (string)($scanBooking['court_name'] ?? 'Court'),
                        'date' => (string)($scanBooking['date'] ?? ''),
                        'time' => (string)($scanBooking['time'] ?? ''),
                        'status' => (string)($scanBooking['status'] ?? ''),
                    ];

                    $scanStatus = (string)($scanBooking['status'] ?? '');
                    if ($scanStatus !== 'confirmed') {
                        $reason = $scanStatus === 'pending'
                            ? 'This reservation is still pending owner approval — it has not been confirmed.'
                            : 'This reservation is ' . $scanStatus . " — it can't be checked in.";
                        return $this->json(['success' => false, 'message' => $reason, 'booking' => $scanDetails], 409);
                    }

                    // A confirmed pass is only valid on the day of its own session.
                    if ($db->isBookingPast($scanBooking)) {
                        return $this->json(['success' => false, 'message' => 'This pass has expired — the session has already ended.', 'booking' => $scanDetails], 409);
                    }
                    $scanDay = (string)($scanBooking['booking_date'] ?? '');
                    if ($scanDay !== '' && $scanDay > date('Y-m-d')) {
                        return $this->json(['success' => false, 'message' => 'This pass is for ' . date('M j, Y', (int)strtotime($scanDay)) . ', not today.', 'booking' => $scanDetails], 409);
                    }
                    return $this->json(['success' => true, 'message' => 'Booking verified.', 'booking' => $scanDetails]);

                case 'mark_notifications_read':
                    if (!$currentUser) {
                        return $this->jsonError('Unauthorized', 401);
                    }
                    $this->notificationService->markAllAsRead($currentUser['id']);
                    return $this->json(['success' => true, 'message' => 'All notifications marked as read']);

                case 'delete_notification':
                    if (!$currentUser) {
                        return $this->jsonError('Unauthorized', 401);
                    }
                    $notifId = (string)$request->input('id', '');
                    if ($notifId) {
                        $this->notificationService->deleteNotification($notifId, $currentUser['id']);
                    }
                    return $this->json(['success' => true, 'message' => 'Notification removed']);

                case 'update_profile':
                    if (!$currentUser) {
                        return $this->jsonError('Unauthorized', 401);
                    }
                    $name = trim((string)$request->input('name', ''));
                    $email = trim((string)$request->input('email', ''));
                    $phone = trim((string)$request->input('phone', ''));
                    // One stored form per number, so sign-in and the uniqueness
                    // check below recognise it however it was typed.
                    if ($phone !== '') {
                        $phone = \Picklers\Helpers\Format::phMobile($phone) ?? $phone;
                    }
                    $level = trim((string)$request->input('level', ''));
                    // users.name/phone/level are VARCHAR(120/32/20): under
                    // STRICT_TRANS_TABLES an over-long value is a hard SQL error.
                    if ($name !== '' && (mb_strlen($name) > 120 || preg_match('/[<>]/', $name))) {
                        return $this->jsonError('Please enter a valid name (up to 120 characters, no < or >).', 400);
                    }
                    if ($level !== '' && (mb_strlen($level) > 20 || preg_match('/[<>]/', $level))) {
                        return $this->jsonError('Please choose a valid skill level.', 400);
                    }
                    if ($phone !== '' && $phone !== (string)($currentUser['phone'] ?? '')) {
                        if (strlen($phone) > 32 || !preg_match('/^[0-9+()\-\s]{7,32}$/', $phone)) {
                            return $this->jsonError('Please enter a valid mobile number.', 400);
                        }
                        // Phone is a sign-in identifier: it must stay unique.
                        $phoneOwner = $this->authService->getUserByEmailOrPhone($phone);
                        if ($phoneOwner && (string)$phoneOwner['id'] !== (string)$currentUser['id']) {
                            return $this->jsonError('This mobile number is already linked to another account.', 400);
                        }
                    }
                    $fields = [];
                    if (!empty($name)) $fields['name'] = $name;
                    if (!empty($phone)) $fields['phone'] = $phone;
                    if (!empty($level)) $fields['level'] = $level;
                    if (!empty($email) && $email !== ($currentUser['email'] ?? '')) {
                        if (strlen($email) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                            return $this->jsonError('Please enter a valid email address', 400);
                        }
                        $existing = $this->authService->getUserByEmailOrPhone($email);
                        if ($existing && $existing['id'] !== $currentUser['id']) {
                            return $this->jsonError('An account with this email address already exists', 400);
                        }
                        $fields['email'] = $email;
                    }
                    $avatarUrl = $request->input('avatar_url');
                    if ($avatarUrl !== null && !empty(trim((string)$avatarUrl))) {
                        $avatarUrl = trim((string)$avatarUrl);

                        // Avatars were stored verbatim, so a multi-megabyte data URL
                        // landed in the users table and was then inlined into every
                        // page rendering that user. Cap the size and restrict the
                        // accepted shapes to an http(s) URL or a small image data URL.
                        if (strlen($avatarUrl) > self::MAX_AVATAR_BYTES) {
                            return $this->jsonError(
                                'That image is too large. Please choose a smaller photo.', 413
                            );
                        }

                        $isHttp = (bool)preg_match('#^https?://#i', $avatarUrl);
                        $isData = (bool)preg_match('#^data:image/(png|jpe?g|webp|gif);base64,#i', $avatarUrl);
                        if (!$isHttp && !$isData) {
                            return $this->jsonError('Unsupported image format.', 400);
                        }

                        $fields['avatar_url'] = $avatarUrl;
                    }

                    $updated = $this->authService->updateUser($currentUser['id'], $fields);
                    return $this->json(['success' => true, 'user' => $this->sanitizeUser($updated), 'message' => 'Profile updated successfully!']);

                case 'verify_identity':
                    if (!$currentUser) {
                        return $this->jsonError('Unauthorized', 401);
                    }
                    $currentStatus = (string)($currentUser['verification_status'] ?? 'unverified');
                    if ($currentStatus === 'verified') {
                        return $this->json([
                            'success' => true,
                            'status'  => 'verified',
                            'user'    => $currentUser,
                            'message' => 'Your identity is already verified.',
                        ]);
                    }
                    if ($currentStatus === 'pending_review') {
                        return $this->json([
                            'success' => true,
                            'status'  => 'pending_review',
                            'user'    => $currentUser,
                            'message' => 'Your verification is already under review. We will notify you once it is approved.',
                        ]);
                    }

                    // A user may REQUEST verification; only an administrator can grant it.
                    // Self-granting 'verified' here was a trust-badge escalation.
                    $updated = $this->authService->updateUser($currentUser['id'], [
                        'verification_status' => 'pending_review',
                    ]);
                    $this->notificationService->addNotification(
                        $currentUser['id'],
                        'Verification Requested 🛡️',
                        'Thanks! Your identity verification request has been submitted. Our team will review it shortly.',
                        'system'
                    );
                    return $this->json([
                        'success' => true,
                        'status'  => 'pending_review',
                        'user'    => $this->sanitizeUser($updated),
                        'message' => 'Verification request submitted — our team will review it shortly.',
                    ]);

                case 'switch_user':
                    if (!$currentUser || empty($currentUser['is_admin'])) {
                        return $this->jsonError('Unauthorized: Admin access required', 403);
                    }
                    $targetId = (string)$request->input('user_id', '');
                    $target = $this->authService->getUserById($targetId);
                    if ($target && ($target['role'] ?? '') !== 'deleted') {
                        unset($_SESSION['user']);
                        \Picklers\Middleware\AuthMiddleware::login($target['id']);
                        // The raw row (password hash included) was returned here.
                        return $this->json(['success' => true, 'user' => $this->sanitizeUser($target), 'message' => 'Switched to ' . $target['name']]);
                    }
                    return $this->jsonError('User not found', 404);

                case 'delete_own_account':
                    // A soft-delete (the same shape admin_delete_user uses), so
                    // financial records referenced by FK constraints survive.
                    if (!$currentUser) {
                        return $this->jsonError('Unauthorized', 401);
                    }
                    $typed = trim((string)$request->input('confirm', ''));
                    if ($typed !== 'DELETE') {
                        return $this->jsonError('Type DELETE in capitals to confirm.', 400);
                    }
                    if (!empty($currentUser['is_admin'])) {
                        return $this->jsonError(
                            'Administrator accounts cannot be self-deleted. Contact another administrator.', 403
                        );
                    }
                    // The facility listing, its bookings and payouts would stay
                    // attached to an account nobody can sign in to anymore.
                    if (!empty($currentUser['is_owner'])) {
                        return $this->jsonError(
                            'Facility owner accounts cannot be deleted from the app while a facility is listed. Please contact Picklers support.', 403
                        );
                    }

                    $selfId = (string)$currentUser['id'];
                    // Release the courts this account still holds: a deactivated
                    // account's pending/confirmed bookings kept those slots blocked
                    // for everyone. The normal cancellation policy (and refunds) applies.
                    $selfDb = \Picklers\Core\Database::get();
                    foreach ($this->bookingService->getBookings($selfId) as $held) {
                        if (in_array((string)($held['status'] ?? ''), ['pending', 'confirmed'], true) && !$selfDb->isBookingPast($held)) {
                            $this->bookingService->cancelBooking((string)$held['id'], $selfId);
                        }
                    }
                    $this->authService->updateUser($selfId, [
                        'role'                => 'deleted',
                        'email'               => 'deleted_' . $selfId . '@picklers.invalid',
                        'is_admin'            => 0,
                        'is_owner'            => 0,
                        'verification_status' => 'unverified',
                    ]);
                    \Picklers\Middleware\AuthMiddleware::logout();

                    return $this->jsonSuccess(
                        ['redirect' => \Picklers\Helpers\Url::to('auth.php?logout=1')],
                        'Your account has been deactivated. Signing you out.'
                    );
                case 'logout':
                    \Picklers\Middleware\AuthMiddleware::logout();
                    return $this->jsonSuccess([], 'Logged out successfully');

                // ----------------------------------------------------------------------
                // Facilities & Courts
                // ----------------------------------------------------------------------
                case 'facilities':
                    $search = (string)$request->query('search', '');
                    $type = (string)$request->query('type', 'All');
                    $sort = (string)$request->query('sort', 'recommended');
                    $facilities = $this->facilityService->getFacilities($search, $type, $sort);
                    if ($currentUser) {
                        // So a client-side re-render (silentRefreshDiscover(),
                        // triggered by PickSync on a 'facilities'/'courts'
                        // change) still shows this player's real favorited
                        // hearts filled in, not just the ones on-screen when
                        // the page first loaded.
                        $favIds = \Picklers\Core\Database::get()->getFavoriteFacilityIds((string)$currentUser['id']);
                        foreach ($facilities as &$favFac) {
                            $favFac['is_favorited'] = in_array((int)($favFac['id'] ?? 0), $favIds, true);
                        }
                        unset($favFac);
                    }
                    return $this->json(['success' => true, 'facilities' => $facilities]);

                case 'facility_detail':
                    $idRaw = $request->query('id', '');
                    $id = ctype_digit((string)$idRaw) ? (int)$idRaw : (string)$idRaw;
                    $facility = $this->facilityService->getFacility($id);
                    if (!$facility) {
                        return $this->jsonError('Facility not found', 404);
                    }
                    // getCourtsWithAttributes() so each court carries its own
                    // real Indoor/Outdoor/Covered/Air Conditioned tags (F-17)
                    // rather than only the facility-wide amenities list below.
                    $courts = $this->facilityService->getCourtsWithAttributes($id);
                    $images = $this->facilityService->getCourtImagesByFacility($id);
                    $amenities = $this->facilityService->getFacilityAmenities($id);

                    // Check if current user has joined any open play sessions at this facility
                    if ($currentUser && !empty($currentUser['id'])) {
                        $userId = (string)$currentUser['id'];
                        $db = \Picklers\Core\Database::get();
                        $myBookings = $db->getBookings($userId);
                        $joinedMatchMap = [];
                        foreach ($myBookings as $mb) {
                            if (in_array($mb['status'] ?? '', ['pending', 'confirmed'], true)) {
                                $bId = (string)($mb['id'] ?? '');
                                $bCourtName = (string)($mb['court_name'] ?? '');
                                $bLabel = (string)($mb['label'] ?? '');
                                $bFacId = (string)($mb['facility_id'] ?? '');

                                if (!empty($mb['match_id'])) {
                                    $joinedMatchMap[(string)$mb['match_id']] = true;
                                }

                                $isOPBooking = str_starts_with($bId, 'PKL-OP-')
                                            || stripos($bCourtName, 'open play') !== false
                                            || stripos($bLabel, 'open play') !== false
                                            || !empty($mb['match_id']);

                                if ($isOPBooking && $bFacId !== '') {
                                    $joinedMatchMap["fac_{$bFacId}"] = true;
                                }

                                $key = $bFacId . '|' . $bCourtName . '|' . (string)($mb['time'] ?? '');
                                $joinedMatchMap[$key] = true;
                            }
                        }
                        foreach ($courts as &$c) {
                            $cFacId = (string)($c['facility_id'] ?? $id);
                            $cName = (string)($c['name'] ?? '');
                            $isOPCourt = !empty($c['has_open_play'])
                                      || (!empty($c['occupied_by']) && (stripos($c['occupied_by'], 'open play') !== false || stripos($c['occupied_by'], 'hosted') !== false))
                                      || !empty($c['open_play_match']);

                            if ($isOPCourt) {
                                $op = $c['open_play_match'] ?? [];
                                $opId = (string)($op['id'] ?? '');
                                $opKey = $cFacId . '|' . (string)($op['type'] ?? $cName) . '|' . (string)($op['time'] ?? '');

                                if ((!empty($opId) && isset($joinedMatchMap[$opId]))
                                    || isset($joinedMatchMap[$opKey])
                                    || isset($joinedMatchMap["fac_{$cFacId}"])
                                ) {
                                    $c['user_joined'] = true;
                                    if (!empty($c['open_play_match'])) {
                                        $c['open_play_match']['is_joined'] = true;
                                        $c['open_play_match']['user_joined'] = true;
                                    }
                                }
                            }
                        }
                        unset($c);
                    }

                    return $this->json([
                        'success' => true,
                        'facility' => $facility,
                        'courts' => $courts,
                        'images' => $images,
                        'amenities' => $amenities
                    ]);

                case 'slot_availability':
                    $facIdRaw = $request->query('facility_id', $request->query('id', ''));
                    $facilityId = ctype_digit((string)$facIdRaw) ? (int)$facIdRaw : (string)$facIdRaw;
                    $courtId = trim((string)$request->query('court_id', ''));
                    if ($courtId === '') {
                        return $this->jsonError('court_id is required.', 400);
                    }
                    // The checkout sends display dates ("Sun, Sep 14, 2026") but
                    // availability is keyed on the canonical Y-m-d booking_date:
                    // the raw string never matched, so every slot read as free and
                    // the "already booked" warning never appeared.
                    $slotDateTs = strtotime((string)$request->query('date', ''));
                    $dateStr = $slotDateTs !== false ? date('Y-m-d', $slotDateTs) : date('Y-m-d');
                    $slots = $this->bookingService->getSlotAvailability($facilityId, $courtId, $dateStr);
                    return $this->json(['success' => true, 'slots' => $slots]);

                // ----------------------------------------------------------------------
                // Open Play Matches (Explore Tab)
                // ----------------------------------------------------------------------
                case 'my_tournaments':
                    if (!$currentUser) {
                        return $this->jsonError('Authentication required.', 401);
                    }
                    // Entries belong to THIS account: by user id wherever the
                    // roster recorded one, otherwise by exact full name.
                    // Substring matching showed a player named "Al" every
                    // tournament containing an "Alex", "Alan" or "Salvador".
                    $myId = (string)$currentUser['id'];
                    $playerName = strtolower(trim((string)($currentUser['name'] ?? '')));
                    $isMe = static function (string $entryUserId, string $entryName) use ($myId, $playerName): bool {
                        if ($entryUserId !== '') {
                            return $entryUserId === $myId;
                        }
                        return $playerName !== '' && strtolower(trim($entryName)) === $playerName;
                    };
                    $myTs = [];
                    foreach ($this->tournamentService()->all() as $t) {
                        $found = false;
                        foreach ((array)($t['teams'] ?? []) as $team) {
                            if ($isMe((string)($team['player1_id'] ?? ''), (string)($team['player1'] ?? ''))
                                || $isMe((string)($team['player2_id'] ?? ''), (string)($team['player2'] ?? ''))) {
                                $found = true; break;
                            }
                        }
                        if (!$found) {
                            foreach ((array)($t['players_pool'] ?? []) as $player) {
                                if ($isMe((string)($player['user_id'] ?? ''), (string)($player['name'] ?? ''))) {
                                    $found = true; break;
                                }
                            }
                        }
                        if ($found) {
                            // Other entrants' contact details are the host's, not this player's.
                            $t['players_pool'] = array_map(static function ($p) {
                                unset($p['email']);
                                return $p;
                            }, (array)($t['players_pool'] ?? []));
                            $myTs[] = $t;
                        }
                    }
                    return $this->jsonSuccess(['tournaments' => $myTs]);

                case 'matches':
                    $level = (string)$request->query('level', 'All');
                    $facilitySearch = (string)$request->query('facility', '');
                    $matches = $this->matchService->getMatches($level, $facilitySearch);
                    return $this->json(['success' => true, 'matches' => $matches]);

                case 'join_match':
                    if (!$currentUser) {
                        return $this->jsonError('Please log in to join Open Play', 401);
                    }
                    $matchId = (string)$request->input('match_id', '');
                    $paymentMethod = (string)$request->input('payment_method', 'GCash');
                    $paymentMethod = self::PAYMENT_METHOD_ALIASES[$paymentMethod] ?? $paymentMethod;
                    if (!in_array($paymentMethod, self::ALLOWED_PAYMENT_METHODS, true)) {
                        return $this->jsonError('Unsupported payment method.', 400);
                    }
                    // The entry fee is resolved server-side from the match record.
                    // A client-supplied price is never trusted.
                    $promoCode = trim((string)$request->input('promo_code', ''));
                    $res = $this->matchService->joinMatch(
                        $matchId,
                        $currentUser['id'],
                        $paymentMethod,
                        $promoCode !== '' ? $promoCode : null
                    );
                    return $this->json($res);

                // ----------------------------------------------------------------------
                // Bookings
                // ----------------------------------------------------------------------
                case 'book_court':
                    if (!$currentUser) {
                        return $this->jsonError('Please log in to book a court', 401);
                    }
                    $facIdRaw = $request->input('facility_id', '');
                    $facilityId = ctype_digit((string)$facIdRaw) ? (int)$facIdRaw : (string)$facIdRaw;
                    // court_id is the authoritative identity (it comes from this
                    // facility's own courts list). court_name is only a fallback for
                    // callers that do not send an id.
                    $courtId   = trim((string)$request->input('court_id', ''));
                    $courtName = (string)$request->input('court_name', 'Court 1');
                    // No defaults: a booking without an explicit date and time is rejected.
                    $date = trim((string)$request->input('date', ''));
                    $time = trim((string)$request->input('time', ''));
                    $paymentMethod = (string)$request->input('payment_method', 'Pickle Credits');
                    $paymentMethod = self::PAYMENT_METHOD_ALIASES[$paymentMethod] ?? $paymentMethod;
                    if (!in_array($paymentMethod, self::ALLOWED_PAYMENT_METHODS, true)) {
                        return $this->jsonError('Unsupported payment method.', 400);
                    }
                    if ($date === '' || $time === '') {
                        return $this->jsonError('Please choose a date and time slot.', 400);
                    }

                    // Price is computed server-side from the court's published rate.
                    // Anything the client sends as `price` is deliberately ignored.
                    $duration  = $this->pricingService->normalizeDuration($request->input('duration', 1));

                    // The court is held for the slot's real length, so that is
                    // what must be paid for. Pricing trusted `duration` alone,
                    // so "7:00 AM - 3:00 PM" sent with duration=1 reserved eight
                    // hours for the price of one.
                    $slotRange = \Picklers\Core\Database::get()->parseTimeRange($time);
                    if ($slotRange === null) {
                        return $this->jsonError('Please choose a valid time slot.', 400);
                    }
                    $slotMinutes = $slotRange[1] - $slotRange[0];
                    if ($slotMinutes % 60 !== 0 || intdiv($slotMinutes, 60) !== $duration) {
                        return $this->jsonError('The selected time slot does not match the booking duration. Please reselect your time.', 400);
                    }
                    $promoCode = trim((string)$request->input('promo_code', ''));
                    $quote     = $this->pricingService->quoteCourtBooking(
                        $facilityId,
                        $courtName,
                        $duration,
                        $promoCode !== '' ? $promoCode : null,
                        $currentUser['id'],
                        $courtId
                    );
                    if (empty($quote['success'])) {
                        return $this->jsonError((string)($quote['message'] ?? 'Unable to price this booking.'), 400);
                    }

                    // The promo redemption is recorded inside createBooking()'s own
                    // transaction, so a discount is never granted without being counted.
                    $res = $this->bookingService->createBooking(
                        $currentUser['id'], $facilityId, $courtName, $date, $time,
                        $duration, (float)$quote['total'], $paymentMethod,
                        $courtId, $promoCode !== '' ? $promoCode : null, (float)($quote['discount'] ?? 0)
                    );
                    if (!empty($res['success'])) {
                        $res['quote'] = [
                            'rate'        => $quote['rate'],
                            'court_fee'   => $quote['court_fee'],
                            'service_fee' => $quote['service_fee'],
                            'discount'    => $quote['discount'],
                            'promo_label' => $quote['promo_label'],
                            'total'       => $quote['total'],
                        ];
                    }
                    return $this->json($res);

                case 'quote_booking':
                    // Lets the checkout screen display the same authoritative numbers
                    // the server will actually charge (used for live promo validation).
                    if (!$currentUser) {
                        return $this->jsonError('Unauthorized', 401);
                    }
                    $qFacRaw    = $request->input('facility_id', '');
                    $qFacility  = ctype_digit((string)$qFacRaw) ? (int)$qFacRaw : (string)$qFacRaw;
                    $qCourtId   = trim((string)$request->input('court_id', ''));
                    $qCourt     = (string)$request->input('court_name', 'Court 1');
                    $qDuration  = $this->pricingService->normalizeDuration($request->input('duration', 1));
                    $qPromo     = trim((string)$request->input('promo_code', ''));
                    $qMatchId   = trim((string)$request->input('match_id', ''));

                    if ($qMatchId !== '') {
                        $qMatch = $this->matchService->getMatchById($qMatchId);
                        if (!$qMatch) {
                            return $this->jsonError('Open Play session not found.', 404);
                        }
                        $quote = $this->pricingService->quoteMatchJoin($qMatch, $qPromo !== '' ? $qPromo : null, $currentUser['id']);
                    } else {
                        $quote = $this->pricingService->quoteCourtBooking(
                            $qFacility, $qCourt, $qDuration, $qPromo !== '' ? $qPromo : null, $currentUser['id'], $qCourtId
                        );
                    }

                    if (empty($quote['success'])) {
                        return $this->jsonError((string)($quote['message'] ?? 'Unable to price this booking.'), 400);
                    }
                    // Same $currentUser['id'] as the quote above, so this preview
                    // never says a promo "applies" when the actual charge (which
                    // enforces the same per-user cap) would reject it.
                    $promoFeedback = $this->pricingService->evaluatePromo(
                        $qPromo !== '' ? $qPromo : null,
                        (float)$quote['court_fee'] + (float)$quote['service_fee'],
                        $currentUser['id']
                    );
                    return $this->json([
                        'success'      => true,
                        'quote'        => $quote,
                        'promo_valid'  => $promoFeedback['valid'],
                        'promo_message'=> $promoFeedback['message'],
                    ]);

                case 'court_availability':
                    // Which of a court's fixed hourly slots are free on a given
                    // day — lets the booking screen show real availability up
                    // front instead of a guess the server might reject after
                    // the player has already picked a time and paid.
                    $avFacRaw  = $request->query('facility_id', '');
                    $avFacility = ctype_digit((string)$avFacRaw) ? (int)$avFacRaw : (string)$avFacRaw;
                    $avCourtId = trim((string)$request->query('court_id', ''));
                    $avDateRaw = (string)$request->query('date', date('Y-m-d'));

                    if ($avFacility === '' || $avFacility === 0 || $avCourtId === '') {
                        return $this->jsonError('facility_id and court_id are required.', 400);
                    }
                    $avTs = strtotime($avDateRaw);
                    $avDate = $avTs !== false ? date('Y-m-d', $avTs) : date('Y-m-d');

                    return $this->json([
                        'success' => true,
                        'date'    => $avDate,
                        'slots'   => $this->bookingService->getSlotAvailability($avFacility, $avCourtId, $avDate),
                    ]);

                case 'bookings':
                    if (!$currentUser) {
                        return $this->json(['success' => false, 'bookings' => []]);
                    }
                    $status = $request->query('status');
                    $bookings = $this->bookingService->getBookings($currentUser['id'], $status ? (string)$status : null);
                    return $this->json(['success' => true, 'bookings' => $bookings]);

                case 'cancel_booking':
                    if (!$currentUser) {
                        return $this->jsonError('Unauthorized', 401);
                    }
                    $bookingId = (string)$request->input('booking_id', '');
                    $res = $this->bookingService->cancelBooking($bookingId, $currentUser['id']);
                    if (is_array($res)) {
                        return $this->json($res);
                    }
                    return $this->json(['success' => (bool)$res, 'message' => $res ? 'Booking cancelled' : 'Could not cancel booking']);

                // ----------------------------------------------------------------------
                // Wallet
                // ----------------------------------------------------------------------
                case 'wallet':
                    if (!$currentUser) {
                        return $this->json(['success' => false, 'balance' => 0, 'transactions' => []]);
                    }
                    $user = $this->authService->getUserById($currentUser['id']);
                    $txs = $this->walletService->getWalletTransactions($currentUser['id']);
                    return $this->json([
                        'success' => true,
                        'balance' => (float)($user['wallet_balance'] ?? 0),
                        'transactions' => $txs
                    ]);

                case 'top_up':
                    if (!$currentUser) {
                        return $this->jsonError('Unauthorized', 401);
                    }
                    $amount = (float)($request->input('amount') ?? $request->query('amount', 0));
                    $method = trim((string)($request->input('method') ?? $request->query('method', 'GCash'))) ?: 'GCash';

                    // No payment gateway is wired up yet. Crediting a wallet from an
                    // unverified client request would mint real spending power, so this
                    // fails closed in production and only simulates in local/dev.
                    if (!$this->simulatedPaymentsAllowed()) {
                        return $this->jsonError(
                            'Wallet top-ups are temporarily unavailable while payment processing is being finalised.',
                            503
                        );
                    }

                    $res = $this->walletService->topUpWallet($currentUser['id'], $amount, $method);
                    if (!empty($res['success'])) {
                        $res['simulated'] = true;
                    }
                    return $this->json($res);

                // ----------------------------------------------------------------------
                // Community Feed
                // ----------------------------------------------------------------------
                case 'feed_posts':
                    $posts = $this->communityService->getFeedPosts($currentUser ? $currentUser['id'] : null);
                    return $this->json(['success' => true, 'posts' => $posts]);

                case 'create_post':
                    if (!$currentUser) {
                        return $this->jsonError('Please log in to post', 401);
                    }
                    $content = trim((string)$request->input('content', ''));
                    $imageUrl = trim((string)$request->input('image_url', '')) ?: null;
                    $type = (string)$request->input('post_type', 'text');
                    if (empty($content)) {
                        return $this->jsonError('Post cannot be empty', 400);
                    }
                    if (mb_strlen($content) > 2000) {
                        return $this->jsonError('Posts are limited to 2,000 characters.', 400);
                    }
                    if (!preg_match('/^[a-z_]{1,30}$/', $type)) {
                        $type = 'text';
                    }
                    // Only a real web image or a small inline image — never a
                    // javascript: or data:text URL rendered into other feeds.
                    if ($imageUrl !== null && (strlen($imageUrl) > self::MAX_AVATAR_BYTES
                        || !preg_match('#^(https?://|data:image/(png|jpe?g|webp|gif);base64,)#i', $imageUrl))) {
                        return $this->jsonError('Unsupported image. Please attach a PNG, JPG, WEBP or GIF.', 400);
                    }
                    $res = $this->communityService->createFeedPost($currentUser['id'], $content, $imageUrl, $type);
                    return $this->json($res);

                case 'like_post':
                    if (!$currentUser) {
                        return $this->jsonError('Please log in to like', 401);
                    }
                    $postId = (string)$request->input('post_id', '');
                    $res = $this->communityService->toggleLikePost($postId, $currentUser['id']);
                    return $this->json($res);

                case 'toggle_favorite_facility':
                    // Was a CSS class toggled in the browser only — no backend,
                    // reset on every reload, meant nothing beyond that one tab.
                    if (!$currentUser) {
                        return $this->jsonError('Please log in to save favorites', 401);
                    }
                    $favFacilityId = (string)$request->input('facility_id', '');
                    if ($favFacilityId === '') {
                        return $this->jsonError('Missing facility ID', 400);
                    }
                    $favRes = \Picklers\Core\Database::get()->toggleFavoriteFacility((string)$currentUser['id'], $favFacilityId);
                    return $this->json($favRes);

                case 'add_comment':
                    if (!$currentUser) {
                        return $this->jsonError('Please log in to comment', 401);
                    }
                    $postId = (string)$request->input('post_id', '');
                    $comment = trim((string)$request->input('comment', ''));
                    if (empty($comment)) {
                        return $this->jsonError('Comment cannot be empty', 400);
                    }
                    if (mb_strlen($comment) > 1000) {
                        return $this->jsonError('Comments are limited to 1,000 characters.', 400);
                    }
                    $res = $this->communityService->addComment($postId, $currentUser['id'], $comment);
                    return $this->json($res);

                // ----------------------------------------------------------------------
                // Chat & Direct Messages
                // ----------------------------------------------------------------------
                case 'messages':
                    if (!$currentUser) {
                        return $this->json(['success' => false, 'messages' => []]);
                    }
                    $partnerId = $this->resolveMessagePartnerId((string)$request->query('partner_id', 'usr_admin'));
                    \Picklers\Core\Database::get()->markConversationRead((string)$currentUser['id'], $partnerId);
                    $messages = $this->communityService->getMessages($currentUser['id'], $partnerId);
                    $partner = $this->authService->getUserById($partnerId);
                    return $this->json([
                        'success' => true,
                        'messages' => $messages,
                        'partner' => $partner ? [
                            'id' => $partner['id'],
                            'name' => $partner['name'],
                            'avatar_url' => $partner['avatar_url'],
                            'level' => $partner['level'],
                        ] : null
                    ]);

                case 'send_message':
                    if (!$currentUser) {
                        return $this->jsonError('Unauthorized', 401);
                    }
                    $partnerId = $this->resolveMessagePartnerId((string)$request->input('partner_id', 'usr_admin'));
                    $content = trim((string)$request->input('content', ''));
                    if (empty($content)) {
                        return $this->jsonError('Message is empty', 400);
                    }
                    if (mb_strlen($content) > 2000) {
                        return $this->jsonError('Messages are limited to 2,000 characters.', 400);
                    }
                    $recipient = $this->authService->getUserById($partnerId);
                    if (!$recipient || ($recipient['role'] ?? '') === 'deleted' || (string)$recipient['id'] === (string)$currentUser['id']) {
                        return $this->jsonError('This conversation is not available.', 404);
                    }
                    // Replies only ever come from the actual recipient.
                    $res = $this->communityService->sendMessage($currentUser['id'], $partnerId, $content);
                    return $this->json($res);
                // ----------------------------------------------------------------------
                // Console Operations: Owner & Admin
                // ----------------------------------------------------------------------
                case 'update_court_status':
                case 'owner_update_court':
                    if (!$currentUser || (empty($currentUser['is_owner']) && empty($currentUser['is_admin']))) {
                        return $this->jsonError('Unauthorized: Owner access required', 403);
                    }
                    // Cast: a numeric JSON court_id reached verifyCourtOwner(string)
                    // as an int and threw a TypeError under strict_types.
                    $courtId = trim((string)$request->input('court_id', ''));
                    $status = (string)$request->input('status', 'available');
                    $allowedStatuses = ['available', 'occupied', 'maintenance'];
                    if (!in_array($status, $allowedStatuses, true)) {
                        return $this->jsonError('Invalid status value', 400);
                    }
                    $db = \Picklers\Core\Database::get();
                    if ($courtId === '' || !$db->getCourtById($courtId)) {
                        return $this->jsonError('Court not found', 404);
                    }
                    if (empty($currentUser['is_admin'])) {
                        if (!$db->verifyCourtOwner($courtId, (string)$currentUser['id'])) {
                            return $this->jsonError('Unauthorized: You do not own this court', 403);
                        }
                    }
                    $db->updateCourtStatus($courtId, $status);
                    return $this->jsonSuccess([], "Court status updated to $status");

                case 'approve_booking':
                    if (!$currentUser || (empty($currentUser['is_owner']) && empty($currentUser['is_admin']))) {
                        return $this->jsonError('Unauthorized: Owner access required', 403);
                    }
                    $bookingId = (string)$request->input('booking_id', '');
                    if (empty($bookingId)) {
                        return $this->jsonError('Missing booking ID', 400);
                    }
                    $db = \Picklers\Core\Database::get();
                    if (empty($currentUser['is_admin'])) {
                        if (!$db->verifyBookingOwner($bookingId, $currentUser['id'])) {
                            return $this->jsonError('Unauthorized: You do not own the facility for this booking', 403);
                        }
                    }
                    $booking = $db->getBookingById($bookingId);
                    if (!$booking) {
                        return $this->jsonError('Booking record not found', 404);
                    }
                    $prevStatus = (string)($booking['status'] ?? 'pending');
                    if (in_array($prevStatus, ['cancelled', 'declined'], true)) {
                        return $this->jsonError('This request was already cancelled or declined.', 400);
                    }
                    if ($prevStatus === 'confirmed') {
                        // Already accepted (another tab, a double-click): report
                        // the real state without re-counting or re-notifying.
                        return $this->jsonSuccess(['booking_id' => $bookingId, 'status' => 'confirmed'], 'This booking is already confirmed.');
                    }

                    // Resolve the Open Play match (if any) BEFORE writing the new
                    // status, so a full session can be rejected without leaving
                    // the booking half-approved.
                    $matchId = $booking['match_id'] ?? null;
                    $facId = $booking['facility_id'] ?? null;
                    if (!$matchId && ($facId !== null) && (!empty($booking['court_name']) || str_starts_with($bookingId, 'PKL-OP-'))) {
                        $matches = $db->getMatchesByFacility($facId);
                        foreach ($matches as $m) {
                            if (($m['type'] ?? '') === ($booking['court_name'] ?? '') && ($m['date'] ?? '') === ($booking['date'] ?? '') && ($m['time'] ?? '') === ($booking['time'] ?? '')) {
                                $matchId = $m['id'];
                                break;
                            }
                        }
                    }
                    if ($matchId) {
                        $match = $db->getMatchById((string)$matchId);
                        // Capacity is per session occurrence. current_players is a
                        // lifetime counter that never resets for an Everyday
                        // session, so comparing it to max_players made a recurring
                        // session permanently "full" once enough days had filled.
                        if ($match) {
                            $occurrence = (string)($booking['booking_date'] ?? '') !== ''
                                ? (string)$booking['booking_date']
                                : \Picklers\Core\Database::getMatchTargetDate($match);
                            if ($db->countActiveMatchBookings((string)$matchId, $occurrence) > (int)($match['max_players'] ?? 0)) {
                                return $this->jsonError('This Open Play session is already full. Decline this request or increase its capacity first.', 409);
                            }
                        }
                    }

                    // Compare-and-set: only the request that actually moves the
                    // booking out of pending takes the seat and notifies.
                    if (!$db->transitionBookingStatus($bookingId, ['pending', 'upcoming'], 'confirmed')) {
                        $latest = $db->getBookingById($bookingId);
                        if (($latest['status'] ?? '') === 'confirmed') {
                            return $this->jsonSuccess(['booking_id' => $bookingId, 'status' => 'confirmed'], 'This booking is already confirmed.');
                        }
                        return $this->jsonError('This request is no longer pending.', 409);
                    }

                    if ($matchId) {
                        $db->adjustMatchPlayerCount((string)$matchId, 1);
                    }
                    // The player was only ever told a request was SENT (see
                    // createBooking()/joinMatch()'s notifications). This is the
                    // one place that actually confirms it.
                    $courtLabel = (string)($booking['court_name'] ?? 'your court');
                    $facilityLabel = (string)($booking['facility_name'] ?? 'the facility');
                    $this->notificationService->addNotification(
                        (string)($booking['user_id'] ?? ''),
                        'Booking Confirmed! 🎾',
                        "Great news! Your reservation for {$courtLabel} at {$facilityLabel} on " .
                        ($booking['date'] ?? 'the requested date') . ' (' . ($booking['time'] ?? 'the requested time') . ') has been confirmed. See you on the court!',
                        'booking'
                    );

                    return $this->jsonSuccess(['booking_id' => $bookingId, 'status' => 'confirmed'], 'Booking confirmed successfully');

                case 'decline_booking':
                    if (!$currentUser || (empty($currentUser['is_owner']) && empty($currentUser['is_admin']))) {
                        return $this->jsonError('Unauthorized: Owner access required', 403);
                    }
                    $bookingId = (string)$request->input('booking_id', '');
                    if (empty($bookingId)) {
                        return $this->jsonError('Missing booking ID', 400);
                    }
                    $db = \Picklers\Core\Database::get();
                    if (empty($currentUser['is_admin'])) {
                        if (!$db->verifyBookingOwner($bookingId, $currentUser['id'])) {
                            return $this->jsonError('Unauthorized: You do not own the facility for this booking', 403);
                        }
                    }

                    $booking = $db->getBookingById($bookingId);
                    if (!$booking) {
                        return $this->jsonError('Booking record not found', 404);
                    }

                    if (($booking['status'] ?? '') === 'cancelled') {
                        return $this->jsonError('Booking is already cancelled', 400);
                    }

                    $refundProcessed = false;
                    try {
                        $refundProcessed = $db->declineBookingAtomically(
                            $bookingId,
                            (string)$booking['user_id'],
                            (float)($booking['price'] ?? 0),
                            (string)($booking['payment_method'] ?? ''),
                            (string)($booking['facility_name'] ?? 'Pickleball Facility')
                        );
                    } catch (\Throwable $e) {
                        return $this->jsonError('Failed to process decline. Please try again.', 500);
                    }

                    return $this->jsonSuccess([
                        'booking_id' => $bookingId,
                        'status' => 'cancelled',
                        'refunded' => $refundProcessed
                    ], 'Booking declined and player refunded successfully');

                // ----------------------------------------------------------------------
                // Tournaments
                //
                // Every action below is owner-scoped twice: the account must be an
                // owner (or admin), and the tournament must belong to a facility
                // that account actually holds — see requireOwnedTournament().
                // ----------------------------------------------------------------------
                case 'list_tournaments':
                    if (!$this->isOwnerAccount($currentUser)) {
                        return $this->jsonError('Unauthorized: owner access required.', 403);
                    }
                    $facility = $this->ownerFacility($currentUser, (string)$request->input('facility_id', ''));
                    return $this->jsonSuccess([
                        'tournaments' => $this->tournamentService()->grouped($facility ? (string)$facility['id'] : null),
                    ]);

                case 'get_tournament':
                    $owned = $this->requireOwnedTournament($currentUser, (string)$request->input('tournament_id', ''));
                    if ($owned['error'] !== null) {
                        return $this->jsonError($owned['error'], $owned['status']);
                    }
                    return $this->jsonSuccess(['tournament' => $owned['tournament']]);

                case 'create_tournament':
                    if (!$this->isOwnerAccount($currentUser)) {
                        return $this->jsonError('Unauthorized: owner access required.', 403);
                    }

                    $facility = $this->ownerFacility($currentUser, (string)$request->input('facility_id', ''));
                    if ($facility === null) {
                        return $this->jsonError('Complete your owner application before hosting a tournament.', 403);
                    }

                    $created = $this->tournamentService()->create([
                        'facility_id'  => (string)$facility['id'],
                        'owner_id'     => (string)($currentUser['id'] ?? ''),
                        'title'        => (string)$request->input('title', ''),
                        'category'     => (string)$request->input('category', $request->input('type', '')),
                        'format'       => (string)$request->input('format', 'single_elimination'),
                        'pairing_mode' => (string)$request->input('pairing_mode', 'fixed'),
                        'max_teams'    => $request->input('max_teams', 16),
                        'date'         => (string)$request->input('date', ''),
                        'end_date'     => (string)$request->input('end_date', ''),
                        'time'         => (string)$request->input('time', ''),
                        'venue'        => (string)$request->input('venue', (string)($facility['name'] ?? '')),
                        'prize_pool'   => (string)$request->input('prize_pool', ''),
                        'entry_fee'    => (string)$request->input('entry_fee', ''),
                        'description'  => (string)$request->input('description', ''),
                        'teams'        => (array)$request->input('teams', []),
                        'players'      => (array)$request->input('players', []),
                    ]);

                    if ($created['error'] !== null) {
                        return $this->jsonError($created['error'], 422);
                    }

                    return $this->jsonSuccess(
                        ['tournament' => $created['tournament']],
                        'Tournament published successfully.'
                    );

                case 'update_tournament':
                    $owned = $this->requireOwnedTournament($currentUser, (string)$request->input('tournament_id', ''));
                    if ($owned['error'] !== null) {
                        return $this->jsonError($owned['error'], $owned['status']);
                    }

                    // Only forward keys the client actually sent, so a partial
                    // edit never blanks a field it did not touch.
                    $patch = [];
                    foreach ([
                        'title', 'category', 'format', 'pairing_mode', 'max_teams', 'date',
                        'end_date', 'time', 'venue', 'prize_pool', 'entry_fee', 'description', 'status',
                    ] as $key) {
                        $value = $request->input($key, null);
                        if ($value !== null) {
                            $patch[$key] = $value;
                        }
                    }

                    $updated = $this->tournamentService()->update((string)$owned['tournament']['id'], $patch);
                    if ($updated['error'] !== null) {
                        return $this->jsonError($updated['error'], 422);
                    }
                    return $this->jsonSuccess(['tournament' => $updated['tournament']], 'Tournament updated.');

                case 'delete_tournament':
                    $owned = $this->requireOwnedTournament($currentUser, (string)$request->input('tournament_id', ''));
                    if ($owned['error'] !== null) {
                        return $this->jsonError($owned['error'], $owned['status']);
                    }
                    if (!$this->tournamentService()->delete((string)$owned['tournament']['id'])) {
                        return $this->jsonError('Tournament could not be deleted.', 500);
                    }
                    return $this->jsonSuccess([], 'Tournament deleted.');

                case 'add_tournament_player':
                case 'add_tournament_team':
                case 'add_tournament_entrant':
                    $owned = $this->requireOwnedTournament($currentUser, (string)$request->input('tournament_id', ''));
                    if ($owned['error'] !== null) {
                        return $this->jsonError($owned['error'], $owned['status']);
                    }

                    $entrant = $this->tournamentService()->addEntrant((string)$owned['tournament']['id'], [
                        'name'        => (string)$request->input('name', ''),
                        'team_name'   => (string)$request->input('team_name', ''),
                        'player1'     => (string)$request->input('player1', $request->input('player1_name', '')),
                        'player2'     => (string)$request->input('player2', $request->input('player2_name', '')),
                        'player1_id'  => (string)$request->input('player1_id', ''),
                        'player2_id'  => (string)$request->input('player2_id', ''),
                        'email'       => (string)$request->input('email', ''),
                        'user_id'     => (string)$request->input('user_id', ''),
                    ]);

                    if ($entrant['error'] !== null) {
                        return $this->jsonError($entrant['error'], 422);
                    }
                    return $this->jsonSuccess(['tournament' => $entrant['tournament']], 'Entrant registered.');

                case 'update_tournament_team':
                    $owned = $this->requireOwnedTournament($currentUser, (string)$request->input('tournament_id', ''));
                    if ($owned['error'] !== null) {
                        return $this->jsonError($owned['error'], $owned['status']);
                    }

                    $teamPatch = [];
                    foreach (['name', 'player1', 'player2', 'seed'] as $key) {
                        $value = $request->input($key, null);
                        if ($value !== null) {
                            $teamPatch[$key] = $value;
                        }
                    }

                    $teamResult = $this->tournamentService()->updateTeam(
                        (string)$owned['tournament']['id'],
                        (string)$request->input('team_id', ''),
                        $teamPatch
                    );
                    if ($teamResult['error'] !== null) {
                        return $this->jsonError($teamResult['error'], 422);
                    }
                    return $this->jsonSuccess(['tournament' => $teamResult['tournament']], 'Team updated.');

                case 'remove_tournament_team':
                    $owned = $this->requireOwnedTournament($currentUser, (string)$request->input('tournament_id', ''));
                    if ($owned['error'] !== null) {
                        return $this->jsonError($owned['error'], $owned['status']);
                    }
                    $removed = $this->tournamentService()->removeTeam(
                        (string)$owned['tournament']['id'],
                        (string)$request->input('team_id', '')
                    );
                    if ($removed['error'] !== null) {
                        return $this->jsonError($removed['error'], 422);
                    }
                    return $this->jsonSuccess(['tournament' => $removed['tournament']], 'Team removed.');

                case 'remove_tournament_player':
                    $owned = $this->requireOwnedTournament($currentUser, (string)$request->input('tournament_id', ''));
                    if ($owned['error'] !== null) {
                        return $this->jsonError($owned['error'], $owned['status']);
                    }
                    $removedPlayer = $this->tournamentService()->removePoolPlayer(
                        (string)$owned['tournament']['id'],
                        (string)$request->input('player_id', '')
                    );
                    if ($removedPlayer['error'] !== null) {
                        return $this->jsonError($removedPlayer['error'], 422);
                    }
                    return $this->jsonSuccess(['tournament' => $removedPlayer['tournament']], 'Player removed from the pool.');

                case 'mix_tournament_teams':
                    $owned = $this->requireOwnedTournament($currentUser, (string)$request->input('tournament_id', ''));
                    if ($owned['error'] !== null) {
                        return $this->jsonError($owned['error'], $owned['status']);
                    }
                    $drawn = $this->tournamentService()->mixDraw((string)$owned['tournament']['id']);
                    if ($drawn['error'] !== null) {
                        return $this->jsonError($drawn['error'], 422);
                    }
                    return $this->jsonSuccess(
                        ['tournament' => $drawn['tournament']],
                        'Partners drawn — ' . count($drawn['tournament']['teams']) . ' teams formed.'
                    );

                case 'generate_tournament_bracket':
                    $owned = $this->requireOwnedTournament($currentUser, (string)$request->input('tournament_id', ''));
                    if ($owned['error'] !== null) {
                        return $this->jsonError($owned['error'], $owned['status']);
                    }
                    $generated = $this->tournamentService()->generateBracket(
                        (string)$owned['tournament']['id'],
                        filter_var($request->input('randomize_seeds', false), FILTER_VALIDATE_BOOLEAN)
                    );
                    if ($generated['error'] !== null) {
                        return $this->jsonError($generated['error'], 422);
                    }
                    return $this->jsonSuccess(['tournament' => $generated['tournament']], 'Bracket drawn. The tournament is live.');

                case 'reset_tournament_bracket':
                    $owned = $this->requireOwnedTournament($currentUser, (string)$request->input('tournament_id', ''));
                    if ($owned['error'] !== null) {
                        return $this->jsonError($owned['error'], $owned['status']);
                    }
                    $cleared = $this->tournamentService()->resetBracket((string)$owned['tournament']['id']);
                    if ($cleared['error'] !== null) {
                        return $this->jsonError($cleared['error'], 422);
                    }
                    return $this->jsonSuccess(['tournament' => $cleared['tournament']], 'Bracket cleared. Registration is open again.');

                case 'report_tournament_match':
                    $owned = $this->requireOwnedTournament($currentUser, (string)$request->input('tournament_id', ''));
                    if ($owned['error'] !== null) {
                        return $this->jsonError($owned['error'], $owned['status']);
                    }

                    $rawScore1 = $request->input('score1', null);
                    $rawScore2 = $request->input('score2', null);
                    $score1 = ($rawScore1 === null || $rawScore1 === '') ? null : (int)$rawScore1;
                    $score2 = ($rawScore2 === null || $rawScore2 === '') ? null : (int)$rawScore2;

                    // A single-sided score is ambiguous, and a negative one is
                    // not a pickleball result — drop both rather than storing a
                    // scoreline nobody can interpret.
                    if ($score1 === null || $score2 === null) {
                        $score1 = null;
                        $score2 = null;
                    } elseif ($score1 < 0 || $score2 < 0 || $score1 > 999 || $score2 > 999) {
                        return $this->jsonError('Scores must be between 0 and 999.', 422);
                    }

                    $reported = $this->tournamentService()->reportMatch(
                        (string)$owned['tournament']['id'],
                        (string)$request->input('match_id', ''),
                        (string)$request->input('winner_team_id', ''),
                        $score1,
                        $score2
                    );
                    if ($reported['error'] !== null) {
                        return $this->jsonError($reported['error'], 422);
                    }
                    return $this->jsonSuccess(['tournament' => $reported['tournament']], 'Result recorded.');

                case 'reset_tournament_match':
                    $owned = $this->requireOwnedTournament($currentUser, (string)$request->input('tournament_id', ''));
                    if ($owned['error'] !== null) {
                        return $this->jsonError($owned['error'], $owned['status']);
                    }
                    $wiped = $this->tournamentService()->resetMatchResult(
                        (string)$owned['tournament']['id'],
                        (string)$request->input('match_id', '')
                    );
                    if ($wiped['error'] !== null) {
                        return $this->jsonError($wiped['error'], 422);
                    }
                    return $this->jsonSuccess(['tournament' => $wiped['tournament']], 'Match result cleared.');

                case 'search_players':
                case 'search_users':
                    // Only used by the owner Staff/Walk-In search and the
                    // tournament roster search (owner.js, tournament.js) — both
                    // owner-only screens — but had no auth check at all, so
                    // anyone unauthenticated could enumerate every registered
                    // user's name, email, and role via this one GET request.
                    if (!$this->isOwnerAccount($currentUser)) {
                        return $this->jsonError('Unauthorized', 401);
                    }
                    $q = strtolower(trim((string)($request->query('q') ?? $request->input('q', ''))));
                    $allUsers = \Picklers\Core\Database::get()->getAllUsers();
                    $matched = [];
                    foreach ($allUsers as $u) {
                        if (($u['role'] ?? '') === 'deleted') {
                            continue;
                        }
                        $name = (string)($u['name'] ?? '');
                        $email = (string)($u['email'] ?? '');
                        $nameLower = strtolower($name);
                        $emailLower = strtolower($email);

                        if ($q === '') {
                            $score = 0;
                        } else if (str_starts_with($nameLower, $q)) {
                            $score = 1; // Highest priority: Name starts with query
                        } else {
                            $words = preg_split('/\s+/', $nameLower);
                            $wordMatch = false;
                            foreach ($words as $w) {
                                if (str_starts_with($w, $q)) {
                                    $wordMatch = true;
                                    break;
                                }
                            }
                            if ($wordMatch) {
                                $score = 2; // Word in name starts with query
                            } else if (str_starts_with($emailLower, $q)) {
                                $score = 3; // Email starts with query
                            } else if (str_contains($nameLower, $q)) {
                                $score = 4; // Name contains query
                            } else if (str_contains($emailLower, $q)) {
                                $score = 5; // Email contains query
                            } else {
                                continue;
                            }
                        }

                        $matched[] = [
                            'id' => (string)($u['id'] ?? ''),
                            'name' => $name,
                            'email' => $email,
                            'avatar_url' => (string)($u['avatar_url'] ?? ''),
                            'role' => (string)($u['role'] ?? 'Player'),
                            '_score' => $score
                        ];
                    }

                    usort($matched, function ($a, $b) {
                        if ($a['_score'] !== $b['_score']) {
                            return $a['_score'] <=> $b['_score'];
                        }
                        return strcasecmp($a['name'], $b['name']);
                    });

                    $resultUsers = array_slice(array_map(function ($u) {
                        unset($u['_score']);
                        return $u;
                    }, $matched), 0, 10);

                    return $this->jsonSuccess(['users' => $resultUsers]);

                // ----------------------------------------------------------------------
                // Admin actions — delegated, not duplicated.
                //
                // AdminController::handle() is the single source of truth (it
                // performs its own admin and CSRF checks and sends the related
                // notifications); these legacy api.php actions forward to it.
                // ----------------------------------------------------------------------
                case 'admin_update_role':
                case 'admin_toggle_verify':
                case 'admin_approve_owner_application':
                case 'admin_reject_owner_application':
                    return (new AdminController())->handle($request);

                default:
                    return $this->jsonError('Invalid action: ' . htmlspecialchars($action), 400);
            }
        } catch (\Throwable $e) {
            // \Throwable, so a TypeError also returns JSON; raw exception text
            // (which can include SQL) stays in the log, not the response.
            error_log(sprintf('[PICKLERS API] action=%s failed: %s in %s:%d', $action, $e->getMessage(), $e->getFile(), $e->getLine()));
            $debug = filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN);
            return $this->jsonError($debug ? $e->getMessage() : 'Something went wrong while processing your request. Please try again.', 500);
        }
    }
}
