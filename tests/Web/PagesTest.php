<?php
declare(strict_types=1);

namespace Picklers\Tests\Web;

use Picklers\Tests\Admin\AdminWebTestCase;

/** Landing page, sign-in/registration/sign-out and the player app, served by Symfony. */
final class PagesTest extends AdminWebTestCase
{
    private const TOKEN = 'web-test-token';

    private static function fixturePassword(): string
    {
        preg_match("/const FIXTURE_PASSWORD = '([^']+)'/", (string)file_get_contents(dirname(__DIR__, 2) . '/scripts/seed-fixtures.php'), $m);

        return $m[1];
    }

    /** @return array{status:int,json:array<string,mixed>} */
    private function auth(array $fields, bool $ajax = true): array
    {
        $_SESSION['_sf2_attributes']['_csrf/picklers'] = self::TOKEN;
        $this->client->request('POST', '/auth', $fields + ['csrf_token' => self::TOKEN], [], $ajax ? ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'] : []);
        $r = $this->client->getResponse();

        return ['status' => $r->getStatusCode(), 'json' => json_decode((string)$r->getContent(), true) ?? [], 'response' => $r];
    }

    public function testLandingPageForGuestsAndPlayers(): void
    {
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.hero-title', 'PICKLERS');
        self::assertSelectorExists('a.btn-nav-signup');

        $this->signIn('usr_fx_p1');
        $this->client->request('GET', '/index.php');
        self::assertSelectorTextContains('a.btn-nav-login', 'Open App');
    }

    public function testSignInPageTabsAndFlash(): void
    {
        $this->client->request('GET', '/auth.php', ['intent' => 'signup']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#authHeading', 'Start Playing Pickleball');

        $r = $this->auth(['auth_action' => 'signin', 'identifier' => 'nobody@fixture.test', 'password' => 'x'], false);
        self::assertSame(302, $r['status']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.auth-toast-error', 'No account found');
    }

    public function testSignInChecksPasswordThrottlesAndStartsTheSession(): void
    {
        self::assertSame(403, $this->auth(['auth_action' => 'signin', 'identifier' => 'p1@fixture.test', 'password' => 'x', 'csrf_token' => 'forged'])['status']);
        $wrong = $this->auth(['auth_action' => 'signin', 'identifier' => 'p3@fixture.test', 'password' => 'wrong-password-1']);
        self::assertSame(400, $wrong['status']);
        self::assertStringContainsString('Incorrect password', $wrong['json']['message']);
        self::assertArrayNotHasKey('picklers_user_id', $_SESSION['_sf2_attributes'] ?? []);

        $ok = $this->auth(['auth_action' => 'signin', 'identifier' => 'p1@fixture.test', 'password' => self::fixturePassword()]);
        self::assertTrue($ok['json']['success'], json_encode($ok['json']));
        self::assertStringEndsWith('/app.php', $ok['json']['redirect']);
        self::assertArrayNotHasKey('password_hash', $ok['json']['user']);
        self::assertSame('usr_fx_p1', $_SESSION['_sf2_attributes']['picklers_user_id']);

        $gone = $this->auth(['auth_action' => 'signin', 'identifier' => 'deleted_usr_fx_gone@picklers.invalid', 'password' => self::fixturePassword()]);
        self::assertStringContainsString('deactivated', $gone['json']['message']);
    }

    public function testRegistrationValidatesAndCreatesAPlayer(): void
    {
        self::pdo()->exec("DELETE FROM users WHERE email = 'newbie@fixture.test'");
        $base = ['auth_action' => 'signup', 'name' => 'New Bie', 'email' => 'newbie@fixture.test', 'role' => 'owner'];
        self::assertStringContainsString('at least', $this->auth($base + ['password' => 'short'])['json']['message']);
        self::assertStringContainsString('already exists', $this->auth(['email' => 'p1@fixture.test', 'password' => 'Strong2026pass'] + $base)['json']['message']);

        $r = $this->auth($base + ['password' => 'Strong2026pass']);
        self::assertTrue($r['json']['success'], json_encode($r['json']));
        self::assertSame('owner-application', $r['json']['redirect'], 'owner intent goes to the application');
        $row = self::pdo()->query("SELECT role, is_owner, password_hash FROM users WHERE email = 'newbie@fixture.test'")->fetch();
        self::assertSame('player', $row['role'], 'registration never creates an owner');
        self::assertTrue(password_verify('Strong2026pass', $row['password_hash']));
        self::pdo()->exec("DELETE FROM users WHERE email = 'newbie@fixture.test'");
    }

    public function testLogoutEndsTheSession(): void
    {
        $this->signIn('usr_fx_p1');
        $this->client->request('GET', '/logout');
        self::assertResponseRedirects('/auth?logout=1');
        self::assertArrayNotHasKey('picklers_user_id', $_SESSION['_sf2_attributes'] ?? []);
    }

    public function testPlayerAppNeedsAnAccountAndRendersEveryTab(): void
    {
        $this->client->request('GET', '/app');
        self::assertResponseRedirects('/auth.php');

        $this->signIn('usr_fx_p1');
        foreach (['play' => '#playTabContent', 'explore' => '.match-card-item', 'wallet' => '.wallet-balance-num', 'bookings' => '.settings-hero-name, .bookings-subtabs, body', 'settings' => '#settingsHeroNameDisplay'] as $tab => $selector) {
            $this->client->request('GET', '/app.php', ['tab' => $tab]);
            self::assertResponseIsSuccessful($tab);
            self::assertSelectorExists($selector, $tab);
        }
        $this->client->request('GET', '/app', ['tab' => 'bookings', 'sub' => 'cancelled']);
        self::assertResponseIsSuccessful();
    }
}
