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

    public function testLandingEmptyStatesExplainMissingListings(): void
    {
        $html = static::getContainer()->get('twig')->render('web/home.html.twig', [
            'currentUser' => null, 'facilities' => [], 'matches' => [], 'brands' => [], 'faqs' => [], 'site_name' => 'PICKLERS', 'city_count' => 0,
        ]);
        self::assertStringContainsString('No facilities are listed yet.', $html);
        self::assertStringContainsString('No Open Play sessions are listed yet.', $html);
        self::assertStringContainsString('href="owner-application.php">List your court', $html);
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

    public function testExploreEmptyStateIsVisibleOnInitialRenderOnlyWhenThereAreNoMatches(): void
    {
        $twig = static::getContainer()->get('twig');
        foreach ([[], [['id' => 'empty-state-test', 'level' => 'Beginner', 'location' => 'Test venue', 'time' => '10:00 AM']]] as $matches) {
            $html = $twig->render('web/partials/app/_tab-explore.html.twig', [
                'activeTab' => 'explore', 'matches' => $matches, 'bookings' => [],
            ]);
            $crawler = new \Symfony\Component\DomCrawler\Crawler($html);
            $emptyState = $crawler->filter('#matchesEmptyState');
            self::assertCount(1, $emptyState);
            self::assertStringContainsString('No Matches Found', $emptyState->text());
            self::assertSame($matches === [], !str_contains((string)$emptyState->attr('style'), 'display:none'));
            self::assertCount(count($matches), $crawler->filter('.match-card-item'));
        }
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

    public function testRegistrationChecksSubmittedPasswordConfirmationBeforeWriting(): void
    {
        $email = 'confirm-pass2@fixture.test';
        $base = ['auth_action' => 'signup', 'name' => 'Confirmation Customer', 'email' => $email, 'password' => 'Fixture-Pass-2026'];
        self::pdo()->exec("DELETE FROM users WHERE email = 'confirm-pass2@fixture.test'");
        try {
            foreach (['Different-Pass-2026', ''] as $confirmation) {
                $result = $this->auth($base + ['password_confirm' => $confirmation]);
                self::assertSame(400, $result['status']);
                self::assertStringContainsString('Passwords do not match', $result['json']['message']);
                self::assertSame(0, (int)$this->scalar('SELECT COUNT(*) FROM users WHERE email = ?', [$email]));
            }
            $result = $this->auth($base + ['password_confirm' => $base['password']]);
            self::assertSame(200, $result['status']);
            self::assertTrue($result['json']['success']);
            self::assertSame(1, (int)$this->scalar('SELECT COUNT(*) FROM users WHERE email = ?', [$email]));
        } finally {
            self::pdo()->exec("DELETE FROM users WHERE email = 'confirm-pass2@fixture.test'");
        }
    }

    public function testLogoutEndsTheSession(): void
    {
        $this->signIn('usr_fx_p1');
        $_SESSION['_sf2_attributes']['_csrf/picklers'] = 'logout-token';
        $this->client->request('POST', '/logout', ['csrf_token' => 'logout-token']);
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
