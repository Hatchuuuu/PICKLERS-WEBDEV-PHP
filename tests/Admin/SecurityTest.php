<?php
declare(strict_types=1);

namespace Picklers\Tests\Admin;

use Picklers\Admin\Controller\ActionController;
use Picklers\Admin\Http\AdminArea;
use Picklers\Web\Http\InstallBase;
use Picklers\Kernel;
use Symfony\Component\HttpFoundation\Request;

/** R15 / R05 / R13 — authorization, CSRF, methods, sessions and route ownership. */
final class SecurityTest extends AdminWebTestCase
{
    public function testAnonymousPageRedirectsToLegacySignIn(): void
    {
        $this->client->request('GET', '/admin');
        self::assertResponseRedirects('/auth?next=admin');
    }

    public function testAnonymousActionIs401Json(): void
    {
        $r = $this->action('admin_stats', [], 'x');
        self::assertSame(401, $r['status']);
        self::assertFalse($r['json']['success']);
    }

    public function testPlayerAndOwnerAreRefused(): void
    {
        foreach (['usr_fx_p1', 'usr_fx_owner1'] as $id) {
            $this->signIn($id);
            $this->client->request('GET', '/admin');
            self::assertResponseRedirects('/app?error=unauthorized', null, "{$id} page");
            $r = $this->action('admin_stats');
            self::assertSame(403, $r['status'], "{$id} action");
        }
    }

    public function testAdminCanOpenEveryPanel(): void
    {
        $this->signIn('usr_fx_admin');
        foreach (['overview', 'applications', 'facilities', 'bookings', 'users', 'moderation', 'ledger', 'promos', 'analytics', 'system'] as $tab) {
            $this->client->request('GET', '/admin?tab=' . $tab);
            self::assertResponseIsSuccessful("panel {$tab}");
            self::assertSelectorExists('[data-panel-heading]');
        }
        $this->client->request('GET', '/admin.php?tab=users');
        self::assertResponseIsSuccessful('/admin.php alias');
    }

    public function testRoleIsReadFreshFromTheDatabaseEveryRequest(): void
    {
        $this->signIn('usr_fx_admin');
        self::assertSame(200, $this->action('admin_stats')['status']);
        self::pdo()->exec("UPDATE users SET is_admin = 0, role = 'player' WHERE id = 'usr_fx_admin'");
        self::assertSame(403, $this->action('admin_stats')['status'], 'demotion applies on the very next request');
    }

    public function testDeactivatedAdminSessionIsEndedImmediately(): void
    {
        $this->signIn('usr_fx_admin');
        self::pdo()->exec("UPDATE users SET role = 'deleted' WHERE id = 'usr_fx_admin'");
        self::assertSame(401, $this->action('admin_stats')['status']);
        self::assertArrayNotHasKey('picklers_user_id', $_SESSION['_sf2_attributes'] ?? [], 'the shared session was destroyed');
    }

    public function testIdleTimeoutEndsSession(): void
    {
        $this->signIn('usr_fx_admin');
        $_SESSION['_sf2_attributes']['picklers_last_activity'] = time() - 7201;
        self::assertSame(401, $this->action('admin_stats')['status']);
        self::assertSame([], array_intersect_key($_SESSION['_sf2_attributes'] ?? [], ['picklers_user_id' => 1]));
    }

    public function testPasswordChangeSignsOutOlderSessions(): void
    {
        $this->signIn('usr_fx_admin', time() - 600);
        self::pdo()->exec("UPDATE users SET password_changed_at = NOW() WHERE id = 'usr_fx_admin'");
        self::assertSame(401, $this->action('admin_stats')['status']);
    }

    public function testMutationsRequireCsrfAndPost(): void
    {
        $this->signIn('usr_fx_admin');
        $r = $this->action('admin_toggle_promo', ['promo_id' => 'fx_promo_live'], 'not-the-token');
        self::assertSame(403, $r['status']);
        self::assertSame('active', $this->scalar("SELECT status FROM promo_codes WHERE id = 'fx_promo_live'"));
        $this->client->request('GET', '/admin/api', ['action' => 'admin_toggle_promo', 'promo_id' => 'fx_promo_live']);
        self::assertSame(405, $this->client->getResponse()->getStatusCode());
        self::assertNotNull($this->lastAudit('admin_toggle_promo'), 'the CSRF failure was audited as a denied attempt');
    }

    public function testUnknownActionsAndPathsAre404FromSymfony(): void
    {
        $this->signIn('usr_fx_admin');
        self::assertSame(404, $this->action('admin_does_not_exist')['status']);
        $this->client->request('GET', '/admin/no/such/page');
        self::assertResponseStatusCodeSame(404);
    }

    public function testEveryDeclaredActionIsRoutedAndGuarded(): void
    {
        $this->signIn('usr_fx_p1'); // a non-admin must be refused for EVERY action
        foreach (ActionController::actionNames() as $action) {
            self::assertSame(403, $this->action($action)['status'], $action);
        }
    }

