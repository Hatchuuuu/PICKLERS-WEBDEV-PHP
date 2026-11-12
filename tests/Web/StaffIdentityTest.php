<?php
declare(strict_types=1);

namespace Picklers\Tests\Web;

use Picklers\Repositories\OwnerRepository;
use Picklers\Tests\Admin\AdminWebTestCase;

final class StaffIdentityTest extends AdminWebTestCase
{
    public function testStaffSearchFiltersEligibilityBeforeResultLimit(): void
    {
        $this->signIn('usr_fx_owner1');
        $this->db()->executeStatement("UPDATE users SET name = 'Staffsearch Z Player' WHERE id = 'usr_fx_p1'");
        $this->db()->executeStatement("UPDATE users SET name = 'Staffsearch A Account' WHERE id IN ('usr_fx_owner2', 'usr_fx_admin', 'usr_fx_gone')");
        $player = $this->db()->fetchAssociative("SELECT * FROM users WHERE id = 'usr_fx_p1'");
        $ids = [];
        try {
            foreach (['owner', 'admin', 'deactivated', 'deleted', 'owner', 'admin', 'owner', 'admin', 'owner', 'admin', 'owner'] as $i => $role) {
                $row = $player;
                $row['id'] = $ids[] = 'usr_staffsearch_' . $i;
                $row['name'] = 'Staffsearch A Account ' . $i;
                $row['email'] = 'staffsearch' . $i . '@fixture.test';
                $row['phone'] = null;
                $row['role'] = $role;
                $this->db()->insert('users', $row);
            }
            $this->client->request('GET', '/api?action=search_players&q=Staffsearch&context=staff');
            self::assertSame(200, $this->client->getResponse()->getStatusCode());
            $staff = json_decode((string)$this->client->getResponse()->getContent(), true)['users'];
            self::assertSame(['usr_fx_p1'], array_column($staff, 'id'));
            foreach (['search_players', 'search_users'] as $action) {
                $this->client->request('GET', '/api?action=' . $action . '&q=Staffsearch');
                $general = json_decode((string)$this->client->getResponse()->getContent(), true)['users'];
                self::assertCount(10, $general);
                self::assertContains('owner', array_column($general, 'role'));
                self::assertNotContains('deleted', array_column($general, 'role'));
            }
        } finally {
            foreach ($ids as $id) $this->db()->delete('users', ['id' => $id]);
        }
    }

    public function testSelectedPlayerIsRevalidatedAfterDeactivationOrEmailReuse(): void
    {
        $this->signIn('usr_fx_owner1');
        $email = (string)$this->scalar("SELECT email FROM users WHERE id = 'usr_fx_p1'");
        $params = ['name' => 'Player', 'email' => $email, 'user_id' => 'usr_fx_p1'];
        $this->db()->executeStatement("UPDATE users SET role = 'deactivated' WHERE id = 'usr_fx_p1'");
        self::assertSame(400, $this->ownerRequest('add_staff', $params)[0]);
        $this->db()->executeStatement("UPDATE users SET role = 'player', email = 'changed@fixture.test' WHERE id = 'usr_fx_p1'");
        $this->db()->executeStatement("UPDATE users SET email = ? WHERE id = 'usr_fx_p2'", [$email]);
        self::assertSame(400, $this->ownerRequest('add_staff', $params)[0]);
        self::assertSame(0, (int)$this->scalar("SELECT COUNT(*) FROM staff WHERE user_id IN ('usr_fx_p1', 'usr_fx_p2')"));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->db()->executeStatement("DELETE FROM staff WHERE user_id IN ('usr_fx_p1', 'usr_fx_p2')");
    }

    public function testRepeatedGrantThenRevokeRemovesMembership(): void
    {
        $this->signIn('usr_fx_owner1');
        $params = ['name' => 'Player', 'email' => $this->scalar("SELECT email FROM users WHERE id = 'usr_fx_p1'"), 'user_id' => 'usr_fx_p1'];
        [$status, $first] = $this->ownerRequest('add_staff', $params);
        self::assertSame(200, $status);
        [$status, $second] = $this->ownerRequest('add_staff', $params);
        self::assertSame(200, $status);
        self::assertSame($first['staff']['id'], $second['staff']['id']);
        self::assertSame(200, $this->ownerRequest('revoke_staff', ['staff_id' => $first['staff']['id']])[0]);
        self::assertSame(403, $this->ownerRequest('revoke_staff', ['staff_id' => $first['staff']['id']])[0]);
        $this->signIn('usr_fx_p1');
        $this->client->request('GET', '/owner.php?tab=staff');
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('owner-application.php', (string)$this->client->getResponse()->headers->get('Location'));
    }

