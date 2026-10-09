<?php
declare(strict_types=1);

namespace Picklers\Admin\Controller;

use Picklers\Admin\Http\ActionInput;
use Picklers\Admin\Http\AdminActionException;
use Picklers\Admin\Http\AdminResponder;
use Picklers\Admin\Repository\AccountRepository;
use Picklers\Admin\Security\Capability;
use Picklers\Admin\Service\ApplicationReviewService;
use Picklers\Admin\Service\BookingAdminService;
use Picklers\Admin\Service\FacilityAdminService;
use Picklers\Admin\Service\ListQuery;
use Picklers\Admin\Service\MetricsService;
use Picklers\Admin\Service\ModerationService;
use Picklers\Admin\Service\NotificationAdminService;
use Picklers\Admin\Service\PromoAdminService;
use Picklers\Admin\Service\UserAdminService;
use Picklers\Admin\Service\WalletAdjustmentService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * The admin action endpoint — every legacy `action=admin_*` contract, served by
 * Symfony and delegated to injected services (no business rules live here).
 *
 *   POST /admin | /admin.php | /admin/api     mutations (CSRF required)
 *   GET  /admin/api?action=admin_get_…       read-only actions
 *
 * Each action declares its capability and whether it mutates; mutations require
 * POST and a valid CSRF token (header X-CSRF-Token or field csrf_token).
 */
final class ActionController extends AbstractAdminController
{
    /** action => [capability, mutates] */
    private const ACTIONS = [
        // Control Center
        'admin_stats' => [Capability::VIEW_CONSOLE, false],
        'admin_get_activity' => [Capability::VIEW_CONSOLE, false],
        'admin_mark_activity_seen' => [Capability::VIEW_CONSOLE, true],
        // Partner applications
        'admin_get_application' => [Capability::REVIEW_APPLICATIONS, false],
        'admin_approve_owner_application' => [Capability::REVIEW_APPLICATIONS, true],
        'admin_reject_owner_application' => [Capability::REVIEW_APPLICATIONS, true],
        // Facilities & courts
        'admin_get_facility' => [Capability::MANAGE_FACILITIES, false],
        'admin_update_facility' => [Capability::MANAGE_FACILITIES, true],
        'admin_set_facility_status' => [Capability::MANAGE_FACILITIES, true],
        'admin_update_court_status' => [Capability::MANAGE_FACILITIES, true],
        // Bookings
        'admin_get_bookings' => [Capability::MANAGE_BOOKINGS, false],
        'admin_get_booking' => [Capability::MANAGE_BOOKINGS, false],
        'admin_update_booking_status' => [Capability::MANAGE_BOOKINGS, true],
        'admin_cancel_booking' => [Capability::MANAGE_BOOKINGS, true],
        // Users & roles
        'admin_get_users' => [Capability::VIEW_CONSOLE, false],
        'admin_get_user' => [Capability::VIEW_CONSOLE, false],
        'admin_toggle_verify' => [Capability::VERIFY_USERS, true],
        'admin_set_verification' => [Capability::VERIFY_USERS, true],
        'admin_update_role' => [Capability::SET_BASIC_ROLE, true],
        'admin_set_privilege' => [Capability::MANAGE_PRIVILEGE, true],
        'admin_delete_user' => [Capability::DEACTIVATE_USER, true],       // legacy name: deactivate
        'admin_deactivate_user' => [Capability::DEACTIVATE_USER, true],
        'admin_reactivate_user' => [Capability::DEACTIVATE_USER, true],
        'admin_hard_delete_user' => [Capability::DELETE_USER, true],
        'admin_delete_account' => [Capability::DELETE_USER, true],
        'admin_reset_password' => [Capability::RESET_PASSWORD, true],
        'admin_send_notification' => [Capability::NOTIFY_USER, true],
        'admin_impersonate' => [Capability::IMPERSONATE, true],
        // Wallet
        'admin_get_wallet' => [Capability::VIEW_LEDGER, false],
        'admin_adjust_wallet' => [Capability::ADJUST_WALLET, true],
        // Moderation
        'admin_get_moderation_case' => [Capability::MODERATE_CONTENT, false],
        'admin_moderation_flag' => [Capability::MODERATE_CONTENT, true],
        'admin_moderation_hide' => [Capability::MODERATE_CONTENT, true],
        'admin_moderation_restore' => [Capability::MODERATE_CONTENT, true],
        'admin_moderation_resolve' => [Capability::MODERATE_CONTENT, true],
        'admin_moderation_dismiss' => [Capability::MODERATE_CONTENT, true],
        // Promos
        'admin_get_promos' => [Capability::MANAGE_PROMOS, false],
        'admin_get_promo' => [Capability::MANAGE_PROMOS, false],
        'admin_create_promo' => [Capability::MANAGE_PROMOS, true],
        'admin_toggle_promo' => [Capability::MANAGE_PROMOS, true],
        'admin_delete_promo' => [Capability::MANAGE_PROMOS, true],
        // Notifications
        'admin_broadcast_preview' => [Capability::BROADCAST, false],
        'admin_broadcast_notification' => [Capability::BROADCAST, true],
    ];

