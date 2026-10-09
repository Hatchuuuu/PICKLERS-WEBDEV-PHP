<?php
declare(strict_types=1);

namespace Picklers\Admin\Controller;

use Picklers\Admin\Http\AdminResponder;
use Picklers\Admin\Security\Capability;
use Picklers\Admin\Service\ApplicationReviewService;
use Picklers\Admin\Service\BookingAdminService;
use Picklers\Admin\Service\FacilityAdminService;
use Picklers\Admin\Service\MetricsService;
use Picklers\Admin\Service\ModerationService;
use Picklers\Admin\Service\PanelBuilder;
use Picklers\Admin\Service\PromoAdminService;
use Picklers\Admin\Service\UserAdminService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Admin console pages (Twig). `/admin` and `/admin.php` keep their `?tab=` links.
 * `/admin/panel/{tab}` returns just one panel so filters, pagination and
 * post-action refreshes update in place (with history) instead of reloading.
 */
final class ConsoleController extends AbstractAdminController
{
    public function __construct(
        private readonly PanelBuilder $panels,
        private readonly MetricsService $metrics,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly AdminResponder $responder,
    ) {
    }

    #[Route('/admin', name: 'admin_console', methods: ['GET'])]
    #[Route('/admin.php', name: 'admin_console_php', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->requireCapability(Capability::VIEW_CONSOLE, 'console.view');
        $admin = $this->admin();
        $tab = PanelBuilder::normalizeTab((string)$request->query->get('tab', 'overview'));

        return $this->render('admin/console.html.twig', [
            'admin' => $admin,
            'panel' => $this->panels->build($tab, $request->query->all(), $admin),
            'badges' => $this->panels->badges(),
            'unseen' => $this->metrics->unseenCount($admin->id()),
            'csrf_token' => $this->csrf->getToken('admin')->getValue(),
            'search_scopes' => PanelBuilder::SEARCH_SCOPES,
            'app_url' => $this->responder->legacyUrl($request, 'app'),
            'logout_url' => $this->responder->legacyUrl($request, 'logout'),
        ]);
    }

    /** Record detail rendered server-side (escaped Twig) and shown in a dialog. */
    #[Route('/admin/detail/{type}/{id}', name: 'admin_detail', methods: ['GET'], requirements: ['type' => 'application|facility|booking|user|moderation|promo', 'id' => '[A-Za-z0-9_\-]{1,80}'])]
    public function detail(string $type, string $id, ApplicationReviewService $applications, FacilityAdminService $facilities,
        BookingAdminService $bookings, UserAdminService $users, ModerationService $moderation, PromoAdminService $promos): Response
    {
        [$capability, $loader] = match ($type) {
            'application' => [Capability::REVIEW_APPLICATIONS, fn() => $applications->detail($id)],
            'facility' => [Capability::MANAGE_FACILITIES, fn() => $facilities->detail((int)$id)],
            'booking' => [Capability::MANAGE_BOOKINGS, fn() => $bookings->detail($id)],
            'user' => [Capability::VIEW_CONSOLE, fn() => $users->detail($id)],
            'moderation' => [Capability::MODERATE_CONTENT, fn() => $moderation->caseDetail($id)],
            'promo' => [Capability::MANAGE_PROMOS, fn() => $promos->detail($id)],
        };
        $this->requireCapability($capability, 'detail.view', $type, $id);

        return $this->render('admin/details/_' . $type . '.html.twig', ['admin' => $this->admin(), 'record' => $loader()]);
    }

    #[Route('/admin/panel/{tab}', name: 'admin_panel', methods: ['GET'], requirements: ['tab' => 'overview|applications|facilities|bookings|users|moderation|ledger|promos|analytics|system'])]
    public function panel(Request $request, string $tab): Response
    {
        $this->requireCapability(Capability::VIEW_CONSOLE, 'console.view');
        $admin = $this->admin();
        $response = $this->render('admin/panels/_' . $tab . '.html.twig', [
            'admin' => $admin,
            'panel' => $this->panels->build($tab, $request->query->all(), $admin),
        ]);
        $response->headers->set('X-Admin-Badges', (string)json_encode($this->panels->badges()));
        $response->headers->set('X-Search-Scope', PanelBuilder::SEARCH_SCOPES[$tab] ?? '');

        return $response;
    }
}
