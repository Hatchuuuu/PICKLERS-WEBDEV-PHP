<?php
declare(strict_types=1);

namespace Picklers\Tests\Web;

use Picklers\Tests\Admin\AdminWebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Owner portal pages, the bracket console and the owner application, served by Symfony. */
final class OwnerPagesTest extends AdminWebTestCase
{
    private const TOKEN = 'web-test-token';

    public function testDashboardOmitsEarningsShortcutAndPayoutControlRemainsOwnerOnly(): void
    {
        $facility = (string)$this->scalar("SELECT id FROM facilities WHERE owner_id = 'usr_fx_owner1' LIMIT 1");
        $this->signIn('usr_fx_owner1');
        $this->client->request('GET', '/owner', ['tab' => 'dashboard', 'facility_id' => $facility]);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href*="tab=earnings"]');
        $this->client->request('GET', '/owner', ['tab' => 'earnings', 'facility_id' => $facility]);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('button[onclick="openModal(\'payoutModal\')"]');

        $this->db()->insert('staff', ['id' => 'staff_earnings_nav', 'facility_id' => $facility, 'user_id' => 'usr_fx_p1', 'name' => 'Player', 'role' => 'Front Desk', 'status' => 'Active']);
        try {
            $this->signIn('usr_fx_p1');
            $this->client->request('GET', '/owner', ['tab' => 'dashboard', 'facility_id' => $facility]);
            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('a[href*="tab=earnings"]');
            $this->client->request('GET', '/owner', ['tab' => 'earnings', 'facility_id' => $facility]);
            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('button[onclick="openModal(\'payoutModal\')"]');
        } finally {
            $this->db()->executeStatement('DELETE FROM staff WHERE id = ?', ['staff_earnings_nav']);
        }
    }

    public static function applicantVerificationStates(): array
    {
        return [
            'verified player' => ['usr_fx_p1', 'verified', 'verified', 'verified'],
            'verified owner' => ['usr_fx_owner1', 'verified', 'verified', 'verified'],
            'unverified owner retained' => ['usr_fx_owner1', 'unverified', 'unverified', 'unverified'],
            'unverified applicant' => ['usr_fx_p4', 'unverified', 'pending_owner_review', 'unverified'],
            'verified during upload' => ['usr_fx_p4', 'verified', 'verified', 'verified'],
            'verification revoked during upload' => ['usr_fx_p1', 'unverified', 'pending_owner_review', 'unverified'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('applicantVerificationStates')]
    public function testApplicationReviewPreservesCurrentVerification(string $userId, string $currentVerification, string $pendingVerification, string $rejectedVerification): void
    {
        $this->db()->executeStatement('DELETE FROM owner_applications WHERE user_id = ?', [$userId]);
        $before = $this->db()->fetchAssociative('SELECT role, is_owner FROM users WHERE id = ?', [$userId]);
        $this->signIn($userId);
        $_SESSION['_sf2_attributes']['_csrf/picklers'] = self::TOKEN;
        $files = [];
        foreach (['permit_file', 'gov_id_file'] as $key) {
            $path = tempnam(sys_get_temp_dir(), 'pkl');
            file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
            $files[$key] = new class($path, $key . '.png', self::pdo(), $userId, $currentVerification) extends UploadedFile {
                public function __construct(string $path, string $name, private \PDO $db, private string $userId, private string $verification)
                {
                    parent::__construct($path, $name, 'image/png', null, true);
                }

                public function move(string $directory, ?string $name = null): \Symfony\Component\HttpFoundation\File\File
                {
                    // Change the persisted state after the controller has read its session user.
                    $statement = $this->db->prepare('UPDATE users SET verification_status = ? WHERE id = ?');
                    $statement->execute([$this->verification, $this->userId]);
                    return parent::move($directory, $name);
                }
            };
        }
        $application = null;
        try {
            $request = \Symfony\Component\HttpFoundation\Request::create('/owner-application', 'POST', [
                'facility_name' => 'Review Arena', 'address' => 'Dumaguete', 'owner_name' => 'Fixture Applicant',
                'business_email' => 'applicant@fixture.test', 'phone' => '09175550199',
                'entity_name' => 'Review Arena Inc.', 'reg_number' => 'DTI-REVIEW',
            ], [], $files, ['HTTP_X_CSRF_TOKEN' => self::TOKEN, 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
            $response = self::$kernel->handle($request);
            self::$kernel->terminate($request, $response);
            self::assertSame(200, $response->getStatusCode(), (string)$response->getContent());
            $application = $this->db()->fetchAssociative('SELECT * FROM owner_applications WHERE user_id = ? AND status = ?', [$userId, 'pending_review']);
            self::assertIsArray($application);
            self::assertSame($pendingVerification, $this->scalar('SELECT verification_status FROM users WHERE id = ?', [$userId]));
            self::assertSame($before, $this->db()->fetchAssociative('SELECT role, is_owner FROM users WHERE id = ?', [$userId]));
            $this->signIn('usr_fx_admin');
            $result = $this->action('admin_reject_owner_application', ['application_id' => $application['id'], 'reason' => 'Permit needs correction']);
            self::assertSame(200, $result['status']);
            self::assertSame('rejected', $this->scalar('SELECT status FROM owner_applications WHERE id = ?', [$application['id']]));
            self::assertSame($rejectedVerification, $this->scalar('SELECT verification_status FROM users WHERE id = ?', [$userId]));
            self::assertSame($before, $this->db()->fetchAssociative('SELECT role, is_owner FROM users WHERE id = ?', [$userId]));
        } finally {
            foreach ($this->db()->fetchAllAssociative('SELECT permit_file, gov_id_file FROM owner_applications WHERE user_id = ?', [$userId]) as $documents) {
                foreach ($documents as $document) {
                    @unlink($_ENV['PRIVATE_DOCUMENT_DIR'] . '/' . $document);
                }
            }
            $this->db()->executeStatement('DELETE FROM owner_applications WHERE user_id = ?', [$userId]);
        }
    }

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
        foreach (['address' => 200, 'operating_hours' => 50] as $field => $max) {
            $this->client->request('POST', '/owner-application', [$field => str_repeat('x', $max + 1)] + $fields, [], $headers);
            self::assertResponseStatusCodeSame(400);
            self::assertStringContainsString("max {$max} characters", (string)$this->client->getResponse()->getContent());
        }

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
        $this->client->request('POST', '/owner-application', $fields, [], ['HTTP_X_CSRF_TOKEN' => self::TOKEN]);
        self::assertResponseRedirects('/app.php?tab=settings&notice=application_pending');

        self::pdo()->exec("UPDATE owner_applications SET status = 'rejected' WHERE user_id = 'usr_fx_p1'");
        $files = [];
        foreach (['permit_file', 'gov_id_file'] as $key) {
            $path = tempnam(sys_get_temp_dir(), 'pkl');
            file_put_contents($path, $png);
            $files[$key] = new UploadedFile($path, "{$key}.png", 'image/png', null, true);
        }
        $this->client->request('POST', '/owner-application', $fields, $files, $headers);
        self::assertResponseIsSuccessful();
        self::assertSame(1, (int)$this->scalar("SELECT COUNT(*) FROM owner_applications WHERE user_id = 'usr_fx_p1' AND status = 'pending_review'"), 'a resolved review permits another application');
        foreach (self::pdo()->query("SELECT permit_file, gov_id_file FROM owner_applications WHERE user_id = 'usr_fx_p1'")->fetchAll() as $documents) {
            foreach ($documents as $document) {
                @unlink($_ENV['PRIVATE_DOCUMENT_DIR'] . '/' . $document);
            }
        }
        self::pdo()->exec("DELETE FROM owner_applications WHERE user_id = 'usr_fx_p1'");
    }
}
