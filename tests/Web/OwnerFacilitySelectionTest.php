<?php
declare(strict_types=1);

namespace Picklers\Tests\Web;

use Picklers\Tests\Admin\AdminWebTestCase;

final class OwnerFacilitySelectionTest extends AdminWebTestCase
{
    public function testSelectedFacilitySurvivesPageNavigationAndWritesCannotReachOtherOwners(): void
    {
        $first = $this->db()->fetchAssociative("SELECT * FROM facilities WHERE owner_id = 'usr_fx_owner1' LIMIT 1");
        $copy = $first;
        unset($copy['id']);
        $copy['name'] = 'Selection fixture B';
        $this->db()->insert('facilities', $copy);
        $second = (string)$this->db()->lastInsertId();
        $foreign = (string)$this->scalar("SELECT id FROM facilities WHERE owner_id = 'usr_fx_owner2' LIMIT 1");
        try {
            $this->signIn('usr_fx_owner1');
            $crawler = $this->client->request('GET', '/owner.php', ['tab' => 'settings', 'facility_id' => $second]);
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('window.PICKLERS_FACILITY_ID = ' . $second . ';', (string)$this->client->getResponse()->getContent());
            $links = $crawler->filter('a[href*="tab="]');
            self::assertGreaterThan(0, $links->count(), 'actual owner navigation was inspected');
            foreach ($links as $link) {
                parse_str((string)parse_url($link->getAttribute('href'), PHP_URL_QUERY), $params);
                self::assertSame($second, $params['facility_id']);
            }
            $_SESSION['_sf2_attributes']['_csrf/picklers'] = 'selection-test';
            $this->client->request('POST', '/owner.php?action=update_facility_settings&facility_id=' . $second, ['facility_name' => 'Selection B updated'], [], ['HTTP_X_CSRF_TOKEN' => 'selection-test']);
            self::assertResponseIsSuccessful();
            self::assertSame('Selection B updated', $this->scalar('SELECT name FROM facilities WHERE id = ?', [$second]));
            self::assertSame($first['name'], $this->scalar('SELECT name FROM facilities WHERE id = ?', [$first['id']]));
            $this->client->request('POST', '/owner.php?action=update_facility_settings&facility_id=' . $foreign, ['facility_name' => 'Unauthorized change'], [], ['HTTP_X_CSRF_TOKEN' => 'selection-test']);
            self::assertResponseStatusCodeSame(403);
            self::assertNotSame('Unauthorized change', $this->scalar('SELECT name FROM facilities WHERE id = ?', [$foreign]));
        } finally {
            $this->db()->delete('facilities', ['id' => $second]);
        }
    }
}