    public function testHistoricalDuplicatesRevokeTogetherWithoutTouchingOtherFacility(): void
    {
        $owners = static::getContainer()->get(OwnerRepository::class);
        $facility = $this->scalar("SELECT id FROM facilities WHERE owner_id = 'usr_fx_owner1' LIMIT 1");
        $other = $this->scalar("SELECT id FROM facilities WHERE owner_id = 'usr_fx_owner2' LIMIT 1");
        foreach (['staff_duplicate_a' => $facility, 'staff_duplicate_b' => $facility, 'staff_other' => $other] as $id => $venue) {
            $this->db()->insert('staff', ['id' => $id, 'facility_id' => $venue, 'user_id' => 'usr_fx_p1', 'name' => 'Player', 'email' => 'old@fixture.test', 'role' => 'Front Desk', 'status' => 'Active', 'date_joined' => 'Oct 2026']);
        }
        $this->signIn('usr_fx_owner1');
        $this->client->request('GET', '/owner.php?tab=staff');
        self::assertSame('usr_fx_p1', $this->client->getCrawler()->filter('.staff-member-card')->first()->attr('data-user-id'));
        self::assertSame((string)$facility, $this->client->getCrawler()->filter('.staff-member-card')->first()->attr('data-facility-id'));
        self::assertSame(200, $this->ownerRequest('revoke_staff', ['staff_id' => 'staff_duplicate_a'])[0]);
        self::assertSame([(string)$other], array_map('strval', $owners->getStaffFacilityIdsForUser('usr_fx_p1', 'old@fixture.test')));
        self::assertFalse($owners->deleteStaff('staff_duplicate_b'));
    }

    public function testAssignedStaffCannotGrantSelfOrOthersOrRevoke(): void
    {
        $this->signIn('usr_fx_owner1');
        $params = ['name' => 'Player', 'email' => $this->scalar("SELECT email FROM users WHERE id = 'usr_fx_p1'"), 'user_id' => 'usr_fx_p1'];
        [$status, $grant] = $this->ownerRequest('add_staff', $params);
        self::assertSame(200, $status);
        $this->signIn('usr_fx_p1');
        self::assertSame(403, $this->ownerRequest('add_staff', $params)[0]);
        $params['user_id'] = 'usr_fx_p2';
        $params['email'] = $this->scalar("SELECT email FROM users WHERE id = 'usr_fx_p2'");
        self::assertSame(403, $this->ownerRequest('add_staff', $params)[0]);
        self::assertSame(403, $this->ownerRequest('revoke_staff', ['staff_id' => $grant['staff']['id']])[0]);
    }

    public function testAdministratorCanGrantAndRevokeForSelectedVenue(): void
    {
        $facility = $this->scalar("SELECT id FROM facilities WHERE owner_id = 'usr_fx_owner2' LIMIT 1");
        $params = ['facility_id' => $facility, 'name' => 'Player', 'email' => $this->scalar("SELECT email FROM users WHERE id = 'usr_fx_p1'"), 'user_id' => 'usr_fx_p1'];
        $this->signIn('usr_fx_owner1');
        self::assertSame(403, $this->ownerRequest('add_staff', $params)[0]);
        $this->signIn('usr_fx_admin');
        [$status, $grant] = $this->ownerRequest('add_staff', $params);
        self::assertSame(200, $status);
        self::assertSame((string)$facility, (string)$grant['staff']['facility_id']);
        self::assertSame(200, $this->ownerRequest('revoke_staff', ['staff_id' => $grant['staff']['id']])[0]);
    }

    public function testConcurrentGrantsReturnOneMembership(): void
    {
        $facility = $this->scalar("SELECT id FROM facilities WHERE owner_id = 'usr_fx_owner1' LIMIT 1");
        $root = dirname(__DIR__, 2);
        $processes = [];
        $connections = [];
        $this->db()->beginTransaction();
        $this->db()->fetchOne('SELECT id FROM facilities WHERE id = ? FOR UPDATE', [$facility]);
        try {
            for ($i = 0; $i < 2; $i++) {
                $processes[] = proc_open([PHP_BINARY, $root . '/tests/Web/staff-grant-worker.php', (string)$facility], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i], $root, ['PICKLERS_SKIP_DB_REBUILD' => '1'] + getenv());
                stream_set_timeout($pipes[$i][1], 10);
                $connections[] = (int)fgets($pipes[$i][1]);
                self::assertGreaterThan(0, $connections[$i], 'Worker is ready with an independent connection');
            }
            self::assertNotSame($connections[0], $connections[1]);
            foreach ($pipes as $workerPipes) {
                fwrite($workerPipes[0], "grant\n");
                fclose($workerPipes[0]);
            }
            $deadline = microtime(true) + 10;
            do {
                $waiting = (int)$this->scalar("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID IN (?, ?) AND COMMAND = 'Query' AND INFO LIKE 'SELECT id FROM facilities WHERE id = % FOR UPDATE'", $connections);
                if ($waiting === 2) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $states = $this->db()->fetchAllAssociative('SELECT ID, STATE, INFO FROM information_schema.PROCESSLIST WHERE ID IN (?, ?)', $connections);
        } finally {
            $this->db()->rollBack();
        }
        $ids = [];
        foreach ($processes as $i => $process) {
            $output = stream_get_contents($pipes[$i][1]);
            $error = stream_get_contents($pipes[$i][2]);
            self::assertSame(0, proc_close($process), $error);
            $ids[] = json_decode($output, true)['id'];
        }
        self::assertSame(2, $waiting, 'Both workers must overlap while blocked by the venue lock: ' . json_encode($states));
        self::assertSame($ids[0], $ids[1]);
        self::assertSame(1, (int)$this->scalar('SELECT COUNT(*) FROM staff WHERE facility_id = ? AND user_id = ?', [$facility, 'usr_fx_p1']));
    }

