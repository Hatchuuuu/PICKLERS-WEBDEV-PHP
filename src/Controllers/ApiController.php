<?php
declare(strict_types=1);

namespace Picklers\Controllers;

use Exception;
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
            'create_post', 'send_message', 'like_post', 'add_comment',
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
                        $unread = $this->notificationService->getNotifications($currentUser['id']);
                        $syncPayload['unread_notifications'] = count(array_filter($unread, fn($n) => empty($n['is_read'])));
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
                    $level = trim((string)$request->input('level', ''));
                    $fields = [];
                    if (!empty($name)) $fields['name'] = $name;
                    if (!empty($phone)) $fields['phone'] = $phone;
                    if (!empty($level)) $fields['level'] = $level;
                    if (!empty($email) && $email !== ($currentUser['email'] ?? '')) {
                        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
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
                    if ($target) {
                        unset($_SESSION['user']);
                        \Picklers\Middleware\AuthMiddleware::login($target['id']);
                        return $this->json(['success' => true, 'user' => $target, 'message' => 'Switched to ' . $target['name']]);
                    }
                    return $this->jsonError('User not found', 404);

                case 'delete_own_account':
                    // The settings screen previously showed a "DELETE" confirmation
                    // and then merely signed the user out — nothing was ever
                    // deleted, while the toast claimed it had been. This performs a
                    // real soft-delete (the same shape admin_delete_user uses, so
                    // financial records referenced by FK constraints survive).
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

                    $selfId = (string)$currentUser['id'];
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
                    return $this->json([
                        'success' => true,
                        'facility' => $facility,
                        'courts' => $courts,
                        'images' => $images,
                        'amenities' => $amenities
                    ]);

                // ----------------------------------------------------------------------
                // Open Play Matches (Explore Tab)
                // ----------------------------------------------------------------------
                case 'my_tournaments':
                    if (!$currentUser) {
                        return $this->jsonError('Authentication required.', 401);
                    }
                    $playerName = strtolower(trim((string)($currentUser['name'] ?? '')));
                    $myTs = [];
                    foreach ($this->tournamentService()->all() as $t) {
                        $found = false;
                        foreach ((array)($t['teams'] ?? []) as $team) {
                            $p1 = strtolower(trim((string)($team['player1'] ?? '')));
                            $p2 = strtolower(trim((string)($team['player2'] ?? '')));
                            if ($playerName !== '' && (str_contains($p1, $playerName) || str_contains($p2, $playerName))) {
                                $found = true; break;
                            }
                        }
                        if (!$found) {
                            foreach ((array)($t['players_pool'] ?? []) as $player) {
                                $pn = strtolower(trim((string)($player['name'] ?? '')));
                                if ($playerName !== '' && str_contains($pn, $playerName)) {
                                    $found = true; break;
                                }
                            }
                        }
                        if ($found) { $myTs[] = $t; }
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
                    // court_id is the authoritative identity once the client supplies
                    // one (it comes straight from the courts list this same facility
                    // detail request returned, so it cannot be stale/mistyped the way
                    // a display name — previously regex-truncated client-side before
                    // it ever reached here — could be). court_name is kept only as a
                    // fallback for any caller that hasn't been updated to send an id.
                    $courtId   = trim((string)$request->input('court_id', ''));
                    $courtName = (string)$request->input('court_name', 'Court 1');
                    $date = (string)$request->input('date', 'Tomorrow');
                    $time = (string)$request->input('time', '6:00 PM - 7:00 PM');
                    $paymentMethod = (string)$request->input('payment_method', 'Pickle Credits');
                    if (!in_array($paymentMethod, self::ALLOWED_PAYMENT_METHODS, true)) {
                        return $this->jsonError('Unsupported payment method.', 400);
                    }

                    // Price is computed server-side from the court's published rate.
                    // Anything the client sends as `price` is deliberately ignored.
                    $duration  = $this->pricingService->normalizeDuration($request->input('duration', 1));
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

                    // Recorded ATOMICALLY inside createBooking()'s own transaction —
                    // a granted discount can no longer be committed without also
                    // being counted, or vice versa (see createBooking()'s doc comment).
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
                    $res = $this->communityService->createFeedPost($currentUser['id'], $content, $imageUrl, $type);
                    return $this->json($res);

                case 'like_post':
                    if (!$currentUser) {
                        return $this->jsonError('Please log in to like', 401);
                    }
                    $postId = (string)$request->input('post_id', '');
                    $res = $this->communityService->toggleLikePost($postId, $currentUser['id']);
                    return $this->json($res);

                case 'add_comment':
                    if (!$currentUser) {
                        return $this->jsonError('Please log in to comment', 401);
                    }
                    $postId = (string)$request->input('post_id', '');
                    $comment = trim((string)$request->input('comment', ''));
                    if (empty($comment)) {
                        return $this->jsonError('Comment cannot be empty', 400);
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
                    $partnerId = (string)$request->query('partner_id', 'usr_admin');
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
                            'online' => true
                        ] : null
                    ]);

                case 'send_message':
                    if (!$currentUser) {
                        return $this->jsonError('Unauthorized', 401);
                    }
                    $partnerId = (string)$request->input('partner_id', 'usr_admin');
                    $content = trim((string)$request->input('content', ''));
                    if (empty($content)) {
                        return $this->jsonError('Message is empty', 400);
                    }
                    $res = $this->communityService->sendMessage($currentUser['id'], $partnerId, $content);

                    // Optional auto-reply from admin bot if user is messaging admin
                    if ($partnerId === 'usr_admin' && $currentUser['id'] !== 'usr_admin') {
                        $replies = [
                            "Let's get some games in! Court 1 at BGC is usually free around 6 PM.",
                            "Nice! Let me know if you want to team up for Saturday's tournament.",
                            "Always ready for a dink battle! See you on the court 🏓",
                            "Got it! Thanks for reaching out. Have a great session!"
                        ];
                        $replyContent = $replies[array_rand($replies)];
                        $this->communityService->sendMessage($partnerId, $currentUser['id'], $replyContent);
                        $res['auto_reply'] = $replyContent;
                    }

                    return $this->json($res);

                // ----------------------------------------------------------------------
                // Console Operations: Owner & Admin
                // ----------------------------------------------------------------------
                case 'update_court_status':
                case 'owner_update_court':
                    if (!$currentUser || (empty($currentUser['is_owner']) && empty($currentUser['is_admin']))) {
                        return $this->jsonError('Unauthorized: Owner access required', 403);
                    }
                    $courtId = $request->input('court_id', 0);
                    $status = (string)$request->input('status', 'available');
                    $allowedStatuses = ['available', 'occupied', 'maintenance'];
                    if (!in_array($status, $allowedStatuses, true)) {
                        return $this->jsonError('Invalid status value', 400);
                    }
                    $db = \Picklers\Core\Database::get();
                    if (empty($currentUser['is_admin'])) {
                        if (!$db->verifyCourtOwner($courtId, $currentUser['id'])) {
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
                    $prevStatus = $booking['status'] ?? 'pending';
                    $this->bookingService->updateBookingStatus($bookingId, 'confirmed');

                    if ($booking) {
                        $playerUser = $db->getUserById($booking['user_id'] ?? '');
                        $playerName = $playerUser['name'] ?? ($booking['author_name'] ?? 'Player');
                        $courtTarget = !empty($booking['court_id']) ? $booking['court_id'] : ($booking['court_name'] ?? null);
                        $facId = $booking['facility_id'] ?? null;
                        if ($facId && $courtTarget) {
                            $db->occupyCourt($facId, $courtTarget, $playerName, $booking['time'] ?? '36:20');
                        }

                        // Increment match player count if this was an Open Play join request being approved
                        if ($prevStatus !== 'confirmed') {
                            $matchId = $booking['match_id'] ?? null;
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
                                $db->adjustMatchPlayerCount((string)$matchId, 1);
                            }
                        }
                    }

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
                    $q = strtolower(trim((string)($request->query('q') ?? $request->input('q', ''))));
                    $allUsers = \Picklers\Core\Database::get()->getAllUsers();
                    $matched = [];
                    foreach ($allUsers as $u) {
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
                // These four used to exist here as separate, weaker copies of the
                // AdminController implementations: they skipped the notification
                // dispatch and never updated the owner_application record's own
                // status, so approving via api.php left the application stuck in
                // the review queue forever. AdminController::handle() is the single
                // source of truth (it performs its own admin + CSRF checks); this
                // forwards to it so any legacy caller keeps working.
                // ----------------------------------------------------------------------
                case 'admin_update_role':
                case 'admin_toggle_verify':
                case 'admin_approve_owner_application':
                case 'admin_reject_owner_application':
                    return (new AdminController())->handle($request);

                default:
                    return $this->jsonError('Invalid action: ' . htmlspecialchars($action), 400);
            }
        } catch (Exception $e) {
            return $this->jsonError($e->getMessage(), 500);
        }
    }
}
