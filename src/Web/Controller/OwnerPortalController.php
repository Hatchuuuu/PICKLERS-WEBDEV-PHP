<?php
declare(strict_types=1);

namespace Picklers\Web\Controller;

use Picklers\Web\Api\OwnerPortalActions;
use Picklers\Web\Http\LegacyInput;
use Picklers\Web\Service\OwnerScope;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Owner portal writes: POST owner.php?action=… from owner.js. */
final class OwnerPortalController extends AbstractWebController
{
    public function __construct(
        private readonly OwnerPortalActions $actions,
        private readonly OwnerScope $scope,
    ) {
    }

    #[Route('/owner', name: 'owner_post', methods: ['POST'])]
    #[Route('/owner.php', name: 'owner_post_php', methods: ['POST'])]
    #[Route('/app/owner', name: 'owner_post_app', methods: ['POST'])]
    public function post(Request $request): Response
    {
        $user = $this->sessionUser()?->row();
        if ($user === null) {
            return $this->redirectToPath($request, 'auth.php');
        }
        if (!$this->scope->canUsePortal($user)) {
            return $this->redirectToPath($request, 'owner-application.php?notice=verification_required');
        }

        $in = new LegacyInput($request);
        if (!$this->validCsrf($request)) {
            return $in->wantsJson()
                ? $this->legacyError('Security token invalid or expired. Please refresh the page.', 403)
                : $this->redirectToPath($request, 'owner.php?notice=csrf_error');
        }

        // Impersonation: payout and settings changes are refused; every other
        // owner change is audited against the original administrator.
        $action = (string)$in->input('action', '');
        $this->impersonation()->audit('owner', $action);
        if ($this->impersonation()->isRestricted($action) || ($action === 'update_facility_settings' && $this->impersonation()->state() !== null)) {
            return $this->legacyError('This action is not available while an administrator is viewing as this owner.', 403);
        }

        $handler = match ($action) {
            'add_court' => $this->actions->addCourt(...),
            'edit_court' => $this->actions->editCourt(...),
            'toggle_court_status' => $this->actions->toggleCourtStatus(...),
            'end_court_session' => $this->actions->endCourtSession(...),
            'delete_court' => $this->actions->deleteCourt(...),
            'host_open_play' => $this->actions->hostOpenPlay(...),
            'cancel_open_play' => $this->actions->cancelOpenPlay(...),
            'update_facility_settings' => $this->actions->updateFacilitySettings(...),
            'get_open_play_roster' => $this->actions->openPlayRoster(...),
            'create_tournament' => $this->actions->createTournament(...),
            'add_staff' => $this->actions->addStaff(...),
            'revoke_staff' => $this->actions->revokeStaff(...),
            'request_payout' => $this->actions->requestPayout(...),
            default => null,
        };

        // An unknown or retired action is an error, never a silent success.
        return $handler !== null ? $handler($in, $user) : $this->legacyError('Unknown action.', 400);
    }
}
