<?php
declare(strict_types=1);

namespace Picklers\Tests;

use Picklers\Core\Database;

/**
 * Guards the court-level relational attribute system (Indoor/Outdoor/
 * Covered/Air Conditioned as real rows, not a free-text facilities.type
 * string one court could never disagree with another about).
 */
final class CourtAttributesTest extends TestCase {
    public function run(): void {
        $db = Database::get();

        // ── REGRESSION: surface material names are not location signals ────
        // "Outdoor Acrylic" is a paint/material name used on indoor courts in
        // this data set (courts.crt_1_4 is exactly this: type=Indoor,
        // surface="Outdoor Acrylic"). The court's own `type` column is
        // authoritative; `surface` must never override it into the wrong
        // environment tag.
        $this->assertEquals(
            ['indoor', 'air-conditioned'],
            Database::deriveCourtAttributeSlugsFromLegacyFields('Indoor', 'Outdoor Acrylic', 'Indoor · Cushion'),
            'REGRESSION: an Indoor court with an "Outdoor Acrylic" surface is tagged indoor, never outdoor'
        );

        $this->assertEquals(
            ['outdoor'],
            Database::deriveCourtAttributeSlugsFromLegacyFields('Outdoor', 'Outdoor Acrylic', 'Outdoor · Acrylic Pro'),
            'A genuinely Outdoor court (its OWN type column, not just its surface) is tagged outdoor'
        );

        // ── REGRESSION: a facility's own free-text type must not override an
        // individual court's own type. Facility 2 in the live data is typed
        // "Outdoor · Social & Cafe" while every one of its own courts is
        // individually Indoor — the facility string must lose. ─────────────
        $this->assertEquals(
            ['indoor'],
            Database::deriveCourtAttributeSlugsFromLegacyFields('Indoor', 'Championship Wood', 'Outdoor · Social & Cafe'),
            'REGRESSION: a court\'s own Indoor type wins over its facility\'s Outdoor-sounding free-text type'
        );

        // Facility type is only consulted when the court's OWN type is empty.
        $this->assertEquals(
            ['outdoor'],
            Database::deriveCourtAttributeSlugsFromLegacyFields('', 'Hard Court', 'Outdoor Venue'),
            'An empty court type legitimately falls back to the facility\'s own type'
        );

        // Climate/cover signals legitimately live in surface/facility type.
        $this->assertEquals(
            ['indoor', 'air-conditioned'],
            Database::deriveCourtAttributeSlugsFromLegacyFields('Indoor', 'Cushion Acrylic', ''),
            'A cushioned surface is treated as a real air-conditioning signal'
        );
        $this->assertEquals(
            ['outdoor', 'covered'],
            Database::deriveCourtAttributeSlugsFromLegacyFields('Outdoor', 'Acrylic', 'Covered Outdoor Complex'),
            'A "Covered" facility type is picked up as a real cover signal'
        );

        // ── Live read/write path ────────────────────────────────────────────
        $facilityId = $db->createFacilityFromApplication([
            'id' => 'app_test_attr_' . bin2hex(random_bytes(4)),
            'user_id' => 'usr_test_attr_' . bin2hex(random_bytes(4)),
            'facility_name' => 'Attribute Test Facility ' . bin2hex(random_bytes(3)),
            'address' => '123 Attribute St',
            'courts_count' => 1,
            'operating_hours' => '6:00 AM – 10:00 PM',
        ]);
        $facility = $db->getFacility($facilityId);
        $this->assertNotNull($facility, 'A dedicated test facility exists to test against');

        $court = $db->insertCourt([
            'facility_id' => $facility['id'],
            'name' => 'Attribute Test Court ' . bin2hex(random_bytes(3)),
            'price' => 200.0,
        ]);

        $this->assertEquals([], $db->getCourtAttributes((string)$court['id']),
            'A freshly inserted court starts with no attributes');

        $db->setCourtAttributes((string)$court['id'], ['outdoor', 'covered']);
        $slugs = array_column($db->getCourtAttributes((string)$court['id']), 'slug');
        sort($slugs);
        $this->assertEquals(['covered', 'outdoor'], $slugs,
            'setCourtAttributes() persists exactly the slugs given');

        // Replacing (not appending) is the contract.
        $db->setCourtAttributes((string)$court['id'], ['indoor']);
        $slugs = array_column($db->getCourtAttributes((string)$court['id']), 'slug');
        $this->assertEquals(['indoor'], $slugs,
            'A second call to setCourtAttributes() REPLACES the set, it does not accumulate');

        // An unknown slug from a stale client is silently ignored, not fatal.
        $db->setCourtAttributes((string)$court['id'], ['indoor', 'not-a-real-slug']);
        $slugs = array_column($db->getCourtAttributes((string)$court['id']), 'slug');
        $this->assertEquals(['indoor'], $slugs,
            'An unrecognised slug is ignored rather than breaking the rest of a legitimate edit');

        // ── getCourtsWithAttributes() attaches the right tags to the right
        // court, not a cross-contaminated blob ──────────────────────────────
        $withAttrs = $db->getCourtsWithAttributes($facility['id']);
        $found = null;
        foreach ($withAttrs as $c) {
            if ((string)$c['id'] === (string)$court['id']) { $found = $c; break; }
        }
        $this->assertNotNull($found, 'The new court appears in getCourtsWithAttributes()');
        $this->assertEquals(['indoor'], $found['attribute_slugs'] ?? null,
            'getCourtsWithAttributes() reports this exact court\'s own tags');

        // ── getFacilitiesByAttributes(): AND semantics across slugs, and a
        // facility with no matching court is excluded entirely ─────────────
        $db->setCourtAttributes((string)$court['id'], ['outdoor', 'covered']);
        $byAttrs = $db->getFacilitiesByAttributes(['outdoor', 'covered']);
        $foundFacility = false;
        foreach ($byAttrs as $f) {
            if ((int)$f['id'] === (int)$facility['id']) { $foundFacility = true; break; }
        }
        $this->assertTrue($foundFacility,
            'A facility with a court carrying every requested tag is included');

        $impossible = $db->getFacilitiesByAttributes(['outdoor', 'covered', 'night-lighting']);
        $stillFound = false;
        foreach ($impossible as $f) {
            if ((int)$f['id'] === (int)$facility['id']) { $stillFound = true; break; }
        }
        $this->assertFalse($stillFound,
            'Adding a tag no court at this facility carries excludes it (AND semantics, not OR)');

        $this->assertEquals($db->getFacilities(), $db->getFacilitiesByAttributes([]),
            'No filter selected falls back to the unfiltered facility list');
    }
}
