<?php
declare(strict_types=1);

namespace Picklers\Web\Api;

use Picklers\Core\Database;
use Picklers\Domain\BookingRules;
use Picklers\Repositories\BookingRepository;
use Picklers\Repositories\MatchRepository;
use Picklers\Repositories\NotificationRepository;
use Picklers\Repositories\WalletRepository;
use Picklers\Services\AuthService;
use Picklers\Services\PricingService;
use Picklers\Services\TournamentService;
use Picklers\Web\Http\LegacyInput;
use Picklers\Web\Http\LegacyResponses;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;

/** Player API: sync poll, Open Play, court bookings, quotes and the wallet. */
final class PlayerActions
{
    use LegacyResponses;

    /** Payment methods the platform accepts. Anything else is rejected outright. */
    public const ALLOWED_PAYMENT_METHODS = ['Pickle Credits', 'GCash', 'Maya', 'Card', 'Pay at Venue'];

    /** Labels the checkout UI sends for an accepted method ("Cash on Site" is paying at the venue). */
    public const PAYMENT_METHOD_ALIASES = ['Cash on Site' => 'Pay at Venue'];

    public function __construct(
        private readonly Database $db,
        private readonly AuthService $auth,
        private readonly BookingRepository $bookings,
        private readonly MatchRepository $matches,
        private readonly NotificationRepository $notifications,
        private readonly PricingService $pricing,
        private readonly TournamentService $tournaments,
        private readonly WalletRepository $wallet,
        #[Autowire('%kernel.environment%')] private readonly string $environment,
        #[Autowire(env: 'default::PAYMENTS_SIMULATED')] private readonly ?string $paymentsSimulated = null,
    ) {
    }

    /**
     * A cheap poll target: clients compare these versions with what they hold and
     * re-fetch only what moved. For a signed-in player it also carries the
     * "your time is up" alert, the unread count and their own bookings/wallet.
     */
    public function sync(LegacyInput $in, ?array $user): JsonResponse
    {
        $payload = ['success' => true, 'versions' => $this->db->getSyncVersions(), 'server_time' => time()];
        if ($user) {
            $payload['ended_sessions'] = $this->db->checkAndNotifySessionEnd((string)$user['id']);
            $unread = $this->notifications->getNotifications($user['id']);
            $payload['unread_notifications'] = count(array_filter($unread, fn($n) => empty($n['is_read'])));
            $payload['account'] = $this->db->getAccountSyncState((string)$user['id']);
        }

        return $this->json($payload);
    }

    /**
     * Tournaments this account entered: by user id wherever the roster recorded
     * one, otherwise by exact full name (substring matching showed "Al" every
     * tournament with an "Alex").
     */
    public function myTournaments(LegacyInput $in, ?array $user): JsonResponse
    {
        if (!$user) {
            return $this->jsonError('Authentication required.', 401);
        }
        $myId = (string)$user['id'];
        $myName = strtolower(trim((string)($user['name'] ?? '')));
        $isMe = static fn(string $entryUserId, string $entryName): bool => $entryUserId !== ''
            ? $entryUserId === $myId
            : $myName !== '' && strtolower(trim($entryName)) === $myName;

        $mine = [];
        foreach ($this->tournaments->all() as $t) {
            $found = false;
            foreach ((array)($t['teams'] ?? []) as $team) {
                if ($isMe((string)($team['player1_id'] ?? ''), (string)($team['player1'] ?? ''))
                    || $isMe((string)($team['player2_id'] ?? ''), (string)($team['player2'] ?? ''))) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                foreach ((array)($t['players_pool'] ?? []) as $player) {
                    if ($isMe((string)($player['user_id'] ?? ''), (string)($player['name'] ?? ''))) {
                        $found = true;
                        break;
                    }
                }
            }
            if ($found) {
                // Other entrants' contact details are the host's, not this player's.
                $t['players_pool'] = array_map(static function ($p) {
                    unset($p['email']);

                    return $p;
                }, (array)($t['players_pool'] ?? []));
                $mine[] = $t;
            }
        }

        return $this->jsonSuccess(['tournaments' => $mine]);
    }

    public function matches(LegacyInput $in, ?array $user): JsonResponse
    {
        return $this->json(['success' => true, 'matches' => $this->matches->getMatches((string)$in->query('level', 'All'), (string)$in->query('facility', ''))]);
    }

    public function joinMatch(LegacyInput $in, ?array $user): JsonResponse
    {
        if (!$user) {
            return $this->jsonError('Please log in to join Open Play', 401);
        }
        $method = $this->paymentMethod((string)$in->input('payment_method', 'GCash'));
        if ($method === null) {
            return $this->jsonError('Unsupported payment method.', 400);
        }
        // The entry fee comes from the match record; a client-supplied price is never trusted.
        $promo = trim((string)$in->input('promo_code', ''));

        return $this->json($this->matches->joinMatch((string)$in->input('match_id', ''), $user['id'], $method, $promo !== '' ? $promo : null));
    }

