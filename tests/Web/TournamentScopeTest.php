<?php
declare(strict_types=1);

namespace Picklers\Tests\Web;

use Picklers\Tests\Admin\AdminWebTestCase;

final class TournamentScopeTest extends AdminWebTestCase
{
    private array $facilityIds;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['usr_fx_owner1', 'usr_fx_owner2'] as $owner) {
            $facility = (string)$this->scalar('SELECT id FROM facilities WHERE owner_id = ? ORDER BY id LIMIT 1', [$owner]);
            self::assertNotSame('', $facility);
            $this->facilityIds[$owner] = $facility;
            $id = 'scope_' . $owner;
            $data = ['id' => $id, 'facility_id' => $facility, 'title' => $id, 'category' => 'Singles', 'status' => 'upcoming', 'players' => [['id' => 'private_' . $owner, 'name' => 'Private participant']]];
            $this->db()->executeStatement('INSERT INTO tournaments (id, facility_id, data) VALUES (?, ?, ?)', [$id, $facility, json_encode($data)]);
        }
    }

    protected function tearDown(): void
    {
        $this->db()->executeStatement('DELETE FROM tournaments WHERE id IN (?, ?)', ['scope_usr_fx_owner1', 'scope_usr_fx_owner2']);
        $this->db()->executeStatement('UPDATE users SET is_owner = 0 WHERE id = ?', ['usr_fx_p5']);
        parent::tearDown();
    }

    private function listing(string $user, ?string $facility = null): array
    {
        $this->signIn($user);
        $params = ['action' => 'list_tournaments'];
        if ($facility !== null) {
            $params['facility_id'] = $facility;
        }
        $this->client->request('GET', '/api.php?' . http_build_query($params));
        $response = $this->client->getResponse();

        return ['status' => $response->getStatusCode(), 'json' => json_decode((string)$response->getContent(), true)];
    }

    private function assertDenied(array $response): void
    {
        self::assertSame(403, $response['status']);
        self::assertFalse($response['json']['success']);
        self::assertSame('Unauthorized: owner access required.', $response['json']['message']);
        self::assertArrayNotHasKey('tournaments', $response['json']);
        self::assertStringNotContainsString('Private participant', json_encode($response['json']));
    }

    public function testForeignFacilityIsDenied(): void
    {
        $this->assertDenied($this->listing('usr_fx_owner1', $this->facilityIds['usr_fx_owner2']));
    }

    public function testUnknownFacilityIsDeniedForOwnerAndAdmin(): void
    {
        foreach (['usr_fx_owner1', 'usr_fx_admin'] as $user) {
            $this->assertDenied($this->listing($user, 'unknown_scope_facility'));
        }
    }

    public function testOwnerWithoutFacilitiesIsDenied(): void
    {
        $this->db()->executeStatement('UPDATE users SET is_owner = 1 WHERE id = ?', ['usr_fx_p5']);
        $this->assertDenied($this->listing('usr_fx_p5'));
    }

    public function testOwnerDefaultAndExplicitFacilityRemainScoped(): void
    {
        foreach ([null, $this->facilityIds['usr_fx_owner1']] as $facility) {
            $this->assertScoped($this->listing('usr_fx_owner1', $facility), 'usr_fx_owner1');
        }
    }

    public function testAdminCanSelectEitherOwnersFacility(): void
    {
        foreach ($this->facilityIds as $owner => $facility) {
            $this->assertScoped($this->listing('usr_fx_admin', $facility), $owner);
        }
    }

    private function assertScoped(array $response, string $owner): void
    {
        self::assertSame(200, $response['status']);
        self::assertTrue($response['json']['success']);
        $tournaments = array_merge(...array_values($response['json']['tournaments']));
        self::assertContains('scope_' . $owner, array_column($tournaments, 'id'));
        self::assertSame([$this->facilityIds[$owner]], array_values(array_unique(array_column($tournaments, 'facility_id'))));
        $foreign = $owner === 'usr_fx_owner1' ? 'usr_fx_owner2' : 'usr_fx_owner1';
        self::assertNotContains('scope_' . $foreign, array_column($tournaments, 'id'));
        self::assertStringNotContainsString('private_' . $foreign, json_encode($response['json']));
    }
}