    public function __construct(
        private readonly AdminResponder $responder,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly MetricsService $metrics,
        private readonly ApplicationReviewService $applications,
        private readonly FacilityAdminService $facilities,
        private readonly BookingAdminService $bookings,
        private readonly UserAdminService $users,
        private readonly AccountRepository $accounts,
        private readonly WalletAdjustmentService $wallet,
        private readonly ModerationService $moderation,
        private readonly PromoAdminService $promos,
        private readonly NotificationAdminService $notifications,
    ) {
    }

    /** @return list<string> every supported action name (route map / tests) */
    public static function actionNames(): array
    {
        return array_keys(self::ACTIONS);
    }

    #[Route('/admin', name: 'admin_action', methods: ['POST'])]
    #[Route('/admin.php', name: 'admin_action_php', methods: ['POST'])]
    #[Route('/admin/api', name: 'admin_api', methods: ['GET', 'POST'])]
    public function handle(Request $request): JsonResponse
    {
        $input = new ActionInput($request);
        $action = $input->action();

        return $this->dispatchAction($request, $input, $action);
    }

    /** Also used by the /api compatibility aliases (same handlers, same checks). */
    public function dispatchAction(Request $request, ActionInput $input, string $action): JsonResponse
    {
        try {
            if (!isset(self::ACTIONS[$action])) {
                throw new AdminActionException($action === '' ? 'Missing action.' : 'Unknown admin action: ' . mb_substr($action, 0, 60), $action === '' ? 400 : 404);
            }
            [$capability, $mutates] = self::ACTIONS[$action];
            if ($mutates && $request->getMethod() !== 'POST') {
                throw new AdminActionException('This action changes data and must be sent with POST.', 405);
            }
            if ($mutates && !$this->csrf->isTokenValid(new CsrfToken('admin', $input->csrfToken()))) {
                $this->auditLog->recordAttempt($action, null, null, 'denied', 'CSRF validation failed');
                throw new AdminActionException('Your security token expired. Refresh the page and try again.', 403, ['csrf' => true]);
            }
            $this->requireCapability($capability, $action);
            $payload = $this->run($action, $input);

            return $this->responder->json(['success' => true] + $payload + ['message' => $payload['message'] ?? 'Done.', 'changed' => $mutates && !($payload['replayed'] ?? false)]);
        } finally {
            $this->auditLog->flushDeferred();
        }
    }

