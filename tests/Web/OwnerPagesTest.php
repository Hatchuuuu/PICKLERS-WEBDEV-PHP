<?php
declare(strict_types=1);

namespace Picklers\Tests\Web;

use Picklers\Tests\Admin\AdminWebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Owner portal pages, the bracket console and the owner application, served by Symfony. */
final class OwnerPagesTest extends AdminWebTestCase
{
    private const TOKEN = 'web-test-token';

    public function testPortalIsForOwnersAndRendersEveryTab(): void
    {
        $this->client->request('GET', '/owner');
        self::assertResponseRedirects('/auth.php');

        $this->signIn('usr_fx_p1');
        $this->client->request('GET', '/owner.php');
        self::assertResponseRedirects('/owner-application.php?notice=verification_required');

        $this->signIn('usr_fx_owner1');
        foreach (['dashboard', 'courts', 'tournaments', 'staff', 'earnings', 'messages', 'settings'] as $tab) {
            $this->client->request('GET', '/app/owner', ['tab' => $tab]);
            self::assertResponseIsSuccessful($tab);
            self::assertSelectorExists('meta[name="csrf-token"]', $tab);
        }
        $this->client->request('GET', '/owner', ['tab' => 'courts']);
        self::assertSelectorTextContains('#courtsListContainer', 'Court 1');
    }

    public function testOwnerWithoutAFacilitySeesOnboarding(): void
    {
        self::pdo()->exec("UPDATE users SET is_owner = 1 WHERE id = 'usr_fx_p4'");
        $this->signIn('usr_fx_p4');
        $this->client->request('GET', '/owner');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Rico Smash Hub');
    }

    public function testBracketConsoleOnlyForTheOwningVenue(): void
    {
        $this->signIn('usr_fx_owner1');
        $fac = (string)$this->scalar("SELECT id FROM facilities WHERE name = 'Fixture Arena Dumaguete'");
        $t = static::getContainer()->get(\Picklers\Services\TournamentService::class)
            ->create(['facility_id' => $fac, 'owner_id' => 'usr_fx_owner1', 'title' => 'Console Cup', 'category' => 'Doubles'])['tournament'];

        $this->client->request('GET', '/app/owner/tournaments/' . $t['id']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.tb-title', 'Console Cup');

        $this->signIn('usr_fx_owner2');
        $this->client->request('GET', '/owner/tournaments/' . $t['id']);
        self::assertResponseStatusCodeSame(404, "another venue's tournament is not confirmed");
        $this->client->request('GET', '/owner/tournaments/tourn_does_not_exist');
        self::assertResponseStatusCodeSame(404);
        static::getContainer()->get(\Picklers\Services\TournamentService::class)->delete($t['id']);
    }

    public function testApplicationPageAndSubmission(): void
    {
        $this->client->request('GET', '/owner-application');
        self::assertResponseRedirects('/auth.php');

        self::pdo()->exec("DELETE FROM owner_applications WHERE user_id = 'usr_fx_p1'");
        $this->signIn('usr_fx_p1');
        $this->client->request('GET', '/owner-application.php');
        self::assertResponseIsSuccessful();

        $_SESSION['_sf2_attributes']['_csrf/picklers'] = self::TOKEN;
        $headers = ['HTTP_X_CSRF_TOKEN' => self::TOKEN, 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'];
        $fields = [
            'facility_name' => 'Paula Courts', 'address' => 'Dumaguete', 'owner_name' => 'Paula Player',
            'business_email' => 'paula@fixture.test', 'phone' => '+63 917 555 0199', 'entity_name' => 'Paula Courts Inc.',
            'reg_number' => 'DTI-FX-0099', 'courts_count' => 99,
        ];
        $this->client->request('POST', '/owner-application', ['facility_name' => ''] + $fields, [], $headers);
        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('Facility Brand Name', (string)$this->client->getResponse()->getContent());

        $this->client->request('POST', '/owner-application', $fields, [], $headers);
        self::assertStringContainsString('upload', (string)$this->client->getResponse()->getContent(), 'documents are required');

        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $files = [];
        foreach (['permit_file', 'gov_id_file'] as $key) {
            $path = tempnam(sys_get_temp_dir(), 'pkl') . '.png';
            file_put_contents($path, $png);
            $files[$key] = new UploadedFile($path, "{$key}.png", 'image/png', null, true);
        }
        $this->client->request('POST', '/owner-application', $fields, $files, $headers);
        $json = json_decode((string)$this->client->getResponse()->getContent(), true);
        self::assertTrue($json['success'] ?? false, (string)$this->client->getResponse()->getContent());
        $app = self::pdo()->query("SELECT * FROM owner_applications WHERE user_id = 'usr_fx_p1' ORDER BY created_at DESC LIMIT 1")->fetch();
        self::assertSame('pending_review', $app['status']);
        self::assertSame(50, (int)$app['courts_count'], 'bounded before it becomes real court rows');
        self::assertFileExists($_ENV['PRIVATE_DOCUMENT_DIR'] . '/' . $app['permit_file'], 'stored privately, outside public/');
        self::assertSame(0, (int)$this->scalar("SELECT is_owner FROM users WHERE id = 'usr_fx_p1'"), 'filing never grants owner access');

        $this->client->request('POST', '/owner-application', $fields, [], $headers);
        self::assertResponseStatusCodeSame(409, 'one application under review at a time');
        self::pdo()->exec("DELETE FROM owner_applications WHERE user_id = 'usr_fx_p1'");
    }
}
