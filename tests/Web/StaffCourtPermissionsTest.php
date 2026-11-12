<?php
declare(strict_types=1);

namespace Picklers\Tests\Web;

use Picklers\Tests\Admin\AdminWebTestCase;

final class StaffCourtPermissionsTest extends AdminWebTestCase
{
    private string $facility;
    private string $foreign;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db()->executeStatement("DELETE FROM staff WHERE user_id IN ('usr_fx_p1', 'usr_fx_owner2')");
        $this->facility = (string)$this->scalar("SELECT id FROM facilities WHERE owner_id = 'usr_fx_owner1' LIMIT 1");
        $this->foreign = (string)$this->scalar("SELECT id FROM facilities WHERE owner_id = 'usr_fx_owner2' LIMIT 1");
        $this->db()->insert('staff', ['id' => 'staff_policy', 'facility_id' => $this->facility, 'user_id' => 'usr_fx_p1', 'name' => 'Player', 'email' => 'old@fixture.test', 'role' => 'Front Desk', 'status' => 'Active', 'date_joined' => 'Oct 2026']);
    }

    public function testStaffRequestsAreReadOnlyWhileOwnersKeepTheirActions(): void
    {
        $this->signIn('usr_fx_p1');
        $this->client->request('GET', '/owner.php?facility_id=' . $this->facility);
        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->client->getCrawler()->filter('#requestsList .btn-req-accept-v2, #requestsList .btn-req-decline-v2')->count());
        self::assertStringContainsString('Only the venue owner or an administrator can accept or decline requests.', (string)$this->client->getResponse()->getContent());
        self::assertStringContainsString('window.PICKLERS_CAN_REVIEW_REQUESTS = false;', (string)$this->client->getResponse()->getContent());
        self::assertSame(403, $this->write('approve_booking', ['booking_id' => 'FX-PEND01'], '/api')[0]);
        $this->signIn('usr_fx_owner1');
        $this->client->request('GET', '/owner.php?facility_id=' . $this->facility);
        self::assertGreaterThan(0, $this->client->getCrawler()->filter('#requestsList .btn-req-accept-v2')->count());
    }

    public function testAssignedStaffCanManageCourtsAndReadExistingRoster(): void
    {
        $this->signIn('usr_fx_p1');
        [$status, $json] = $this->write('add_court', ['facility_id' => $this->facility, 'name' => 'Court 99']);
        self::assertSame(200, $status);
        $court = $json['court']['id'];
        self::assertSame(200, $this->write('edit_court', ['court_id' => $court, 'name' => 'Court 99', 'surface' => 'Indoor Hard', 'rate' => 500])[0]);
        self::assertSame(200, $this->write('toggle_court_status', ['court_id' => $court, 'active' => 1])[0]);
        self::assertSame(200, $this->write('update_court_status', ['court_id' => $court, 'status' => 'maintenance'], '/api')[0]);
        $this->client->request('GET', '/api?action=get_open_play_roster&facility_id=' . $this->facility . '&court_id=' . $court . '&court_name=Court%2099');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(404, $this->write('end_court_session', ['facility_id' => $this->facility, 'court_id' => $court])[0], 'Authorized empty court has no session');
        self::assertSame(200, $this->write('delete_court', ['court_id' => $court])[0]);
        self::assertNull($this->db()->fetchOne('SELECT id FROM courts WHERE id = ?', [$court]) ?: null);
    }

    public function testStaffSettingsWriteAndDirectManagementTabsAreDenied(): void
    {
        $this->signIn('usr_fx_p1');
        $before = $this->db()->fetchAssociative('SELECT * FROM facilities WHERE id = ?', [$this->facility]);
        self::assertSame(403, $this->write('update_facility_settings', ['facility_id' => $this->facility, 'facility_name' => 'Unauthorized rename'])[0]);
        self::assertSame($before, $this->db()->fetchAssociative('SELECT * FROM facilities WHERE id = ?', [$this->facility]));
        foreach (['staff', 'settings', 'courts'] as $tab) {
            $this->client->request('GET', '/owner.php?tab=' . $tab . '&facility_id=' . $this->facility);
            self::assertSame(200, $this->client->getResponse()->getStatusCode());
            self::assertSame(0, $this->client->getCrawler()->filter('#addStaffModal, #revokeStaffModal, #addStaffForm, #ownerSettingsForm')->count());
            self::assertSame(0, $this->client->getCrawler()->filter('a[href*="tab=staff"], a[href*="tab=settings"]')->count());
        }
    }

    public function testForeignAndRevokedCourtWritesDoNotPersist(): void
    {
        $court = (string)$this->scalar('SELECT id FROM courts WHERE facility_id = ? LIMIT 1', [$this->foreign]);
        $before = $this->db()->fetchAssociative('SELECT * FROM courts WHERE id = ?', [$court]);
        $this->signIn('usr_fx_p1');
        foreach (['edit_court' => ['name' => 'Court 99'], 'toggle_court_status' => ['active' => 0], 'delete_court' => [], 'end_court_session' => ['facility_id' => $this->facility]] as $action => $params) {
            self::assertSame(403, $this->write($action, ['court_id' => $court] + $params)[0], $action);
            self::assertSame($before, $this->db()->fetchAssociative('SELECT * FROM courts WHERE id = ?', [$court]));
        }
        self::assertSame(403, $this->write('add_court', ['facility_id' => $this->foreign, 'name' => 'Court 99'])[0]);
        $ownCourt = (string)$this->scalar('SELECT id FROM courts WHERE facility_id = ? LIMIT 1', [$this->facility]);
        $ownBefore = $this->db()->fetchAssociative('SELECT * FROM courts WHERE id = ?', [$ownCourt]);
        $this->db()->executeStatement("DELETE FROM staff WHERE id = 'staff_policy'");
        self::assertSame(403, $this->write('update_court_status', ['court_id' => $ownCourt, 'status' => 'maintenance'], '/api')[0]);
        self::assertSame($ownBefore, $this->db()->fetchAssociative('SELECT * FROM courts WHERE id = ?', [$ownCourt]));
    }

    public function testOwningAnotherVenueDoesNotPermitStaffSettingsAndOwnersKeepUi(): void
    {
        $this->db()->insert('staff', ['id' => 'staff_other_owner', 'facility_id' => $this->facility, 'user_id' => 'usr_fx_owner2', 'name' => 'Other owner', 'role' => 'Front Desk', 'status' => 'Active']);
        $this->signIn('usr_fx_owner2');
        self::assertSame(403, $this->write('update_facility_settings', ['facility_id' => $this->facility, 'facility_name' => 'Unauthorized'])[0]);
        $this->client->request('GET', '/owner.php?tab=settings&facility_id=' . $this->facility);
        self::assertSame(0, $this->client->getCrawler()->filter('a[href*="tab=settings"]')->count());
        foreach (['usr_fx_owner1', 'usr_fx_admin'] as $user) {
            $this->signIn($user);
            $this->client->request('GET', '/owner.php?tab=staff&facility_id=' . $this->facility);
            self::assertSame(1, $this->client->getCrawler()->filter('#addStaffModal')->count());
            self::assertSame(2, $this->client->getCrawler()->filter('a[href*="tab=settings"]')->count());
        }
    }

    public function testForeignCourtRosterIsDeniedEvenWithAnAssignedFacility(): void
    {
        $this->signIn('usr_fx_p1');
        $court = (string)$this->scalar('SELECT id FROM courts WHERE facility_id = ? LIMIT 1', [$this->foreign]);
        $this->client->request('GET', '/api?action=get_open_play_roster&facility_id=' . $this->facility . '&court_id=' . $court . '&court_name=Court%201');
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertSame(403, $this->write('get_open_play_roster', ['facility_id' => $this->facility, 'court_id' => $court, 'court_name' => 'Court 1'])[0]);
    }

    public function testStaffCanEndAnActiveCourtSession(): void
    {
        $court = (string)$this->scalar('SELECT court_id FROM bookings WHERE id = ?', ['FX-CONF01']);
        $this->db()->executeStatement("UPDATE bookings SET booking_date = ?, start_min = 0, end_min = 1440, ended_early = 0 WHERE id = 'FX-CONF01'", [date('Y-m-d')]);
        $this->signIn('usr_fx_p1');
        self::assertSame(200, $this->write('end_court_session', ['facility_id' => $this->facility, 'court_id' => $court])[0]);
        self::assertSame(1, (int)$this->scalar("SELECT ended_early FROM bookings WHERE id = 'FX-CONF01'"));
    }

    public function testEndSessionRejectsCourtIdFromAnotherSelectedVenueWithoutEndingBookings(): void
    {
        $this->db()->insert('staff', ['id' => 'staff_policy_second', 'facility_id' => $this->foreign, 'user_id' => 'usr_fx_p1', 'name' => 'Player', 'role' => 'Front Desk', 'status' => 'Active']);
        $court = (string)$this->scalar("SELECT court_id FROM bookings WHERE id = 'FX-CONF01'");
        $name = (string)$this->scalar("SELECT court_name FROM bookings WHERE id = 'FX-CONF03'");
        $this->db()->executeStatement("UPDATE bookings SET booking_date = ?, start_min = 0, end_min = 1440, ended_early = 0 WHERE id IN ('FX-CONF01', 'FX-CONF03')", [date('Y-m-d')]);
        $before = $this->db()->fetchAllAssociative("SELECT * FROM bookings WHERE id IN ('FX-CONF01', 'FX-CONF03') ORDER BY id");
        $this->signIn('usr_fx_p1');
        [$status] = $this->write('end_court_session', ['facility_id' => $this->foreign, 'court_id' => $court, 'court_name' => $name]);
        self::assertSame($before, $this->db()->fetchAllAssociative("SELECT * FROM bookings WHERE id IN ('FX-CONF01', 'FX-CONF03') ORDER BY id"), 'Neither booking may be ended by mixed venue identifiers');
        self::assertSame(403, $status);
    }

    public function testEndSessionPreservesSameVenueAndLegacyCallers(): void
    {
        $court = (string)$this->scalar("SELECT court_id FROM bookings WHERE id = 'FX-CONF01'");
        $name = (string)$this->scalar("SELECT court_name FROM bookings WHERE id = 'FX-CONF01'");
        $this->signIn('usr_fx_p1');
        foreach ([['facility_id' => $this->facility, 'court_id' => $court, 'court_name' => $name], ['court_id' => $court, 'court_name' => $name], ['facility_id' => $this->facility, 'court_name' => $name]] as $params) {
            $this->db()->executeStatement("UPDATE bookings SET booking_date = ?, start_min = 0, end_min = 1440, ended_early = 0 WHERE id = 'FX-CONF01'", [date('Y-m-d')]);
            self::assertSame(200, $this->write('end_court_session', $params)[0]);
            self::assertSame(1, (int)$this->scalar("SELECT ended_early FROM bookings WHERE id = 'FX-CONF01'"));
        }
    }

    public function testEndSessionUsesCourtIdDespiteAnotherSameVenueCourtName(): void
    {
        $booking = $this->db()->fetchAssociative("SELECT * FROM bookings WHERE id = 'FX-CONF01'");
        $other = $this->db()->fetchAssociative('SELECT * FROM courts WHERE facility_id = ? AND id <> ? LIMIT 1', [$this->facility, $booking['court_id']]);
        self::assertIsArray($other);
        $this->db()->executeStatement("UPDATE bookings SET booking_date = ?, start_min = 0, end_min = 1440, ended_early = 0 WHERE id = 'FX-CONF01'", [date('Y-m-d')]);
        $before = $this->db()->fetchAssociative("SELECT * FROM bookings WHERE id = 'FX-CONF01'");
        $this->signIn('usr_fx_p1');
        self::assertSame(404, $this->write('end_court_session', ['facility_id' => $this->facility, 'court_id' => $other['id'], 'court_name' => $booking['court_name']])[0]);
        self::assertSame($before, $this->db()->fetchAssociative("SELECT * FROM bookings WHERE id = 'FX-CONF01'"));
        $second = $before;
        $second['id'] = 'task15_second_booking';
        $second['court_id'] = $other['id'];
        $second['court_name'] = $other['name'];
        $this->db()->insert('bookings', $second);
        self::assertSame(200, $this->write('end_court_session', ['facility_id' => $this->facility, 'court_id' => $booking['court_id'], 'court_name' => $other['name']])[0]);
        self::assertSame(1, (int)$this->scalar("SELECT ended_early FROM bookings WHERE id = 'FX-CONF01'"));
        self::assertSame($second, $this->db()->fetchAssociative("SELECT * FROM bookings WHERE id = 'task15_second_booking'"));
        self::assertSame(200, $this->write('end_court_session', ['facility_id' => $this->facility, 'court_id' => $other['id'], 'court_name' => $booking['court_name']])[0]);
        self::assertSame(1, (int)$this->scalar("SELECT ended_early FROM bookings WHERE id = 'task15_second_booking'"));
    }

    public function testEndSessionResolvesCanonicalNameOnlyForBookingsWithoutCourtIds(): void
    {
        $repo = static::getContainer()->get(\Picklers\Repositories\FacilityRepository::class);
        $booking = $this->db()->fetchAssociative("SELECT * FROM bookings WHERE id = 'FX-CONF01'");
        $other = $this->db()->fetchAssociative('SELECT * FROM courts WHERE facility_id = ? AND id <> ? LIMIT 1', [$this->facility, $booking['court_id']]);
        self::assertIsArray($other);
        foreach ([null, ''] as $legacyId) {
            $this->db()->executeStatement("UPDATE bookings SET court_id = ?, booking_date = ?, start_min = 0, end_min = 1440, ended_early = 0 WHERE id = 'FX-CONF01'", [$legacyId, date('Y-m-d')]);
            $before = $this->db()->fetchAssociative("SELECT * FROM bookings WHERE id = 'FX-CONF01'");
            self::assertFalse($repo->endCourtSessionEarly($this->foreign, (string)$booking['court_id'], (string)$booking['court_name'])['success']);
            self::assertFalse($repo->endCourtSessionEarly($this->facility, (string)$other['id'], (string)$booking['court_name'])['success']);
            self::assertSame($before, $this->db()->fetchAssociative("SELECT * FROM bookings WHERE id = 'FX-CONF01'"));
            $result = $repo->endCourtSessionEarly($this->facility, (string)$booking['court_id'], (string)$other['name']);
            self::assertTrue($result['success']);
            self::assertSame('FX-CONF01', $result['booking']['id']);
            self::assertSame(1, (int)$this->scalar("SELECT ended_early FROM bookings WHERE id = 'FX-CONF01'"));
        }
    }

    public function testEndSessionNeverMatchesAnotherNonemptyCourtIdByName(): void
    {
        $repo = static::getContainer()->get(\Picklers\Repositories\FacilityRepository::class);
        $booking = $this->db()->fetchAssociative("SELECT * FROM bookings WHERE id = 'FX-CONF01'");
        $other = $this->db()->fetchAssociative('SELECT * FROM courts WHERE facility_id = ? AND id <> ? LIMIT 1', [$this->facility, $booking['court_id']]);
        self::assertIsArray($other);
        $this->db()->executeStatement("UPDATE bookings SET court_id = ?, booking_date = ?, start_min = 0, end_min = 1440, ended_early = 0 WHERE id = 'FX-CONF01'", [$other['id'], date('Y-m-d')]);
        $before = $this->db()->fetchAssociative("SELECT * FROM bookings WHERE id = 'FX-CONF01'");
        self::assertFalse($repo->endCourtSessionEarly($this->facility, (string)$booking['court_id'], (string)$booking['court_name'])['success']);
        self::assertSame($before, $this->db()->fetchAssociative("SELECT * FROM bookings WHERE id = 'FX-CONF01'"));
        $result = $repo->endCourtSessionEarly($this->facility, '', (string)$booking['court_name']);
        self::assertTrue($result['success']);
        self::assertSame('FX-CONF01', $result['booking']['id']);
    }

    public function testRevokedOwnerMembershipAndReusedEmailDoNotAuthorizeCourtWrites(): void
    {
        $court = (string)$this->scalar('SELECT id FROM courts WHERE facility_id = ? LIMIT 1', [$this->facility]);
        $before = $this->db()->fetchAssociative('SELECT * FROM courts WHERE id = ?', [$court]);
        $this->db()->insert('staff', ['id' => 'staff_other_owner', 'facility_id' => $this->facility, 'user_id' => 'usr_fx_owner2', 'name' => 'Other owner', 'role' => 'Front Desk', 'status' => 'Active']);
        $this->signIn('usr_fx_owner2');
        self::assertSame(200, $this->write('toggle_court_status', ['court_id' => $court, 'active' => 1])[0]);
        $before = $this->db()->fetchAssociative('SELECT * FROM courts WHERE id = ?', [$court]);
        $this->db()->executeStatement("DELETE FROM staff WHERE id = 'staff_other_owner'");
        foreach (['edit_court' => ['name' => $before['name']], 'toggle_court_status' => ['active' => 0], 'delete_court' => [], 'end_court_session' => ['facility_id' => $this->facility]] as $action => $params) {
            self::assertSame(403, $this->write($action, ['court_id' => $court] + $params)[0], $action);
            self::assertSame($before, $this->db()->fetchAssociative('SELECT * FROM courts WHERE id = ?', [$court]));
        }
        $this->db()->executeStatement("UPDATE users SET email = 'old@fixture.test' WHERE id = 'usr_fx_p2'");
        foreach (['usr_fx_p2', 'usr_fx_p3'] as $user) {
            $this->signIn($user);
            self::assertSame(403, $this->write('update_court_status', ['court_id' => $court, 'status' => 'maintenance'], '/api')[0]);
            self::assertSame($before, $this->db()->fetchAssociative('SELECT * FROM courts WHERE id = ?', [$court]));
        }
    }

    private function write(string $action, array $params, string $path = '/owner.php'): array
    {
        $_SESSION['_sf2_attributes']['_csrf/picklers'] = 'staff-policy';
        $this->client->request('POST', $path . '?action=' . $action, $params, [], ['HTTP_X_CSRF_TOKEN' => 'staff-policy', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        return [$this->client->getResponse()->getStatusCode(), json_decode((string)$this->client->getResponse()->getContent(), true)];
    }
}
