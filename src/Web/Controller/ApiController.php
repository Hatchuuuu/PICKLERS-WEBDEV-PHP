<?php
declare(strict_types=1);

namespace Picklers\Web\Controller;

use Picklers\Admin\Controller\ActionController;
use Picklers\Admin\Http\ActionInput;
use Picklers\Web\Security\RateLimiter;
use Picklers\Web\Api\CommunityActions;
use Picklers\Web\Api\OwnerActions;
use Picklers\Web\Api\PlayerActions;
use Picklers\Web\Api\TournamentActions;
use Picklers\Web\Http\LegacyInput;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The app's API entry point, /api?action=… (and /api.php), which app.js,
 * owner.js and tournament.js call.
 *
 * - `admin_*` and `switch_user` run through the admin firewall, CSRF, capability
 *   checks and audit trail of /admin/api (`switch_user` is the old impersonation
 *   name; it maps to governed impersonation).
 * - Discover and Account actions are forwarded to their REST controllers, which
 *   apply their own checks.
 * - Every other action runs the API's shared checks first, in this order:
 *   CSRF (by method and by action, so a crafted GET cannot mutate), the
 *   impersonation audit and restriction, then the per-action rate limit.
 */
final class ApiController extends AbstractWebController
{
    /** Mutating actions that need a CSRF token whatever the HTTP method. */
    private const CSRF_ACTIONS = [
        'book_court', 'join_match', 'cancel_booking', 'top_up',
        'approve_booking', 'decline_booking', 'update_court_status', 'owner_update_court',
        'create_post', 'send_message', 'like_post', 'add_comment',
        'create_tournament', 'update_tournament', 'delete_tournament',
        'add_tournament_team', 'add_tournament_player', 'add_tournament_entrant',
        'update_tournament_team', 'remove_tournament_team', 'remove_tournament_player',
        'mix_tournament_teams', 'generate_tournament_bracket', 'reset_tournament_bracket',
        'report_tournament_match', 'reset_tournament_match',
    ];

    /**
     * 20 per minute per account+IP. report_tournament_match and the add_tournament_*
     * entrant actions are deliberately absent: a live 32-team draw fires them in bursts.
     */
    private const RATE_LIMITED = [
        'book_court', 'join_match', 'cancel_booking',
        'create_post', 'send_message', 'like_post', 'add_comment',
        'top_up',
        'create_tournament', 'update_tournament', 'delete_tournament',
        'generate_tournament_bracket', 'mix_tournament_teams',
        'add_tournament_team', 'add_tournament_player', 'add_tournament_entrant',
        'report_tournament_match',
    ];

    public function __construct(
        private readonly ActionController $adminActions,
        private readonly PlayerActions $player,
        private readonly CommunityActions $community,
        private readonly OwnerActions $owner,
        private readonly TournamentActions $tournament,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.debug%')] private readonly bool $debug,
    ) {
    }

    #[Route('/api', name: 'api', methods: ['GET', 'POST'])]
    #[Route('/api.php', name: 'api_php', methods: ['GET', 'POST'])]
    public function handle(Request $request): Response
    {
        $adminInput = new ActionInput($request);
        $action = $adminInput->action();

        if ($action === 'switch_user' || str_starts_with($action, 'admin_')) {
            return $this->adminActions->dispatchAction($request, $adminInput, $action === 'switch_user' ? 'admin_impersonate' : $action);
        }
        if (($rest = $this->restRoute($action, $adminInput)) !== null) {
            // forward() replaces the query string unless it is passed on explicitly.
            return $this->forward($rest[0], $rest[1], $request->query->all());
        }

        $handler = $this->handler($action);
        if ($handler === null) {
            return $this->legacyError('Invalid action: ' . htmlspecialchars($action), 400);
        }

        $in = new LegacyInput($request);
        $user = $this->sessionUser()?->row();
        $mutating = in_array($request->getMethod(), ['POST', 'PUT', 'PATCH', 'DELETE'], true) || in_array($action, self::CSRF_ACTIONS, true);
        if ($mutating && !$this->validCsrf($request)) {
            return $this->legacyError('CSRF security verification failed.', 403);
        }
        if ($mutating) {
            // Registered first so refused attempts are audited too (outcome = HTTP status).
            $this->impersonation()->audit('api', $action);
        }
        if ($this->impersonation()->isRestricted($action)) {
            return $this->legacyError('This action is not available while an administrator is viewing as this user.', 403);
        }
        if (in_array($action, self::RATE_LIMITED, true)) {
            $identity = ($user['id'] ?? 'guest') . '|' . ($request->getClientIp() ?? 'unknown');
            if (RateLimiter::tooMany("api.$action", 20, 60, $identity)) {
                return $this->legacyError('Too many requests. Please slow down.', 429);
            }
        }

        try {
            return $handler($in, $user);
        } catch (\Throwable $e) {
            // A TypeError also returns JSON; exception text (which can hold SQL) stays in the log.
            $this->logger->error('[PICKLERS API] action={action} failed: {message}', ['action' => $action, 'message' => $e->getMessage(), 'exception' => $e]);

            return $this->legacyError($this->debug ? $e->getMessage() : 'Something went wrong while processing your request. Please try again.', 500);
        }
    }

