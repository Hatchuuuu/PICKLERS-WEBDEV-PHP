<?php
declare(strict_types=1);

namespace Picklers\Tests;

use Picklers\Core\Database;

/**
 * Covers the "Favorites" heart button: toggleFavoriteFacility() in app.js used
 * to be a CSS class flip only — no backend, no persistence, reset on every
 * page load. This confirms Database::toggleFavoriteFacility() and
 * getFavoriteFacilityIds() actually persist and round-trip per user.
 */
final class FacilityFavoritesTest extends TestCase {

    public function run(): void {
        $db = Database::get();
        $facilityId = 3;

        $allUsers = $db->getAllUsers();
        $this->assertTrue(count($allUsers) >= 2, 'At least two seeded users exist to test per-user isolation');
        $userA = (string)($allUsers[0]['id'] ?? '');
        $userB = (string)($allUsers[1]['id'] ?? '');

        // Start from a known state for both users on this facility.
        $before = $db->getFavoriteFacilityIds($userA);
        if (in_array($facilityId, $before, true)) {
            $db->toggleFavoriteFacility($userA, $facilityId);
        }
        $beforeB = $db->getFavoriteFacilityIds($userB);
        if (in_array($facilityId, $beforeB, true)) {
            $db->toggleFavoriteFacility($userB, $facilityId);
        }

        $res1 = $db->toggleFavoriteFacility($userA, $facilityId);
        $this->assertTrue($res1['success'] ?? false, 'First toggle succeeds');
        $this->assertTrue($res1['favorited'] ?? false, 'First toggle favorites the facility');

        $idsAfterFirst = $db->getFavoriteFacilityIds($userA);
        $this->assertTrue(in_array($facilityId, $idsAfterFirst, true), 'getFavoriteFacilityIds() reflects the new favorite');

        $idsForOtherUser = $db->getFavoriteFacilityIds($userB);
        $this->assertFalse(in_array($facilityId, $idsForOtherUser, true), 'Favoriting is per-user — does not leak to another account');

        $res2 = $db->toggleFavoriteFacility($userA, $facilityId);
        $this->assertTrue($res2['success'] ?? false, 'Second toggle (un-favorite) succeeds');
        $this->assertFalse($res2['favorited'] ?? true, 'Second toggle removes the favorite');

        $idsAfterSecond = $db->getFavoriteFacilityIds($userA);
        $this->assertFalse(in_array($facilityId, $idsAfterSecond, true), 'getFavoriteFacilityIds() reflects the removal');
    }
}
