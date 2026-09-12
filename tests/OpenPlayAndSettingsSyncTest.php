<?php
declare(strict_types=1);

namespace Picklers\Tests;

use Picklers\Core\Database;

/**
 * Tests Open Play court occupancy sync and Facility Settings persistence.
 */
final class OpenPlayAndSettingsSyncTest extends TestCase {

    public function run(): void {
        $db = Database::get();

        // 1. Open Play Court Status Sync Test
        $facilityId = 3; // Incredoball Sports Center
        $courtName = 'Court 1';

        // Host Open Play match
        $db->occupyCourt($facilityId, $courtName, 'Hosted Open Play', '6:00 PM – 8:00 PM');
        $db->insertMatch([
            'facility_id' => $facilityId,
            'facility_name' => 'Incredoball Sports Center',
            'location' => 'Barangay Daro, Dumaguete City',
            'date' => date('Y-m-d'),
            'time' => '6:00 PM – 8:00 PM',
            'level' => 'All Levels',
            'current_players' => 0,
            'max_players' => 12,
            'price' => 250.0,
            'type' => $courtName,
            'host' => 'Ignacio Reyes',
            'title' => 'Evening Social Open Play'
        ]);

        $courts = $db->getCourtsByFacility($facilityId);
        $court1 = null;
        foreach ($courts as $c) {
            if (strcasecmp((string)($c['name'] ?? ''), $courtName) === 0) {
                $court1 = $c;
                break;
            }
        }

        $this->assertNotNull($court1, 'Court 1 exists in facility court inventory');
        $this->assertSame('occupied', strtolower((string)($court1['status'] ?? '')), 'Hosted Open Play marks Court 1 as occupied in court listing');
        $this->assertSame('Hosted Open Play', $court1['occupied_by'] ?? null, 'Occupied by field indicates Hosted Open Play');

        // Cancel Open Play session
        $db->deleteMatch('Evening Social Open Play', $facilityId, $courtName);
        $db->clearCourtSession($courtName, $facilityId);

        $restoredCourts = $db->getCourtsByFacility($facilityId);
        $restoredCourt1 = null;
        foreach ($restoredCourts as $c) {
            if (strcasecmp((string)($c['name'] ?? ''), $courtName) === 0) {
                $restoredCourt1 = $c;
                break;
            }
        }
        $this->assertSame('available', strtolower((string)($restoredCourt1['status'] ?? '')), 'Cancelling Open Play restores Court 1 status to available');

        // 2. Facility Settings Update Test
        $facBefore = $db->getFacility($facilityId);
        $this->assertNotNull($facBefore, 'Facility 3 exists in database');
        $originalName = $facBefore['name'];

        $testName = 'Incredoball Sports Center (Verified)';
        $ok = $db->updateFacility($facilityId, ['name' => $testName, 'location' => 'Barangay Daro, Dumaguete City']);
        $this->assertTrue($ok, 'updateFacility returns true on successful update');

        $facUpdated = $db->getFacility($facilityId);
        $this->assertSame($testName, $facUpdated['name'] ?? null, 'Facility name was updated in DB');

        // Restore original facility name
        $db->updateFacility($facilityId, ['name' => $originalName]);
        $facRestored = $db->getFacility($facilityId);
        $this->assertSame($originalName, $facRestored['name'] ?? null, 'Facility name restored to original');
    }
}