    public function bookCourt(LegacyInput $in, ?array $user): JsonResponse
    {
        if (!$user) {
            return $this->jsonError('Please log in to book a court', 401);
        }
        $facilityId = self::facilityId($in->input('facility_id', ''));
        // court_id is the authoritative identity; court_name is a fallback for callers without one.
        $courtId = trim((string)$in->input('court_id', ''));
        $courtName = (string)$in->input('court_name', 'Court 1');
        $date = trim((string)$in->input('date', ''));
        $time = trim((string)$in->input('time', ''));
        $method = $this->paymentMethod((string)$in->input('payment_method', 'Pickle Credits'));
        if ($method === null) {
            return $this->jsonError('Unsupported payment method.', 400);
        }
        if ($date === '' || $time === '') {
            return $this->jsonError('Please choose a date and time slot.', 400);
        }

        // Priced server-side from the court's rate, for the slot's real length:
        // a long slot sent with duration=1 must not be paid as one hour.
        $duration = $this->pricing->normalizeDuration($in->input('duration', 1));
        $slot = BookingRules::parseTimeRange($time);
        if ($slot === null) {
            return $this->jsonError('Please choose a valid time slot.', 400);
        }
        $slotMinutes = $slot[1] - $slot[0];
        if ($slotMinutes % 60 !== 0 || intdiv($slotMinutes, 60) !== $duration) {
            return $this->jsonError('The selected time slot does not match the booking duration. Please reselect your time.', 400);
        }
        $promo = trim((string)$in->input('promo_code', ''));
        $quote = $this->pricing->quoteCourtBooking($facilityId, $courtName, $duration, $promo !== '' ? $promo : null, $user['id'], $courtId);
        if (empty($quote['success'])) {
            return $this->jsonError((string)($quote['message'] ?? 'Unable to price this booking.'), 400);
        }

        // The promo redemption is recorded inside createBooking()'s transaction.
        $res = $this->bookings->createBooking(
            $user['id'], $facilityId, $courtName, $date, $time,
            $duration, (float)$quote['total'], $method,
            $courtId, $promo !== '' ? $promo : null, (float)($quote['discount'] ?? 0)
        );
        if (!empty($res['success'])) {
            $res['quote'] = array_intersect_key($quote, array_flip(['rate', 'court_fee', 'service_fee', 'discount', 'promo_label', 'total']));
        }

        return $this->json($res);
    }

    /** The checkout's authoritative numbers (live promo validation), same rules as the charge. */
    public function quoteBooking(LegacyInput $in, ?array $user): JsonResponse
    {
        if (!$user) {
            return $this->jsonError('Unauthorized', 401);
        }
        $promo = trim((string)$in->input('promo_code', ''));
        $matchId = trim((string)$in->input('match_id', ''));
        if ($matchId !== '') {
            $match = $this->matches->getMatchById($matchId);
            if (!$match) {
                return $this->jsonError('Open Play session not found.', 404);
            }
            $quote = $this->pricing->quoteMatchJoin($match, $promo !== '' ? $promo : null, $user['id']);
        } else {
            $quote = $this->pricing->quoteCourtBooking(
                self::facilityId($in->input('facility_id', '')),
                (string)$in->input('court_name', 'Court 1'),
                $this->pricing->normalizeDuration($in->input('duration', 1)),
                $promo !== '' ? $promo : null,
                $user['id'],
                trim((string)$in->input('court_id', ''))
            );
        }
        if (empty($quote['success'])) {
            return $this->jsonError((string)($quote['message'] ?? 'Unable to price this booking.'), 400);
        }
        $feedback = $this->pricing->evaluatePromo($promo !== '' ? $promo : null, (float)$quote['court_fee'] + (float)$quote['service_fee'], $user['id']);

        return $this->json(['success' => true, 'quote' => $quote, 'promo_valid' => $feedback['valid'], 'promo_message' => $feedback['message']]);
    }

    public function bookings(LegacyInput $in, ?array $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['success' => false, 'bookings' => []]);
        }
        $status = $in->query('status');

        return $this->json(['success' => true, 'bookings' => $this->bookings->getBookings($user['id'], $status ? (string)$status : null)]);
    }

    public function cancelBooking(LegacyInput $in, ?array $user): JsonResponse
    {
        if (!$user) {
            return $this->jsonError('Unauthorized', 401);
        }
        $res = $this->bookings->cancelBooking((string)$in->input('booking_id', ''), $user['id']);
        if (is_array($res)) {
            return $this->json($res);
        }

        return $this->json(['success' => (bool)$res, 'message' => $res ? 'Booking cancelled' : 'Could not cancel booking']);
    }

    public function wallet(LegacyInput $in, ?array $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['success' => false, 'balance' => 0, 'transactions' => []]);
        }
        $account = $this->auth->getUserById($user['id']);

        return $this->json([
            'success' => true,
            'balance' => (float)($account['wallet_balance'] ?? 0),
            'transactions' => $this->wallet->getWalletTransactions($user['id']),
        ]);
    }

    /**
     * No payment gateway is wired up: crediting a wallet from an unverified client
     * request would mint spending power, so this fails closed in production and
     * only simulates outside it when PAYMENTS_SIMULATED=true.
     */
    public function topUp(LegacyInput $in, ?array $user): JsonResponse
    {
        if (!$user) {
            return $this->jsonError('Unauthorized', 401);
        }
        $amount = (float)($in->input('amount') ?? $in->query('amount', 0));
        $method = trim((string)($in->input('method') ?? $in->query('method', 'GCash'))) ?: 'GCash';
        if ($this->environment === 'prod' || !filter_var($this->paymentsSimulated ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return $this->jsonError('Wallet top-ups are temporarily unavailable while payment processing is being finalised.', 503);
        }
        $res = $this->wallet->topUpWallet($user['id'], $amount, $method);
        if (!empty($res['success'])) {
            $res['simulated'] = true;
        }

        return $this->json($res);
    }

    private function paymentMethod(string $method): ?string
    {
        $method = self::PAYMENT_METHOD_ALIASES[$method] ?? $method;

        return in_array($method, self::ALLOWED_PAYMENT_METHODS, true) ? $method : null;
    }

    private static function facilityId(mixed $raw): int|string
    {
        return ctype_digit((string)$raw) ? (int)$raw : (string)$raw;
    }
}
