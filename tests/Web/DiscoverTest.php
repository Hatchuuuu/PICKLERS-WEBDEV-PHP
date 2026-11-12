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

    public function testSlotsRejectInvalidTargetsAndDates(): void
    {
        $id = $this->fixtureFacilityId();
        $court = (string)$this->scalar('SELECT id FROM courts WHERE facility_id = ? ORDER BY id LIMIT 1', [$id]);
        $foreign = (string)$this->scalar('SELECT id FROM courts WHERE facility_id <> ? ORDER BY id LIMIT 1', [$id]);
        foreach (['/api', '/api.php', "/api/facilities/{$id}/courts/{$court}/slots"] as $uri) {
            foreach (['not-a-date', '2031-02-30'] as $date) {
                $r = $this->get($uri, ['action' => 'slot_availability', 'facility_id' => $id, 'court_id' => $court, 'date' => $date]);
                self::assertSame(400, $r['status'], $uri . ' ' . $date);
                self::assertFalse($r['json']['success']);
            }
        }
        foreach ([$foreign, 'nonexistent-court'] as $badCourt) {
            foreach (['/api', "/api/facilities/{$id}/courts/{$badCourt}/slots"] as $uri) {
                $r = $this->get($uri, ['action' => 'court_availability', 'facility_id' => $id, 'court_id' => $badCourt, 'date' => '2031-09-14']);
                self::assertSame(404, $r['status'], $uri . ' ' . $badCourt);
                self::assertFalse($r['json']['success']);
            }
        }
        foreach (['999999', $id . '-invalid'] as $badFacility) {
            $r = $this->get('/api', ['action' => 'slot_availability', 'facility_id' => $badFacility, 'court_id' => $court, 'date' => '2031-09-14']);
            self::assertSame(404, $r['status'], 'invalid facility ' . $badFacility);
        }
    }

    public function testPastAndBlockedSlotsAreUnavailableWithoutChangingTheGrid(): void
    {
        $id = $this->fixtureFacilityId();
        self::pdo()->prepare('UPDATE facilities SET hours = ? WHERE id = ?')->execute(['', $id]);
        $court = (string)$this->scalar('SELECT id FROM courts WHERE facility_id = ? ORDER BY id LIMIT 1', [$id]);
        $uri = "/api/facilities/{$id}/courts/{$court}/slots";
        $future = date('Y-m-d', strtotime('+20 days'));
        $free = $this->get($uri, ['date' => $future]);
        self::assertSame(200, $free['status']);
        self::assertCount(15, $free['json']['slots']);
        self::assertSame(420, $free['json']['slots'][0]['start_min']);
        self::assertSame(1320, $free['json']['slots'][14]['end_min']);
        self::assertNotContains(false, array_column($free['json']['slots'], 'available'));
        $default = $this->get($uri);
        self::assertSame(date('Y-m-d'), $default['json']['date']);
        $now = (int)date('G') * 60 + (int)date('i');
        foreach ($default['json']['slots'] as $slot) {
            self::assertSame($slot['start_min'] > $now, $slot['available']);
        }
        $past = $this->get($uri, ['date' => date('Y-m-d', strtotime('-1 day'))]);
        self::assertNotContains(true, array_column($past['json']['slots'], 'available'));
        self::assertSame(['past'], array_values(array_unique(array_column($past['json']['slots'], 'reason'))));
        foreach (['maintenance', 'UNAVAILABLE'] as $status) {
            self::pdo()->prepare('UPDATE courts SET status = ? WHERE id = ?')->execute([$status, $court]);
            $blocked = $this->get('/api', ['action' => 'slot_availability', 'facility_id' => $id, 'court_id' => $court, 'date' => $future]);
            self::assertSame(200, $blocked['status']);
            self::assertNotContains(true, array_column($blocked['json']['slots'], 'available'));
            self::assertStringContainsString('maintenance', $blocked['json']['slots'][0]['reason']);
        }
        self::pdo()->prepare("UPDATE courts SET status = 'available' WHERE id = ?")->execute([$court]);
        self::pdo()->prepare("UPDATE facilities SET operating_status = 'suspended' WHERE id = ?")->execute([$id]);
        $blocked = $this->get($uri, ['date' => $future]);
        self::assertNotContains(true, array_column($blocked['json']['slots'], 'available'));
        self::assertStringContainsString('temporarily unavailable', $blocked['json']['slots'][0]['reason']);
    }

    public function testSlotsKeepBookedAndEndedEarlyBehavior(): void
    {
        $id = $this->fixtureFacilityId();
        $court = (string)$this->scalar('SELECT id FROM courts WHERE facility_id = ? ORDER BY id LIMIT 1', [$id]);
        $name = (string)$this->scalar('SELECT name FROM courts WHERE id = ?', [$court]);
        $day = date('Y-m-d', strtotime('+20 days'));
        self::pdo()->prepare('INSERT INTO bookings (id, user_id, facility_id, court_id, court_name, booking_date, start_min, end_min, status, ended_early) VALUES (?, ?, ?, ?, ?, ?, 600, 660, ?, 0)')
            ->execute(['FX-SLOT-READ', 'usr_fx_p1', $id, $court, $name, $day, 'confirmed']);
        $query = ['action' => 'slot_availability', 'facility_id' => $id, 'court_id' => $court, 'date' => $day];
        $slots = $this->get('/api', $query)['json']['slots'];
        $booked = array_values(array_filter($slots, static fn($slot) => $slot['start_min'] === 600))[0];
        self::assertFalse($booked['available']);
        self::assertSame('booked', $booked['reason']);
        self::pdo()->exec("UPDATE bookings SET ended_early = 1 WHERE id = 'FX-SLOT-READ'");
        $slots = $this->get('/api', $query)['json']['slots'];
        $released = array_values(array_filter($slots, static fn($slot) => $slot['start_min'] === 600))[0];
        self::assertTrue($released['available']);
        self::assertNull($released['reason']);
        self::pdo()->exec("DELETE FROM bookings WHERE id = 'FX-SLOT-READ'");
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
        self::assertTrue($this->get('/api', ['action' => 'facility_detail', 'id' => $id])['json']['facility']['is_favorited'], 'detail Follow reflects the same persisted favorite');

        self::assertFalse($this->favorite($id, 'web-test-token')['json']['favorited'], 'second toggle removes it');
        self::assertFalse($this->get('/api', ['action' => 'facility_detail', 'id' => $id])['json']['facility']['is_favorited']);
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
