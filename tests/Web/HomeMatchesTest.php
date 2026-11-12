<?php
declare(strict_types=1);

namespace Picklers\Tests\Web;

use Picklers\Tests\Admin\AdminWebTestCase;

final class HomeMatchesTest extends AdminWebTestCase
{
    public function testLandingCardsExcludeExpiredSessionsAndUseLiveFacilityNames(): void
    {
        $db = self::pdo();
        $facilityId = $db->query("SELECT id FROM facilities WHERE name = 'Fixture Arena Dumaguete'")->fetchColumn();
        $insert = $db->prepare(
            'INSERT INTO matches (id, facility_id, facility_name, date, time, current_players, max_players, type, title) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        try {
            $insert->execute(['op_zz_home_expired', $facilityId, 'Old facility name', '1900-01-01', '8:00 AM - 9:00 AM', 0, 8, 'Court 1', 'Expired landing session']);
            $insert->execute(['op_zz_home_recurring', $facilityId, 'Stale landing facility name', 'Everyday', '12:00 AM - 11:59 PM', 7, 8, 'Court 2', null]);

            $crawler = $this->client->request('GET', '/');
            self::assertResponseIsSuccessful();
            $cards = $crawler->filter('#openPlayGrid');
            self::assertStringNotContainsString('Expired landing session', $cards->text());
            self::assertStringNotContainsString('Stale landing facility name', $cards->text());
            self::assertStringContainsString('Fixture Arena Dumaguete', $cards->filter('.openplay-card-title')->text());
            self::assertStringContainsString('0 of 8 spots filled', $cards->filter('.openplay-spots-filled')->text(), 'Recurring occupancy comes from bookings for this occurrence.');
        } finally {
            $db->exec("DELETE FROM matches WHERE id IN ('op_zz_home_expired', 'op_zz_home_recurring')");
        }
    }
}