    /** @return array<string,mixed> */
    private function run(string $action, ActionInput $in): array
    {
        $admin = $this->admin();
        $userId = $in->string('user_id');

        return match ($action) {
            'admin_stats' => $this->metrics->summary() + [
                // Legacy keys kept for existing callers, now from the one definition.
                'total_users' => $this->metrics->summary()['active_accounts'],
                'total_bookings' => $this->metrics->summary()['bookings_total'],
            ],
            'admin_get_activity' => ['items' => $this->metrics->recentActivity(10), 'unseen' => $this->metrics->unseenCount($admin->id())],
            'admin_mark_activity_seen' => (function () use ($admin): array {
                $this->metrics->markSeen($admin->id());

                return ['unseen' => 0, 'message' => 'Activity marked as seen.'];
            })(),

            'admin_get_application' => ['application' => $this->applications->detail($in->string('application_id'))],
            'admin_approve_owner_application' => (function () use ($admin, $in, $userId): array {
                $app = $this->applications->resolve($userId, $in->string('application_id'));
                $res = $this->applications->approve($admin, (string)$app['id'], $in->string('reason'));

                return ['user_id' => $app['user_id'], 'facility_id' => $res['facility_id'],
                    'message' => "{$res['facility_name']} has been provisioned and published for {$res['applicant']}."];
            })(),
            'admin_reject_owner_application' => (function () use ($admin, $in, $userId): array {
                $app = $this->applications->resolve($userId, $in->string('application_id'));
                $res = $this->applications->reject($admin, (string)$app['id'], $in->string('reason'));

                return ['user_id' => $app['user_id'], 'message' => "Application rejected; {$res['applicant']} was told why."];
            })(),

            'admin_get_facility' => ['facility' => $this->facilities->detail((int)$in->string('facility_id'))],
            'admin_update_facility' => $this->facilities->updateDetails($admin, (int)$in->string('facility_id'), array_filter([
                'name' => $in->has('name') ? $in->string('name') : null,
                'location' => $in->has('location') ? $in->string('location') : null,
                'hours' => $in->has('hours') ? $in->string('hours') : null,
            ], static fn($v) => $v !== null), $in->string('reason')) + ['message' => 'Facility details updated.'],
            'admin_set_facility_status' => $this->facilityStatus($admin, $in),
            'admin_update_court_status' => $this->courtStatus($admin, $in),

            'admin_get_bookings' => ['bookings' => $this->bookings->search(ListQuery::from($in->all(), [], BookingAdminService::SORTS, 'created_at'))->items],
            'admin_get_booking' => ['booking' => $this->bookings->detail($in->string('booking_id'))],
            'admin_update_booking_status' => ['booking_id' => $in->string('booking_id')]
                + $this->bookings->changeStatus($admin, $in->string('booking_id'), $in->string('status'), $in->string('expected_status'), $in->string('reason')),
            'admin_cancel_booking' => ['booking_id' => $in->string('booking_id')]
                + $this->bookings->cancel($admin, $in->string('booking_id'), $in->string('expected_status'), $in->string('reason')),

            'admin_get_users' => ['users' => $this->accounts->search(ListQuery::from($in->all(), [], ['created_at'], 'created_at'))->items],
            'admin_get_user' => ['user' => $this->users->detail($userId)],
            'admin_toggle_verify' => ['user_id' => $userId] + $this->users->toggleVerification($admin, $userId),
            'admin_set_verification' => ['user_id' => $userId] + $this->users->setVerification($admin, $userId, $in->string('status')),
            'admin_update_role' => $this->users->changeRole($admin, $userId, $in->string('role', 'player')),
            'admin_set_privilege' => $this->users->setPrivilege($admin, $userId, $in->string('grant') === '1', $in->string('reason')),
            'admin_delete_user', 'admin_deactivate_user' => $this->users->deactivate($admin, $userId, $in->string('reason')),
            'admin_reactivate_user' => $this->users->reactivate($admin, $userId, $in->string('reason')),
            'admin_hard_delete_user', 'admin_delete_account' => $this->users->delete($admin, $userId, $in->string('confirm_text'), $in->string('reason')),
            'admin_reset_password' => $this->users->resetPassword($admin, $userId, $in->string('new_password'), $in->string('reason')),
            'admin_send_notification' => $this->notifications->sendToUser($admin, $userId, $in->string('title'), $in->string('message'),
                $in->string('idempotency_key') ?: bin2hex(random_bytes(16))),
            'admin_impersonate' => $this->users->impersonate($admin, $userId, $in->string('reason')),

            'admin_get_wallet' => (function () use ($userId): array {
                $user = $this->users->detail($userId);

                return ['user_id' => $userId, 'balance' => $user['wallet_balance'], 'transactions' => $user['transactions']];
            })(),
            'admin_adjust_wallet' => $this->wallet->adjust($admin, $userId, $in->string('type', 'credit'), $in->string('amount'),
                $in->string('reason') ?: $in->string('label'), $in->string('idempotency_key')),

            'admin_get_moderation_case' => ['case' => $this->moderation->caseDetail($in->string('case_id'))],
            'admin_moderation_flag' => $this->moderation->flag($admin, $in->string('content_type'), $in->string('content_id'), $in->string('category'), $in->string('reason')),
            'admin_moderation_hide' => $this->moderation->hide($admin, $in->string('case_id'), $in->string('reason')),
            'admin_moderation_restore' => $this->moderation->restore($admin, $in->string('case_id'), $in->string('reason')),
            'admin_moderation_resolve' => $this->moderation->close($admin, $in->string('case_id'), 'resolved', $in->string('reason')),
            'admin_moderation_dismiss' => $this->moderation->close($admin, $in->string('case_id'), 'dismissed', $in->string('reason')),

            'admin_get_promos' => ['promos' => $this->promos->search(ListQuery::from($in->all(), [], PromoAdminService::SORTS, 'created_at'))->items, 'stats' => $this->promos->stats()],
            'admin_get_promo' => ['promo' => $this->promos->detail($in->string('promo_id'))],
            'admin_create_promo' => $this->promos->create($admin, $in->all()),
            'admin_toggle_promo' => $this->requireId($in, 'promo_id', fn(string $id) => $this->promos->toggle($admin, $id)),
            'admin_delete_promo' => $this->requireId($in, 'promo_id', fn(string $id) => $this->promos->deleteOrArchive($admin, $id)),

            'admin_broadcast_preview' => ['preview' => $this->notifications->preview(
                NotificationAdminService::audienceFromLegacy($in->string('role_filter', 'all')), $in->string('title'), $in->string('body'))],
            'admin_broadcast_notification' => $this->broadcast($admin, $in),
        };
    }

