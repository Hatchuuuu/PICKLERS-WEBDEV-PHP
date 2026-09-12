<?php
declare(strict_types=1);

namespace Picklers\Tests;

use Picklers\Core\Database;

/**
 * Guards the Owner -> Player provisioning pipeline.
 *
 * Regression origin: approving an owner application only ever flipped
 * is_owner=1 on the user — it never touched the facilities table at all.
 * resolveOwnerFacilities() then fabricated an in-memory placeholder facility
 * (a fictional Manila venue, string id 'fac_'.$userId) that was never
 * persisted. Adding a court against it wrote that string into an INT
 * facility_id column; without STRICT_TRANS_TABLES that silently coerced to
 * 0, and the court vanished from every query keyed on a real facility id.
 */
final class OwnerProvisioningTest extends TestCase {

    public function run(): void {
        $db = Database::get();

        $ownerId = 'usr_test_provision_' . bin2hex(random_bytes(4));
        $db->createUser([
            'id' => $ownerId, 'name' => 'Provisioning Tester',
            'email' => $ownerId . '@example.test', 'password_hash' => password_hash('x', PASSWORD_DEFAULT),
        ]);

        $application = [
            'id' => 'app_test_' . bin2hex(random_bytes(4)),
            'user_id' => $ownerId,
            'facility_name' => 'Regression Test Facility ' . bin2hex(random_bytes(3)),
            'address' => '123 Test Street, Test City',
            'courts_count' => 3,
            'operating_hours' => '6:00 AM – 10:00 PM',
        ];

        // ── PickSync (F-09): provisioning bumps the version counters a client
        // polls, which is what actually makes the new facility reach a
        // player's Discover feed without a manual refresh ──────────────────
        $versionsBefore = $db->getSyncVersions();

        // ── A real, findable facility is created ────────────────────────────
        $facilityId = $db->createFacilityFromApplication($application);
        $this->assertGreaterThan(0, $facilityId, 'A positive integer facility id is returned');

        $versionsAfter = $db->getSyncVersions();
        $this->assertTrue(
            ($versionsAfter['facilities'] ?? 0) > ($versionsBefore['facilities'] ?? 0),
            'REGRESSION: provisioning a facility bumps the "facilities" sync version, so a polling client notices'
        );
        $this->assertTrue(
            ($versionsAfter['courts'] ?? 0) > ($versionsBefore['courts'] ?? 0),
            'REGRESSION: provisioning also bumps "courts" — the scaffolded court rows are new too'
        );

        $facility = $db->getFacility($facilityId);
        $this->assertNotNull($facility, 'The created facility is immediately findable by id');
        $this->assertSame($application['facility_name'], $facility['name'] ?? null,
            'The facility carries the applicant\'s real facility name, not a placeholder');
        $this->assertSame($ownerId, (string)($facility['owner_id'] ?? ''),
            'The facility is owned by the applicant, not left unassigned');

        // ── No invented rating/reviews/price on a brand-new venue ───────────
        $this->assertSame(0.0, (float)($facility['rating'] ?? -1), 'A new facility starts with an honest zero rating, not a fabricated 4.8+');
        $this->assertSame(0, (int)($facility['reviews'] ?? -1), 'A new facility starts with zero reviews, not an invented count');

        // ── Courts are scaffolded to the declared count ──────────────────────
        $courts = $db->getCourtsByFacility($facilityId);
        $this->assertSame(3, count($courts), 'courts_count from the application scaffolds that many real court rows');

        // ── REGRESSION: the facility is immediately visible to players,
        // exactly the Owner -> Player requirement this whole pipeline exists
        // for — not just present in a direct id lookup. ────────────────────
        $discoverable = $db->getFacilities();
        $foundInDiscover = false;
        foreach ($discoverable as $f) {
            if ((int)($f['id'] ?? 0) === $facilityId) { $foundInDiscover = true; break; }
        }
        $this->assertTrue($foundInDiscover,
            'REGRESSION: a newly provisioned facility appears in the same getFacilities() list Discover Courts renders from');

        // ── Idempotent: approving the same application twice must not create
        // a second facility (a double-click, or a retried admin request) ────
        $facilityIdAgain = $db->createFacilityFromApplication($application);
        $this->assertSame($facilityId, $facilityIdAgain,
            'Re-provisioning the same owner_id + facility_name returns the existing facility, never a duplicate');

        // ── An owner can add a court to their OWN facility ──────────────────
        $newCourt = $db->insertCourt(['facility_id' => $facilityId, 'name' => 'Court 4', 'price' => 300.0]);
        $this->assertTrue($db->verifyCourtOwner((string)$newCourt['id'], $ownerId),
            'The applicant is verified as the owner of a court on their own newly-provisioned facility');

        // ── REGRESSION: verifyCourtOwner() no longer treats an unrelated
        // facility as fair game just because ITS owner_id happens to be
        // unassigned (it never should be in practice, but the loophole
        // existed regardless of whether real data ever hit it) ─────────────
        $otherOwnerId = 'usr_test_provision_other_' . bin2hex(random_bytes(4));
        $db->createUser([
            'id' => $otherOwnerId, 'name' => 'Unrelated Owner',
            'email' => $otherOwnerId . '@example.test', 'password_hash' => password_hash('x', PASSWORD_DEFAULT),
        ]);
        $this->assertFalse($db->verifyCourtOwner((string)$newCourt['id'], $otherOwnerId),
            'A DIFFERENT owner is never verified as owning this court');

        // Test users/facilities/courts are left in place, same as the rest of
        // this suite (see PricingServiceTest's usr_test_promo_cap_* users) —
        // picklers_test is disposable and rebuilt from scratch on demand.
    }
}
