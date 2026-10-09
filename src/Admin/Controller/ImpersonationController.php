<?php
declare(strict_types=1);

namespace Picklers\Admin\Controller;

use Picklers\Admin\Http\AdminActionException;
use Picklers\Admin\Http\AdminResponder;
use Picklers\Admin\Security\ImpersonationManager;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * "Return to admin" — the only admin endpoint that answers while impersonating.
 * Posted from the banner on every player/owner page (token id `impersonation_stop`)
 * or from the console's own notice page (token id `admin`).
 */
final class ImpersonationController extends AbstractAdminController
{
    public function __construct(
        private readonly ImpersonationManager $impersonation,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly AdminResponder $responder,
    ) {
    }

    #[Route('/admin/impersonation/stop', name: 'admin_impersonation_stop', methods: ['POST'])]
    public function stop(Request $request): Response
    {
        $token = (string)($request->request->get('_token') ?? $request->headers->get('X-CSRF-Token', ''));
        if (!$this->csrf->isTokenValid(new CsrfToken('impersonation_stop', $token)) && !$this->csrf->isTokenValid(new CsrfToken('admin', $token))) {
            throw new AdminActionException('Your security token expired. Refresh the page and press “Return to admin” again.', 403);
        }
        $actorId = $this->impersonation->stop();
        if ($this->responder->wantsJson($request) && $request->headers->get('X-Requested-With') === 'XMLHttpRequest') {
            return $this->responder->json(['success' => true, 'ended' => $actorId !== null, 'message' => 'Back in your administrator account.']);
        }

        return new RedirectResponse($this->generateUrl('admin_console', ['tab' => 'users']));
    }
}