    private function facilityStatus($admin, ActionInput $in): array
    {
        $res = $this->facilities->setStatus($admin, (int)$in->string('facility_id'), $in->string('status'), $in->string('reason'));
        $n = count($res['affected_bookings']);

        return $res + ['message' => "{$res['facility']} is now {$res['status']}." . ($n > 0 ? " {$n} upcoming booking(s) were left unchanged — review them below." : '')];
    }

    private function courtStatus($admin, ActionInput $in): array
    {
        $courtId = $in->string('court_id');
        if ($courtId === '') {
            throw AdminActionException::notFound('Court');
        }
        $res = $this->facilities->setCourtStatus($admin, $courtId, $in->string('status', 'available'), $in->string('reason'));
        $n = count($res['affected_bookings']);

        return ['court_id' => $courtId] + $res + ['message' => "{$res['court']} is now {$res['status']}." . ($n > 0 ? " {$n} upcoming booking(s) on this court were left unchanged — review them." : '')];
    }

    private function broadcast($admin, ActionInput $in): array
    {
        $audience = NotificationAdminService::audienceFromLegacy($in->string('role_filter', 'all'));
        if (!$in->has('confirm_count')) {
            $count = $this->notifications->audienceCount($audience);
            throw new AdminActionException("Confirm the audience first: this would notify {$count} account(s). Preview the broadcast, then send it with that count.", 422, ['count' => $count]);
        }

        return $this->notifications->broadcast($admin, $audience, $in->string('title'), $in->string('body'), $in->string('type', 'system'),
            (int)$in->string('confirm_count'), $in->string('idempotency_key'));
    }

    /** @param callable(string):array $fn */
    private function requireId(ActionInput $in, string $key, callable $fn): array
    {
        $id = $in->string($key);
        if ($id === '') {
            throw AdminActionException::invalid("Missing {$key}.");
        }

        return $fn($id);
    }
}
