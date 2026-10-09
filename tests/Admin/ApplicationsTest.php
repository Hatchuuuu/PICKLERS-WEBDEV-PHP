<?php
declare(strict_types=1);

namespace Picklers\Tests\Admin;

/** R02 — partner applications and private documents. */
final class ApplicationsTest extends AdminWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->signIn('usr_fx_admin');
    }

    public function testApproveProvisionsExactlyOneFacilityAtomically(): void
    {
        $before = (int)$this->scalar('SELECT COUNT(*) FROM facilities');
        $r = $this->action('admin_approve_owner_application', ['user_id' => 'usr_fx_p4', 'application_id' => 'fx_app_pending1', 'reason' => 'Docs verified']);
        self::assertSame(200, $r['status'], json_encode($r['json']));
        $facilityId = (int)$r['json']['facility_id'];
        self::assertSame($before + 1, (int)$this->scalar('SELECT COUNT(*) FROM facilities'));
        self::assertSame(2, (int)$this->scalar('SELECT COUNT(*) FROM courts WHERE facility_id = ?', [$facilityId]));
        $app = self::pdo()->query("SELECT * FROM owner_applications WHERE id = 'fx_app_pending1'")->fetch();
        self::assertSame('approved', $app['status']);
        self::assertSame('usr_fx_admin', $app['reviewed_by']);
        self::assertSame('Docs verified', $app['review_reason']);
        self::assertSame($facilityId, (int)$app['facility_id']);
        self::assertSame('1', (string)$this->scalar("SELECT is_owner FROM users WHERE id = 'usr_fx_p4'"));
        $audit = $this->lastAudit('application.approve');
        self::assertSame('fx_app_pending1', $audit['target_id']);
        self::assertSame('usr_fx_admin', $audit['actor_user_id']);
        self::assertSame($r['response']->headers->get('X-Request-Id'), $audit['correlation_id']);

        // Repeat → refused, no second facility.
        $again = $this->action('admin_approve_owner_application', ['user_id' => 'usr_fx_p4', 'application_id' => 'fx_app_pending1']);
        self::assertSame(409, $again['status']);
        self::assertSame($before + 1, (int)$this->scalar('SELECT COUNT(*) FROM facilities'));
        // Approved → reject is a forbidden transition.
        self::assertSame(409, $this->action('admin_reject_owner_application', ['application_id' => 'fx_app_pending1', 'reason' => 'Changed my mind'])['status']);
    }

    public function testConcurrentApprovalsCreateAtMostOneFacility(): void
    {
        $before = (int)$this->scalar("SELECT COUNT(*) FROM facilities WHERE owner_id = 'usr_fx_p4'");
        $results = $this->concurrently(4, 'approve', ['application_id' => 'fx_app_pending1']);
        $ok = array_filter($results, fn($r) => ($r['ok'] ?? false) === true);
        self::assertCount(1, $ok, 'exactly one approval wins: ' . json_encode($results));
        foreach ($results as $r) {
            if (!($r['ok'] ?? false)) {
                self::assertSame(409, $r['status'] ?? null, json_encode($r));
            }
        }
        self::assertSame($before + 1, (int)$this->scalar("SELECT COUNT(*) FROM facilities WHERE owner_id = 'usr_fx_p4'"));
    }

    public function testRejectNeedsReasonAndNotifies(): void
    {
        $r = $this->action('admin_reject_owner_application', ['application_id' => 'fx_app_pending2', 'reason' => 'no']);
        self::assertSame(422, $r['status']);
        self::assertSame('reason', $r['json']['field']);
        self::assertSame('pending_review', $this->scalar("SELECT status FROM owner_applications WHERE id = 'fx_app_pending2'"));

        $r = $this->action('admin_reject_owner_application', ['user_id' => 'usr_fx_p5', 'application_id' => 'fx_app_pending2', 'reason' => 'Permit file is missing from the submission']);
        self::assertSame(200, $r['status']);
        self::assertSame('rejected', $this->scalar("SELECT status FROM owner_applications WHERE id = 'fx_app_pending2'"));
        self::assertStringContainsString('Permit file is missing', (string)$this->scalar("SELECT body FROM notifications WHERE user_id = 'usr_fx_p5' ORDER BY created_at DESC LIMIT 1"));
    }

    public function testMismatchedApplicantIsRefused(): void
    {
        $r = $this->action('admin_approve_owner_application', ['user_id' => 'usr_fx_p1', 'application_id' => 'fx_app_pending1']);
        self::assertSame(422, $r['status']);
        self::assertSame('pending_review', $this->scalar("SELECT status FROM owner_applications WHERE id = 'fx_app_pending1'"));
    }

    public function testProvisioningFailureRollsEverythingBack(): void
    {
        self::pdo()->exec("UPDATE owner_applications SET facility_name = '' WHERE id = 'fx_app_pending1'");
        $before = (int)$this->scalar('SELECT COUNT(*) FROM facilities');
        $r = $this->action('admin_approve_owner_application', ['application_id' => 'fx_app_pending1']);
        self::assertSame(500, $r['status']);
        self::assertStringContainsString('nothing was changed', $r['json']['message']);
        self::assertSame('pending_review', $this->scalar("SELECT status FROM owner_applications WHERE id = 'fx_app_pending1'"));
        self::assertSame('0', (string)$this->scalar("SELECT is_owner FROM users WHERE id = 'usr_fx_p4'"));
        self::assertSame($before, (int)$this->scalar('SELECT COUNT(*) FROM facilities'));
    }

    public function testListSearchFilterAndDetail(): void
    {
        $this->client->request('GET', '/admin/panel/applications', ['status' => 'pending_review', 'q' => 'Rico']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.pk-pager__count', 'of 1');
        self::assertSelectorTextContains('table', 'Rico Smash Hub');
        $this->client->request('GET', '/admin/detail/application/fx_app_pending2');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.pk-doc', 'File missing');
    }

    public function testPrivateDocumentDelivery(): void
    {
        $this->client->request('GET', '/admin/document', ['file' => 'fx_permit_rico.png']);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/png');
        self::assertStringContainsString('no-store', (string)$this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSame('fx_app_pending1', $this->lastAudit('document.view')['target_id']);

        foreach (['../.env', '..\\..\\.env', 'fx_permit_rico.png/../x.png', '%2e%2e%2f.env', 'unknown_file.png', 'fx_permit_missing.pdf', 'fx_permit_rico.php', ''] as $bad) {
            $this->client->request('GET', '/admin/document.php', ['file' => $bad]);
            self::assertResponseStatusCodeSame(404, 'rejected: ' . $bad);
        }

        $this->signIn('usr_fx_p4'); // the applicant themself is not an administrator
        $this->client->request('GET', '/admin/document', ['file' => 'fx_permit_rico.png']);
        self::assertResponseRedirects('/app?error=unauthorized');
    }
}
