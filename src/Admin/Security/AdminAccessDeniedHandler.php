<?php
declare(strict_types=1);

namespace Picklers\Admin\Security;

use Picklers\Admin\Http\AdminResponder;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Authorization\AccessDeniedHandlerInterface;
use Twig\Environment;

/**
 * 403 handling: JSON for action/API callers; for page navigation a signed-in
 * non-administrator is sent back to the player app (the legacy behaviour), and
 * an administrator lacking a capability gets an explanatory 403 page.
 */
final class AdminAccessDeniedHandler implements AccessDeniedHandlerInterface
{
    public function __construct(
        private readonly AdminResponder $responder,
        private readonly Security $security,
        private readonly Environment $twig,
    ) {
    }

    public function handle(Request $request, AccessDeniedException $accessDeniedException): ?Response
    {
        $isAdmin = $this->security->isGranted('ROLE_ADMIN');
        $message = $isAdmin
            ? 'Your administrator account does not have permission for this action.'
            : 'Administrator access is required.';

        if ($this->responder->wantsJson($request)) {
            return $this->responder->error($message, Response::HTTP_FORBIDDEN);
        }
        if (!$isAdmin) {
            return new RedirectResponse($this->responder->legacyUrl($request, 'app?error=unauthorized'));
        }

        return new Response($this->twig->render('admin/error.html.twig', [
            'status' => 403,
            'title' => 'Not permitted',
            'message' => $message,
            'app_url' => $this->responder->legacyUrl($request, 'app'),
        ]), Response::HTTP_FORBIDDEN);
    }
}
