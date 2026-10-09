<?php
declare(strict_types=1);

namespace Picklers\Web\Api;

use Picklers\Core\Database;
use Picklers\Web\Http\LegacyInput;
use Picklers\Web\Http\LegacyResponses;
use Picklers\Web\Service\OwnerScope;
use Symfony\Component\HttpFoundation\JsonResponse;

/** Owner API (/api): live queues, check-in, booking approval, court status and user search. */
final class OwnerActions
{
    use LegacyResponses;

    public function __construct(
        private readonly Database $db,
        private readonly OwnerScope $scope,
    ) {
    }

    public function openPlayRoster(LegacyInput $in, ?array $user): JsonResponse
    {
        if (!OwnerScope::isOwnerAccount($user)) {
            return $this->jsonError('Owner access required.', 403);
        }
        $courtName = trim((string)($in->query('court_name') ?? $in->input('court_name', '')));
        $courtId = trim((string)($in->query('court_id') ?? $in->input('court_id', '')));
        $facility = $this->scope->ownedFacility($user, trim((string)($in->query('facility_id') ?? $in->input('facility_id', ''))));
        if ($facility === null) {
            return $this->jsonError('No facility context found.', 403);
        }
        $roster = $this->db->getOpenPlayRoster($facility['id'], $courtName, $courtId);

        return $this->json(['success' => true, 'roster' => $roster, 'court_name' => $courtName, 'data' => ['roster' => $roster]]);
    }

    /** The owner Dashboard's live "Requests" queue, re-fetched when the bookings sync version moves. */
    public function pendingRequests(LegacyInput $in, ?array $user): JsonResponse
    {
        if (!OwnerScope::isOwnerAccount($user)) {
            return $this->jsonError('Owner access required.', 403);
        }
        $facility = $this->scope->ownedFacility($user, trim((string)($in->query('facility_id') ?? $in->input('facility_id', ''))));
        if ($facility === null) {
            return $this->jsonError('No facility context found.', 403);
        }

        return $this->json(['success' => true, 'requests' => $this->db->getPendingBookingRequests($facility['id'])]);
    }

    /**
     * Checks a scanned code against a real booking: success only for one at this
     * owner's facility, confirmed, and for today's session.
     */
    public function verifyCheckin(LegacyInput $in, ?array $user): JsonResponse
    {
        if (!OwnerScope::isOwnerAccount($user)) {
            return $this->jsonError('Owner access required.', 403);
        }
        $code = trim((string)($in->query('code') ?? $in->input('code', '')));
        if ($code === '') {
            return $this->jsonError('No code provided.', 400);
        }
        // The raw booking id, or the "PICKLERS:<id>:..." payload in the receipt's QR code.
        $bookingId = trim(str_starts_with($code, 'PICKLERS:') ? (explode(':', $code)[1] ?? '') : $code);
        if ($bookingId === '') {
            return $this->jsonError('Unrecognized code format.', 400);
        }
        $booking = $this->db->getBookingById($bookingId);
        if (!$booking) {
            return $this->jsonError('No booking found for this code.', 404);
        }
        if (empty($user['is_admin']) && !$this->db->verifyBookingOwner($bookingId, $user['id'])) {
            return $this->jsonError('This pass is not for a booking at your facility.', 403);
        }
        $player = $this->db->getUserById((string)($booking['user_id'] ?? ''));
        $details = [
            'booking_id' => $bookingId,
            'player_name' => $player['name'] ?? ($booking['author_name'] ?? 'Registered Player'),
            'court_name' => (string)($booking['court_name'] ?? 'Court'),
            'date' => (string)($booking['date'] ?? ''),
            'time' => (string)($booking['time'] ?? ''),
            'status' => (string)($booking['status'] ?? ''),
        ];
        $status = (string)($booking['status'] ?? '');
        if ($status !== 'confirmed') {
            $reason = $status === 'pending'
                ? 'This reservation is still pending owner approval — it has not been confirmed.'
                : 'This reservation is ' . $status . " — it can't be checked in.";

            return $this->json(['success' => false, 'message' => $reason, 'booking' => $details], 409);
        }
        if ($this->db->isBookingPast($booking)) {
            return $this->json(['success' => false, 'message' => 'This pass has expired — the session has already ended.', 'booking' => $details], 409);
        }
        $day = (string)($booking['booking_date'] ?? '');
        if ($day !== '' && $day > date('Y-m-d')) {
            return $this->json(['success' => false, 'message' => 'This pass is for ' . date('M j, Y', (int)strtotime($day)) . ', not today.', 'booking' => $details], 409);
        }

        return $this->json(['success' => true, 'message' => 'Booking verified.', 'booking' => $details]);
    }

    /** update_court_status and owner_update_court. */
    public function updateCourtStatus(LegacyInput $in, ?array $user): JsonResponse
    {
        if (!$user || (empty($user['is_owner']) && empty($user['is_admin']))) {
            return $this->jsonError('Unauthorized: Owner access required', 403);
        }
        $courtId = trim((string)$in->input('court_id', ''));
        $status = (string)$in->input('status', 'available');
        if (!in_array($status, ['available', 'occupied', 'maintenance'], true)) {
            return $this->jsonError('Invalid status value', 400);
        }
        if ($courtId === '' || !$this->db->getCourtById($courtId)) {
            return $this->jsonError('Court not found', 404);
        }
        if (empty($user['is_admin']) && !$this->db->verifyCourtOwner($courtId, (string)$user['id'])) {
            return $this->jsonError('Unauthorized: You do not own this court', 403);
        }
        $this->db->updateCourtStatus($courtId, $status);

        return $this->jsonSuccess([], "Court status updated to $status");
    }