    public function testEveryReadActionAnswersForAnAdmin(): void
    {
        $this->signIn('usr_fx_root');
        $fac = (string)$this->scalar("SELECT id FROM facilities WHERE name = 'Fixture Arena Dumaguete'");
        $reads = [
            'admin_stats' => [], 'admin_get_activity' => [], 'admin_get_application' => ['application_id' => 'fx_app_pending1'],
            'admin_get_facility' => ['facility_id' => $fac], 'admin_get_bookings' => ['per_page' => 10], 'admin_get_booking' => ['booking_id' => 'FX-CONF01'],
            'admin_get_users' => [], 'admin_get_user' => ['user_id' => 'usr_fx_p1'], 'admin_get_wallet' => ['user_id' => 'usr_fx_p1'],
            'admin_get_promos' => [], 'admin_get_promo' => ['promo_id' => 'fx_promo_live'],
            'admin_broadcast_preview' => ['role_filter' => 'owner', 'title' => 'T', 'body' => 'B'],
        ];
        foreach ($reads as $action => $params) {
            $r = $this->action($action, $params, null, '/admin/api', 'GET');
            self::assertSame(200, $r['status'], $action . ' ' . json_encode($r['json']));
            self::assertFalse($r['json']['changed'], "{$action} is read-only");
        }
        // Legacy action name admin_delete_user keeps its historical meaning: deactivate.
        $r = $this->action('admin_delete_user', ['user_id' => 'usr_fx_p5', 'reason' => 'Legacy alias check']);
        self::assertSame(200, $r['status']);
        self::assertSame('deleted', $this->scalar("SELECT role FROM users WHERE id = 'usr_fx_p5'"));
        self::assertSame('p5@fixture.test', $this->scalar("SELECT email FROM users WHERE id = 'usr_fx_p5'"));
    }

    public function testLegacyApiAliasesRunThroughSymfonyAuthorization(): void
    {
        $r = $this->action('admin_update_role', ['user_id' => 'usr_fx_p1', 'role' => 'admin'], 'x', '/api');
        self::assertSame(401, $r['status'], 'anonymous /api admin alias');
        $this->signIn('usr_fx_admin');
        $r = $this->action('admin_update_role', ['user_id' => 'usr_fx_p1', 'role' => 'admin'], null, '/api.php');
        self::assertSame(403, $r['status'], 'ordinary admin cannot grant admin, even via /api.php');
        $r = $this->action('admin_toggle_verify', ['user_id' => 'usr_fx_p2'], null, '/api');
        self::assertSame(200, $r['status']);
        self::assertSame('verified', $this->scalar("SELECT verification_status FROM users WHERE id = 'usr_fx_p2'"));
        $r = $this->action('switch_user', ['user_id' => 'usr_fx_p1'], null, '/api');
        self::assertSame(403, $r['status'], 'switch_user maps to governed impersonation (privileged only)');
        self::assertSame('usr_fx_admin', $_SESSION['_sf2_attributes']['picklers_user_id']);
    }

    public function testAdminAreaDecisions(): void
    {
        self::assertTrue(AdminArea::contains('/admin', null));
        self::assertTrue(AdminArea::contains('/admin.php', null));
        self::assertTrue(AdminArea::contains('/admin/anything/unknown', null));
        self::assertTrue(AdminArea::contains('/api', 'admin_stats'));
        self::assertTrue(AdminArea::contains('/api.php', 'switch_user'));
        self::assertFalse(AdminArea::contains('/api', 'book_court'), 'player actions run on the player firewall');
        self::assertFalse(AdminArea::contains('/app', null));
        self::assertFalse(AdminArea::contains('/administrator', null));
    }
    public function testSubdirectoryInstallGeneratesBaseAwareUrls(): void
    {
        $server = InstallBase::server([
            'REQUEST_URI' => '/PICKLERS%20WEBDEV%20PROJECT/admin', 'SCRIPT_NAME' => '/PICKLERS WEBDEV PROJECT/public/index.php',
            'SCRIPT_FILENAME' => dirname(__DIR__, 2) . '/public/index.php', 'REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'localhost',
        ]);
        $request = new Request([], [], [], [], [], $server);
        self::assertSame('/PICKLERS%20WEBDEV%20PROJECT', $request->getBaseUrl());
        self::assertSame('/admin', $request->getPathInfo());
        $_SESSION = [];
        $kernel = new Kernel('test', false);
        $response = $kernel->handle($request);
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/PICKLERS%20WEBDEV%20PROJECT/auth?next=admin', $response->headers->get('Location'));

        $this->signIn('usr_fx_admin');
        $response = $kernel->handle(new Request([], [], [], [], [], $server));
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('href="/PICKLERS%20WEBDEV%20PROJECT/assets/css/admin.css', (string)$response->getContent());
        self::assertStringContainsString('href="/PICKLERS%20WEBDEV%20PROJECT/admin?tab=users"', (string)$response->getContent());
    }

    public function testSecurityHeadersAndCorrelationId(): void
    {
        $this->signIn('usr_fx_admin');
        $r = $this->action('admin_stats');
        $h = $r['response']->headers;
        self::assertSame('nosniff', $h->get('X-Content-Type-Options'));
        self::assertSame('SAMEORIGIN', $h->get('X-Frame-Options'));
        self::assertStringContainsString('no-store', (string)$h->get('Cache-Control'));
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', (string)$h->get('X-Request-Id'));
    }

    public function testErrorsDoNotLeakInternals(): void
    {
        $this->signIn('usr_fx_admin');
        $r = $this->action('admin_get_booking', ['booking_id' => "x' OR 1=1 --"], null, '/admin/api', 'GET');
        self::assertSame(404, $r['status']);
        self::assertStringNotContainsString('SQL', (string)$r['response']->getContent());
        self::assertStringNotContainsString('PDO', (string)$r['response']->getContent());
    }
}