    /** @return array{0:string,1:array<string,string>}|null Discover/Account actions and their REST controller. */
    private function restRoute(string $action, ActionInput $in): ?array
    {
        $discover = DiscoverController::class . '::';
        $account = AccountController::class . '::';

        return match ($action) {
            'facilities' => [$discover . 'list', []],
            'facility_detail' => [$discover . 'detail', ['id' => $in->string('id')]],
            'slot_availability', 'court_availability' => [$discover . 'slots', ['id' => $in->string('facility_id', $in->string('id')), 'courtId' => $in->string('court_id')]],
            'toggle_favorite_facility' => [$discover . 'favorite', ['id' => $in->string('facility_id')]],
            'me' => [$account . 'me', []],
            'change_password' => [$account . 'changePassword', []],
            'update_profile' => [$account . 'updateProfile', []],
            'verify_identity' => [$account . 'requestVerification', []],
            'delete_own_account' => [$account . 'deleteAccount', []],
            'logout' => [$account . 'logout', []],
            'mark_notifications_read' => [$account . 'markNotificationsRead', []],
            'delete_notification' => [$account . 'deleteNotification', ['id' => $in->string('id')]],
            default => null,
        };
    }

    /** @return (callable(LegacyInput, ?array): JsonResponse)|null */
    private function handler(string $action): ?callable
    {
        return match ($action) {
            'sync' => $this->player->sync(...),
            'my_tournaments' => $this->player->myTournaments(...),
            'matches' => $this->player->matches(...),
            'join_match' => $this->player->joinMatch(...),
            'book_court' => $this->player->bookCourt(...),
            'quote_booking' => $this->player->quoteBooking(...),
            'bookings' => $this->player->bookings(...),
            'cancel_booking' => $this->player->cancelBooking(...),
            'wallet' => $this->player->wallet(...),
            'top_up' => $this->player->topUp(...),
            'feed_posts' => $this->community->feedPosts(...),
            'create_post' => $this->community->createPost(...),
            'like_post' => $this->community->likePost(...),
            'add_comment' => $this->community->addComment(...),
            'messages' => $this->community->messages(...),
            'send_message' => $this->community->sendMessage(...),
            'get_open_play_roster' => $this->owner->openPlayRoster(...),
            'get_pending_requests' => $this->owner->pendingRequests(...),
            'verify_checkin' => $this->owner->verifyCheckin(...),
            'update_court_status', 'owner_update_court' => $this->owner->updateCourtStatus(...),
            'approve_booking' => $this->owner->approveBooking(...),
            'decline_booking' => $this->owner->declineBooking(...),
            'search_players', 'search_users' => $this->owner->searchUsers(...),
            'list_tournaments' => $this->tournament->list(...),
            'get_tournament' => $this->tournament->get(...),
            'create_tournament' => $this->tournament->create(...),
            'update_tournament' => $this->tournament->update(...),
            'delete_tournament' => $this->tournament->delete(...),
            'add_tournament_player', 'add_tournament_team', 'add_tournament_entrant' => $this->tournament->addEntrant(...),
            'update_tournament_team' => $this->tournament->updateTeam(...),
            'remove_tournament_team' => $this->tournament->removeTeam(...),
            'remove_tournament_player' => $this->tournament->removePlayer(...),
            'mix_tournament_teams' => $this->tournament->mixTeams(...),
            'generate_tournament_bracket' => $this->tournament->generateBracket(...),
            'reset_tournament_bracket' => $this->tournament->resetBracket(...),
            'report_tournament_match' => $this->tournament->reportMatch(...),
            'reset_tournament_match' => $this->tournament->resetMatch(...),
            default => null,
        };
    }
}
