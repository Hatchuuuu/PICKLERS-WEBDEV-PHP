<?php
declare(strict_types=1);

namespace Picklers\Web\Api;

use Picklers\Domain\Schedule;

use Picklers\Repositories\BookingRepository;
use Picklers\Repositories\UserRepository;
use Picklers\Repositories\FacilityRepository;
use Picklers\Repositories\OpenPlayRepository;
use Picklers\Web\Http\LegacyInput;
use Picklers\Web\Http\LegacyResponses;
use Picklers\Web\Service\OwnerScope;
use Symfony\Component\HttpFoundation\JsonResponse;

/** Owner API (/api): live queues, check-in, booking approval, court status and user search. */
final class OwnerActions
{
    use LegacyResponses;

    public function __construct(
        private readonly BookingRepository $bookings,
        private readonly UserRepository $users,
        private readonly FacilityRepository $facilities,
        private readonly OpenPlayRepository $openPlay,
        private readonly OwnerScope $scope,
    ) {
    }

    public function openPlayRoster(LegacyInput $in, ?array $user): JsonResponse
    {
        if ($user === null) {
            return $this->jsonError('Owner access required.', 403);
        }
        $courtName = trim((string)($in->query('court_name') ?? $in->input('court_name', '')));
        $courtId = trim((string)($in->query('court_id') ?? $in->input('court_id', '')));
        $facility = $this->scope->portalFacility($user, trim((string)($in->query('facility_id') ?? $in->input('facility_id', ''))));
        if ($facility === null) {
            return $this->jsonError('No facility context found.', 403);
        }
        if ($courtId !== '' && (string)($this->facilities->getCourtById($courtId)['facility_id'] ?? '') !== (string)$facility['id']) {
            return $this->jsonError('Court not found at this facility.', 403);
        }
        $roster = $this->openPlay->getOpenPlayRoster($facility['id'], $courtName, $courtId);

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

        return $this->json(['success' => true, 'requests' => $this->bookings->getPendingBookingRequests($facility['id'])]);
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
        $booking = $this->bookings->getBookingById($bookingId);
        if (!$booking) {
            return $this->jsonError('No booking found for this code.', 404);
        }
        if (empty($user['is_admin']) && !$this->bookings->verifyBookingOwner($bookingId, $user['id'])) {
            return $this->jsonError('This pass is not for a booking at your facility.', 403);
        }
        $player = $this->users->getUserById((string)($booking['user_id'] ?? ''));
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
        if (Schedule::isBookingPast($booking)) {
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
        if (!$user) {
            return $this->jsonError('Unauthorized: Owner access required', 403);
        }
        $courtId = trim((string)$in->input('court_id', ''));
        $status = (string)$in->input('status', 'available');
        if (!in_array($status, ['available', 'occupied', 'maintenance'], true)) {
            return $this->jsonError('Invalid status value', 400);
        }
        if ($courtId === '' || !$this->facilities->getCourtById($courtId)) {
            return $this->jsonError('Court not found', 404);
        }
        if (!$this->scope->canManageCourt($user, $courtId)) {
            return $this->jsonError('Unauthorized: You do not own this court', 403);
        }
        $this->facilities->updateCourtStatus($courtId, $status);

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
        if (empty($user['is_admin']) && !$this->bookings->verifyBookingOwner($bookingId, $user['id'])) {
            return $this->jsonError('Unauthorized: You do not own the facility for this booking', 403);
        }
        $confirmed = $this->bookings->confirmBookingRequest($bookingId, ['actor_id' => (string)$user['id']]);
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
        if (empty($user['is_admin']) && !$this->bookings->verifyBookingOwner($bookingId, $user['id'])) {
            return $this->jsonError('Unauthorized: You do not own the facility for this booking', 403);
        }
        $booking = $this->bookings->getBookingById($bookingId);
        if (!$booking) {
            return $this->jsonError('Booking record not found', 404);
        }
        if (($booking['status'] ?? '') === 'cancelled') {
            return $this->jsonError('Booking is already cancelled', 400);
        }
        $reason = trim((string)$in->input('reason', ''));
        if (mb_strlen($reason) > 500) {
            return $this->jsonError('Keep the decline reason under 500 characters.', 400);
        }
        try {
            $outcome = $this->bookings->cancelBookingAsStaff($bookingId, [
                'allowed_from' => ['pending', 'upcoming', 'confirmed'],
                'can_cancel' => fn(array $booking): bool => in_array($booking['status'], ['pending', 'upcoming'], true)
                    || !$this->bookings->hasBookingStarted($booking),
                'actor_id' => (string)$user['id'],
                'reason' => $reason !== '' ? $reason : null,
            ]);
        } catch (\Throwable $e) {
            return $this->jsonError('Failed to process decline. Please try again.', 500);
        }
        if (!$outcome['ok']) {
            return $this->jsonError($outcome['message'], $outcome['code']);
        }
        $message = 'Reservation declined.';
        if ($outcome['refunded']) {
            $message .= ' ₱' . number_format((float)$outcome['refund_amount'], 2) . ' refunded to Pickle Credits.';
        } elseif ($outcome['external_payment']) {
            $message .= ' No refund was issued in-app; follow up with the facility about ' . ($booking['payment_method'] ?? 'this payment') . '.';
        } elseif (($booking['payment_method'] ?? '') === 'Pay at Venue') {
            $message .= ' Nothing was charged for this Pay at Venue booking.';
        }

        return $this->jsonSuccess([
            'booking_id' => $bookingId, 'status' => 'cancelled', 'refunded' => $outcome['refunded'],
            'refund_amount' => $outcome['refund_amount'], 'external_payment' => $outcome['external_payment'],
        ], $message);
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
        $staffOnly = $in->input('context', '') === 'staff';
        $matched = [];
        foreach ($this->users->getAllUsers() as $u) {
            if (($u['role'] ?? '') === 'deleted' || ($staffOnly && ($u['role'] ?? '') !== 'player')) {
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
