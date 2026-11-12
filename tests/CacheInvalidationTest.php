<?php
declare(strict_types=1);

namespace Picklers\Tests;

/**
 * Guards the request-scoped read cache on facility/court lookups.
 *
 * The cache must be transparent: identical reads return identical data, and a
 * write must never leave a stale value behind.
 */
final class CacheInvalidationTest extends TestCase {

    public function run(): void {
        $facilityRepository = $this->service(\Picklers\Repositories\FacilityRepository::class);

        // ── Cache is transparent for reads ─────────────────────────────────
        $a = $facilityRepository->getFacilities();
        $b = $facilityRepository->getFacilities();
        $this->assertEquals($a, $b, 'Repeated getFacilities() calls return identical data');
        $this->assertGreaterThan(0, count($a), 'Facilities are actually returned, not an empty cache');

        $f1 = $facilityRepository->getFacility(1);
        $f2 = $facilityRepository->getFacility(1);
        $this->assertEquals($f1, $f2, 'Repeated getFacility() calls return identical data');
        $this->assertNotNull($f1, 'Facility 1 resolves');

        $c1 = $facilityRepository->getCourtsByFacility(1);
        $c2 = $facilityRepository->getCourtsByFacility(1);
        $this->assertEquals($c1, $c2, 'Repeated getCourtsByFacility() calls return identical data');

        // Different arguments must not collide in the cache key.
        $other = $facilityRepository->getFacility(2);
        if ($other !== null) {
            $this->assertFalse(
                ($other['id'] ?? null) === ($f1['id'] ?? null),
                'Different facility ids return different rows (no cache-key collision)'
            );
        }

        $filtered = $facilityRepository->getFacilities('Pickle');
        $this->assertLessThanOrEqual(count($a), count($filtered),
            'A search filter never returns more rows than the unfiltered set');

        // ── A write invalidates the cache ──────────────────────────────────
        $courts = $facilityRepository->getCourtsByFacility(1);
        if (!empty($courts)) {
            $court    = $courts[0];
            $courtId  = (string)$court['id'];
            $original = (string)($court['status'] ?? 'available');
            $flipped  = $original === 'maintenance' ? 'available' : 'maintenance';

            try {
                $facilityRepository->updateCourtStatus($courtId, $flipped);
                $after = $facilityRepository->getCourtsByFacility(1);

                $seen = null;
                foreach ($after as $c) {
                    if ((string)$c['id'] === $courtId) { $seen = (string)($c['status'] ?? ''); break; }
                }
                $this->assertSame($flipped, $seen,
                    'A court status write is reflected immediately — the cache was invalidated');
            } finally {
                $facilityRepository->updateCourtStatus($courtId, $original);
            }

            $restored = $facilityRepository->getCourtsByFacility(1);
            $seenBack = null;
            foreach ($restored as $c) {
                if ((string)$c['id'] === $courtId) { $seenBack = (string)($c['status'] ?? ''); break; }
            }
            $this->assertSame($original, $seenBack, 'The original court status was restored');
        }
    }
}
