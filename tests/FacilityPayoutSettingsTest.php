<?php
declare(strict_types=1);

namespace Picklers\Tests;

use Picklers\Core\Database;

/**
 * Covers the facility Settings tab's payout fields: gcash_number, maya_number,
 * gcash_enabled, maya_enabled, cash_on_site used to be saved to localStorage
 * ONLY (see saveFacilitySettings() in owner.js) and never reached the server
 * at all — updateFacility()'s allow-list silently dropped them even if sent.
 * This confirms they now actually round-trip through the database.
 */
final class FacilityPayoutSettingsTest extends TestCase {

    public function run(): void {
        $db = Database::get();
        $facilityId = 3;

        $before = $db->getFacility($facilityId);
        $this->assertNotNull($before, 'Facility 3 exists');
        $originalGcash = $before['gcash_number'] ?? null;
        $originalMaya = $before['maya_number'] ?? null;
        $originalCash = $before['cash_on_site'] ?? null;

        $ok = $db->updateFacility($facilityId, [
            'gcash_number' => '09171234567',
            'maya_number'  => '09281234567',
            'gcash_enabled' => 0,
            'maya_enabled'  => 1,
            'cash_on_site'  => 0,
        ]);
        $this->assertTrue($ok, 'updateFacility() accepts the payout fields');

        $after = $db->getFacility($facilityId);
        $this->assertSame('09171234567', (string)($after['gcash_number'] ?? ''), 'gcash_number persisted');
        $this->assertSame('09281234567', (string)($after['maya_number'] ?? ''), 'maya_number persisted');
        $this->assertSame(0, (int)($after['gcash_enabled'] ?? 1), 'gcash_enabled persisted as 0');
        $this->assertSame(1, (int)($after['maya_enabled'] ?? 0), 'maya_enabled persisted as 1');
        $this->assertSame(0, (int)($after['cash_on_site'] ?? 1), 'cash_on_site persisted as 0');

        // A field NOT in the allow-list must still be silently ignored, not
        // fail the whole update or write to an arbitrary column.
        $ok2 = $db->updateFacility($facilityId, ['owner_id' => 'usr_hacker', 'gcash_number' => '09190001111']);
        $this->assertTrue($ok2, 'A mixed update with one allowed field still succeeds');
        $afterOwnerCheck = $db->getFacility($facilityId);
        $this->assertSame((string)($before['owner_id'] ?? ''), (string)($afterOwnerCheck['owner_id'] ?? ''), 'owner_id is not writable through this path');

        // Restore original values so later suite runs see a clean facility.
        $db->updateFacility($facilityId, [
            'gcash_number' => $originalGcash ?? '',
            'maya_number'  => $originalMaya ?? '',
            'gcash_enabled' => 1,
            'maya_enabled'  => 1,
            'cash_on_site'  => $originalCash ?? 1,
        ]);
    }
}