    /** One shared rule with the admin console: row lock, per-occurrence capacity, one seat, one notice. */
    public function approveBooking(LegacyInput $in, ?array $user): JsonResponse
    {
        if (!$user || (empty($user['is_owner']) && empty($user['is_admin']))) {
            return $this->jsonError('Unauthorized: Owner access required', 403);
        }
        $bookingId = (string)$in->input('booking_id', '');
        if ($bookingId === '') {
            return $this->jsonError('Missing booking ID', 400);
        }
        if (empty($user['is_admin']) && !$this->db->verifyBookingOwner($bookingId, $user['id'])) {
            return $this->jsonError('Unauthorized: You do not own the facility for this booking', 403);
        }
        if (!$this->db->isUsingMySQL()) {
            return $this->jsonError('Booking approvals need the primary database, which is unavailable right now.', 503);
        }
        $confirmed = $this->db->confirmBookingRequest($bookingId, ['actor_id' => (string)$user['id']]);
        if ($confirmed['already']) {
            return $this->jsonSuccess(['booking_id' => $bookingId, 'status' => 'confirmed'], $confirmed['message']);
        }
        if (!$confirmed['ok']) {
            return $this->jsonError($confirmed['message'], in_array($confirmed['code'], [404, 409], true) ? $confirmed['code'] : 400);
        }

        return $this->jsonSuccess(['booking_id' => $bookingId, 'status' => 'confirmed'], 'Booking confirmed successfully');
    }

    public function declineBooking(LegacyInput $in, ?array $user): JsonResponse
    {
        if (!$user || (empty($user['is_owner']) && empty($user['is_admin']))) {
            return $this->jsonError('Unauthorized: Owner access required', 403);
        }
        $bookingId = (string)$in->input('booking_id', '');
        if ($bookingId === '') {
            return $this->jsonError('Missing booking ID', 400);
        }
        if (empty($user['is_admin']) && !$this->db->verifyBookingOwner($bookingId, $user['id'])) {
            return $this->jsonError('Unauthorized: You do not own the facility for this booking', 403);
        }
        $booking = $this->db->getBookingById($bookingId);
        if (!$booking) {
            return $this->jsonError('Booking record not found', 404);
        }
        if (($booking['status'] ?? '') === 'cancelled') {
            return $this->jsonError('Booking is already cancelled', 400);
        }
        try {
            $refunded = $this->db->declineBookingAtomically(
                $bookingId,
                (string)$booking['user_id'],
                (float)($booking['price'] ?? 0),
                (string)($booking['payment_method'] ?? ''),
                (string)($booking['facility_name'] ?? 'Pickleball Facility')
            );
        } catch (\Throwable $e) {
            return $this->jsonError('Failed to process decline. Please try again.', 500);
        }

        return $this->jsonSuccess(['booking_id' => $bookingId, 'status' => 'cancelled', 'refunded' => $refunded], 'Booking declined and player refunded successfully');
    }

    /**
     * search_players / search_users — owner Staff/Walk-In and tournament roster
     * search. Ranked: name prefix, word prefix, email prefix, name contains,
     * email contains; at most 10.
     */
    public function searchUsers(LegacyInput $in, ?array $user): JsonResponse
    {
        if (!OwnerScope::isOwnerAccount($user)) {
            return $this->jsonError('Unauthorized', 401);
        }
        $q = strtolower(trim((string)($in->query('q') ?? $in->input('q', ''))));
        $matched = [];
        foreach ($this->db->getAllUsers() as $u) {
            if (($u['role'] ?? '') === 'deleted') {
                continue;
            }
            $name = (string)($u['name'] ?? '');
            $email = (string)($u['email'] ?? '');
            $score = self::searchScore($q, strtolower($name), strtolower($email));
            if ($score === null) {
                continue;
            }
            $matched[] = [
                'id' => (string)($u['id'] ?? ''),
                'name' => $name,
                'email' => $email,
                'avatar_url' => (string)($u['avatar_url'] ?? ''),
                'role' => (string)($u['role'] ?? 'Player'),
                '_score' => $score,
            ];
        }
        usort($matched, static fn($a, $b) => $a['_score'] <=> $b['_score'] ?: strcasecmp($a['name'], $b['name']));
        $users = array_map(static function ($u) {
            unset($u['_score']);

            return $u;
        }, array_slice($matched, 0, 10));

        return $this->jsonSuccess(['users' => $users]);
    }

    private static function searchScore(string $q, string $name, string $email): ?int
    {
        if ($q === '') {
            return 0;
        }
        if (str_starts_with($name, $q)) {
            return 1;
        }
        foreach (preg_split('/\s+/', $name) ?: [] as $word) {
            if (str_starts_with($word, $q)) {
                return 2;
            }
        }

        return match (true) {
            str_starts_with($email, $q) => 3,
            str_contains($name, $q) => 4,
            str_contains($email, $q) => 5,
            default => null,
        };
    }
}
