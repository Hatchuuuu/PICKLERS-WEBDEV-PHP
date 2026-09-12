<?php
declare(strict_types=1);

namespace Picklers\Tests;

use Picklers\Core\Database;

/**
 * Guards the request-scoped read cache on facility/court lookups.
 *
 * The cache must be transparent: identical reads return identical data, and a
 * write must never leave a stale value behind.
 */
final class CacheInvalidationTest extends TestCase {

    public function run(): void {
        $db = Database::get();

        // ── Cache is transparent for reads ─────────────────────────────────
        $a = $db->getFacilities();
        $b = $db->getFacilities();
        $this->assertEquals($a, $b, 'Repeated getFacilities() calls return identical data');
        $this->assertGreaterThan(0, count($a), 'Facilities are actually returned, not an empty cache');

        $f1 = $db->getFacility(1);
        $f2 = $db->getFacility(1);
        $this->assertEquals($f1, $f2, 'Repeated getFacility() calls return identical data');
        $this->assertNotNull($f1, 'Facility 1 resolves');

        $c1 = $db->getCourtsByFacility(1);
        $c2 = $db->getCourtsByFacility(1);
        $this->assertEquals($c1, $c2, 'Repeated getCourtsByFacility() calls return identical data');

        // Different arguments must not collide in the cache key.
        $other = $db->getFacility(2);
        if ($other !== null) {
            $this->assertFalse(
                ($other['id'] ?? null) === ($f1['id'] ?? null),
                'Different facility ids return different rows (no cache-key collision)'
            );
        }

        $filtered = $db->getFacilities('Pickle');
        $this->assertLessThanOrEqual(count($a), count($filtered),
            'A search filter never returns more rows than the unfiltered set');

        // ── A write invalidates the cache ──────────────────────────────────
        $courts = $db->getCourtsByFacility(1);
        if (!empty($courts)) {
            $court    = $courts[0];
            $courtId  = (string)$court['id'];
            $original = (string)($court['status'] ?? 'available');
            $flipped  = $original === 'maintenance' ? 'available' : 'maintenance';

            try {
                $db->updateCourtStatus($courtId, $flipped);
                $after = $db->getCourtsByFacility(1);

                $seen = null;
                foreach ($after as $c) {
                    if ((string)$c['id'] === $courtId) { $seen = (string)($c['status'] ?? ''); break; }
                }
                $this->assertSame($flipped, $seen,
                    'A court status write is reflected immediately — the cache was invalidated');
            } finally {
                $db->updateCourtStatus($courtId, $original);
            }

            $restored = $db->getCourtsByFacility(1);
            $seenBack = null;
            foreach ($restored as $c) {
                if ((string)$c['id'] === $courtId) { $seenBack = (string)($c['status'] ?? ''); break; }
            }
            $this->assertSame($original, $seenBack, 'The original court status was restored');
        }
    }
}
