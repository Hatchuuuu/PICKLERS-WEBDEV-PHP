<?php
declare(strict_types=1);

namespace Picklers\Tests\Web;

use Picklers\Admin\Http\AdminArea;
use Picklers\Tests\Admin\AdminWebTestCase;

/** Section 1 — Discover, served by Symfony with the legacy /api contract intact. */
final class DiscoverTest extends AdminWebTestCase
{
    private function get(string $uri, array $query = []): array
    {
        $this->client->request('GET', $uri, $query);
        $r = $this->client->getResponse();

        return ['status' => $r->getStatusCode(), 'json' => json_decode((string)$r->getContent(), true) ?? [], 'response' => $r];
    }

    private function favorite(string $facilityId, ?string $csrf, string $method = 'POST'): array
    {
        $_SESSION['_sf2_attributes']['_csrf/picklers'] = 'web-test-token';
        $this->client->request($method, '/api.php?action=toggle_favorite_facility', [], [], array_filter([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ]), (string)json_encode(['action' => 'toggle_favorite_facility', 'facility_id' => $facilityId]));
        $r = $this->client->getResponse();

        return ['status' => $r->getStatusCode(), 'json' => json_decode((string)$r->getContent(), true) ?? []];
    }

    private function fixtureFacilityId(): string
    {
        return (string)$this->scalar("SELECT id FROM facilities WHERE name = 'Fixture Arena Dumaguete'");
    }

    public function testPlayerActionsAreNotTheAdminArea(): void
    {
        foreach (['facilities', 'me', 'book_court', 'sync', 'create_tournament'] as $action) {
            self::assertFalse(AdminArea::contains('/api', $action), "{$action} is not admin");
        }
    }

    public function testGuestsCanBrowseThroughLegacyAndRestUrls(): void
    {
        $legacy = $this->get('/api', ['action' => 'facilities']);
        $rest = $this->get('/api/facilities');
        self::assertSame(200, $legacy['status']);
        self::assertTrue($legacy['json']['success']);
        self::assertSame($legacy['json'], $rest['json'], 'both URLs are the same endpoint');
        self::assertContains('Fixture Arena Dumaguete', array_column($legacy['json']['facilities'], 'name'));
        self::assertArrayNotHasKey('is_favorited', $legacy['json']['facilities'][0], 'guests get no favourite flags');
        self::assertNotNull($legacy['response']->headers->get('X-Request-Id'), 'served by the Symfony kernel');

        $filtered = $this->get('/api/facilities', ['search' => 'Valencia']);
        self::assertSame(['Fixture Courts Valencia'], array_column($filtered['json']['facilities'], 'name'));
    }

    public function testFacilityDetailAndNotFoundKeepTheLegacyShape(): void
    {
        $id = $this->fixtureFacilityId();
        $r = $this->get('/api', ['action' => 'facility_detail', 'id' => $id]);
        self::assertSame(200, $r['status']);
        self::assertSame('Fixture Arena Dumaguete', $r['json']['facility']['name']);
        foreach (['courts', 'images', 'amenities'] as $key) {
            self::assertIsArray($r['json'][$key], $key);
        }

        $missing = $this->get('/api', ['action' => 'facility_detail', 'id' => '999999']);
        self::assertSame(404, $missing['status']);
        self::assertSame(['success' => false, 'message' => 'Facility not found', 'errors' => []], $missing['json']);
    }

    public function testSlotAvailabilityReadsTheCanonicalDay(): void
    {
        $id = $this->fixtureFacilityId();
        $court = (string)$this->scalar('SELECT id FROM courts WHERE facility_id = ? ORDER BY id LIMIT 1', [$id]);
        $r = $this->get('/api', ['action' => 'slot_availability', 'facility_id' => $id, 'court_id' => $court, 'date' => 'Sun, Sep 14, 2031']);
        self::assertSame(200, $r['status']);
        self::assertSame('2031-09-14', $r['json']['date'], 'display dates are normalised');
        self::assertNotEmpty($r['json']['slots']);

        $bad = $this->get('/api', ['action' => 'court_availability', 'facility_id' => $id]);
        self::assertSame(400, $bad['status']);
        self::assertFalse($bad['json']['success']);
    }

    public function testFavouritesNeedAccountCsrfAndPost(): void
    {
        $id = $this->fixtureFacilityId();
        self::pdo()->prepare('DELETE FROM facility_favorites WHERE user_id = ?')->execute(['usr_fx_p1']);

        self::assertSame(401, $this->favorite($id, 'web-test-token')['status'], 'guest');
        $this->signIn('usr_fx_p1');
        self::assertSame(403, $this->favorite($id, 'wrong')['status'], 'bad CSRF');
        self::assertSame(403, $this->favorite($id, null)['status'], 'missing CSRF');
        self::assertSame(405, $this->favorite($id, 'web-test-token', 'GET')['status'], 'GET cannot change data');

        $on = $this->favorite($id, 'web-test-token');
        self::assertSame(200, $on['status']);
        self::assertTrue($on['json']['success']);
        self::assertTrue($on['json']['favorited']);
        $list = $this->get('/api', ['action' => 'facilities'])['json']['facilities'];
        $mine = array_values(array_filter($list, fn ($f) => (string)$f['id'] === $id))[0];
        self::assertTrue($mine['is_favorited'], 'the list reflects the signed-in player');

        self::assertFalse($this->favorite($id, 'web-test-token')['json']['favorited'], 'second toggle removes it');
    }

    public function testExpiredOrDeactivatedSessionsBrowseAsGuests(): void
    {
        $this->signIn('usr_fx_p1');
        $_SESSION['_sf2_attributes']['picklers_last_activity'] = time() - 7201;
        $r = $this->get('/api/facilities');
        self::assertSame(200, $r['status']);
        self::assertArrayNotHasKey('is_favorited', $r['json']['facilities'][0]);
        self::assertArrayNotHasKey('picklers_user_id', $_SESSION['_sf2_attributes'] ?? [], 'the idle session was ended, as in the legacy app');

        // A deactivated account's session is destroyed before the action runs,
        // taking its CSRF token with it — refused, exactly as the legacy API did.
        $this->signIn('usr_fx_gone');
        self::assertSame(403, $this->favorite($this->fixtureFacilityId(), 'web-test-token')['status']);
        self::assertSame(0, (int)$this->scalar("SELECT COUNT(*) FROM facility_favorites WHERE user_id = 'usr_fx_gone'"));
    }

    public function testUnknownApiPathsAreJson404s(): void
    {
        $r = $this->get('/api/nope');
        self::assertSame(404, $r['status']);
        self::assertSame(['success' => false, 'message' => 'Not found.', 'errors' => []], $r['json']);
    }
}
