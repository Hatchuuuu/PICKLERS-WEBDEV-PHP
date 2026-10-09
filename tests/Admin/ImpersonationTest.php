<?php
declare(strict_types=1);

namespace Picklers\Tests\Admin;


/** R13 — governed impersonation. */
final class ImpersonationTest extends AdminWebTestCase
{
    private function start(string $target = 'usr_fx_p1', string $reason = 'Reproduce booking display bug'): array
    {
        return $this->action('admin_impersonate', ['user_id' => $target, 'reason' => $reason]);
    }

    public function testOnlyPrivilegedAdminsWithReasonAndEligibleTargets(): void
    {
        $this->signIn('usr_fx_admin');
        self::assertSame(403, $this->start()['status']);
        $this->signIn('usr_fx_root');
        self::assertSame(422, $this->start('usr_fx_p1', '')['status'], 'reason required');
        self::assertSame(403, $this->start('usr_fx_admin')['status'], 'administrators cannot be impersonated');
        self::assertSame(409, $this->start('usr_fx_gone')['status'], 'inactive accounts cannot be impersonated');
        self::assertSame(403, $this->start('usr_fx_root')['status'], 'not yourself');
    }

    public function testStartBannerRestrictionsAndReturn(): void
    {
        $this->signIn('usr_fx_root');
        $r = $this->start();
        self::assertSame(200, $r['status'], json_encode($r['json']));
        self::assertSame('usr_fx_p1', $_SESSION['_sf2_attributes']['picklers_user_id'], 'the app now renders as the target');
        self::assertSame('usr_fx_root', $_SESSION['_sf2_attributes']['picklers_impersonation']['actor_id'], 'original actor preserved');
        self::assertNotNull($this->lastAudit('impersonation.start'));

        // Player side: identity is the target, the banner shows, sensitive actions are blocked.
        self::assertSame('usr_fx_p1', $this->webUser()['id']);
        $this->client->request('GET', '/app');
        self::assertSelectorTextContains('[aria-label="Administrator viewing as another user"]', 'Viewing as Paula Player');
        foreach (['change_password', 'top_up'] as $restricted) {
            self::assertSame('This action is not available while an administrator is viewing as this user.', $this->web($restricted)['json']['message'] ?? null, $restricted);
        }
        self::assertNotSame(403, $this->web('like_post', ['post_id' => 'fx_post2'])['status'], 'ordinary actions stay available');

        // The console is closed (no nested impersonation, no admin mutations).
        $this->client->request('GET', '/admin');
        self::assertResponseStatusCodeSame(403);
        self::assertSelectorTextContains('h1', 'You are viewing the app as');
        self::assertSame(403, $this->action('admin_impersonate', ['user_id' => 'usr_fx_p2', 'reason' => 'Nested attempt'])['status']);
        self::assertSame(403, $this->action('admin_stats')['status']);

        // Return to admin (banner form token).
        $token = bin2hex(random_bytes(16));
        $_SESSION['_sf2_attributes']['_csrf/impersonation_stop'] = $token;
        $this->client->request('POST', '/admin/impersonation/stop', ['_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/admin/impersonation/stop', ['_token' => $token]);
        self::assertResponseRedirects('/admin?tab=users');
        self::assertSame('usr_fx_root', $_SESSION['_sf2_attributes']['picklers_user_id']);
        self::assertArrayNotHasKey('picklers_impersonation', $_SESSION['_sf2_attributes'] ?? []);
        self::assertNotNull($this->lastAudit('impersonation.end'));
        self::assertSame(200, $this->action('admin_stats')['status']);
    }

    public function testExpiryRestoresTheAdministrator(): void
    {
        $this->signIn('usr_fx_root');
        $this->start();
        $_SESSION['_sf2_attributes']['picklers_impersonation']['expires_at'] = time() - 1;
        self::assertSame('usr_fx_root', $this->webUser()['id']);
        self::assertNotNull($this->lastAudit('impersonation.expired'));
    }

    public function testRevokedPrivilegeEndsTheSessionEntirely(): void
    {
        $this->signIn('usr_fx_root');
        $this->start();
        self::pdo()->exec("DELETE FROM admin_privileges WHERE user_id = 'usr_fx_root'");
        self::assertNull($this->webUser());
        self::assertArrayNotHasKey('picklers_user_id', $_SESSION['_sf2_attributes'] ?? []);
    }

    public function testActionsWhileImpersonatingAreAudited(): void
    {
        $this->signIn('usr_fx_root');
        $this->start();
        // Each change is recorded once the response status is known.
        self::assertSame(200, $this->web('like_post', ['post_id' => 'fx_post2'])['status']);
        self::assertSame(400, $this->web('create_post', ['content' => ''])['status']);
        $rows = self::pdo()->query("SELECT actor_user_id, effective_user_id, outcome, changes FROM audit_events WHERE action = 'impersonation.action' ORDER BY id DESC LIMIT 2")->fetchAll();
        self::assertSame('usr_fx_root', $rows[0]['actor_user_id'], 'recorded against the original administrator');
        self::assertSame('usr_fx_p1', $rows[0]['effective_user_id']);
        self::assertSame('failure', $rows[0]['outcome']);
        self::assertStringContainsString('create_post', $rows[0]['changes']);
        self::assertSame('success', $rows[1]['outcome']);
    }
    /** POST a player API action with the app's CSRF token. */
    private function web(string $action, array $params = []): array
    {
        $_SESSION['_sf2_attributes']['_csrf/picklers'] = 'web-test-token';
        $this->client->request('POST', '/api.php?action=' . $action, $params, [], ['HTTP_X_CSRF_TOKEN' => 'web-test-token', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        $r = $this->client->getResponse();

        return ['status' => $r->getStatusCode(), 'json' => json_decode((string)$r->getContent(), true) ?? []];
    }
}