    private function ownerRequest(string $action, array $params): array
    {
        $_SESSION['_sf2_attributes']['_csrf/picklers'] = 'staff-test';
        $this->client->request('POST', '/owner.php?action=' . $action, $params, [], ['HTTP_X_CSRF_TOKEN' => 'staff-test', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        $response = $this->client->getResponse();
        return [$response->getStatusCode(), json_decode((string)$response->getContent(), true)];
    }

    public function testGrantSurvivesEmailChangeWithoutTransferringAndCanBeRevoked(): void
    {
        $this->signIn('usr_fx_owner1');
        $email = (string)$this->scalar("SELECT email FROM users WHERE id = 'usr_fx_p1'");
        [$status, $json] = $this->ownerRequest('add_staff', ['name' => 'Player', 'email' => $email, 'user_id' => 'usr_fx_p1']);
        self::assertSame(200, $status);
        $staff = $json['staff'];
        self::assertSame('usr_fx_p1', $this->scalar('SELECT user_id FROM staff WHERE id = ?', [$staff['id']]));
        $this->db()->executeStatement("UPDATE users SET email = 'changed@fixture.test', avatar_url = 'original-avatar' WHERE id = 'usr_fx_p1'");
        $this->db()->executeStatement("UPDATE users SET email = ?, avatar_url = 'replacement-avatar' WHERE id = 'usr_fx_p2'", [$email]);
        $owners = static::getContainer()->get(OwnerRepository::class);
        self::assertContains((string)$staff['facility_id'], array_map('strval', $owners->getStaffFacilityIdsForUser('usr_fx_p1', 'changed@fixture.test')));
        self::assertSame([], $owners->getStaffFacilityIdsForUser('usr_fx_p2', $email));
        $rows = array_values(array_filter($owners->getStaffByFacility($staff['facility_id']), fn($row) => $row['id'] === $staff['id']));
        self::assertCount(1, $rows);
        self::assertSame('original-avatar', $rows[0]['avatar_url']);
        $this->signIn('usr_fx_owner2');
        self::assertSame(403, $this->ownerRequest('revoke_staff', ['staff_id' => $staff['id']])[0]);
        $this->signIn('usr_fx_owner1');
        self::assertSame(200, $this->ownerRequest('revoke_staff', ['staff_id' => $staff['id']])[0]);
        self::assertSame([], $owners->getStaffFacilityIdsForUser('usr_fx_p1', 'changed@fixture.test'));
    }

    public function testLegacyEmailAloneCannotAuthorizeOrJoinReplacementAccount(): void
    {
        $owners = static::getContainer()->get(OwnerRepository::class);
        $facility = $this->scalar("SELECT id FROM facilities WHERE owner_id = 'usr_fx_owner1' LIMIT 1");
        $email = (string)$this->scalar("SELECT email FROM users WHERE id = 'usr_fx_p2'");
        $staff = $owners->insertStaff(['facility_id' => $facility, 'email' => $email]);
        self::assertSame([], $owners->getStaffFacilityIdsForUser('usr_fx_p2', $email));
        $rows = array_values(array_filter($owners->getStaffByFacility($facility), fn($row) => $row['id'] === $staff['id']));
        self::assertSame('', $rows[0]['avatar_url']);
        $owners->deleteStaff($staff['id']);
    }

    public function testGrantRequiresExistingPlayerAndMatchingSelection(): void
    {
        $this->signIn('usr_fx_owner1');
        $email = (string)$this->scalar("SELECT email FROM users WHERE id = 'usr_fx_p1'");
        foreach (['', 'missing-user', 'usr_fx_owner2', 'usr_fx_gone', 'usr_fx_p2'] as $id) {
            self::assertSame(400, $this->ownerRequest('add_staff', ['name' => 'Player', 'email' => $email, 'user_id' => $id])[0], $id);
        }
    }
}
