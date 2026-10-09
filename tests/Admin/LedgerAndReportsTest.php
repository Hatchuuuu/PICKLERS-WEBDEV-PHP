<?php
declare(strict_types=1);

namespace Picklers\Tests\Admin;

use Picklers\Admin\Service\AnalyticsService;
use Picklers\Admin\Service\AuditLog;
use Picklers\Admin\Service\CsvExporter;
use Picklers\Admin\Service\MetricsService;

/** R01, R07, R10, R11, R14 — figures, ledger evidence, reports, audit, lists and exports. */
final class LedgerAndReportsTest extends AdminWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->signIn('usr_fx_admin');
    }

    public function testBookingPaymentEvidenceNeverInfersSettlement(): void
    {
        $this->client->request('GET', '/admin/panel/ledger', ['view' => 'payments', 'q' => 'FX-']);
        self::assertResponseIsSuccessful();
        $stats = $this->client->getCrawler()->filter('.pk-stat__value')->each(fn($n) => trim($n->text()));
        self::assertSame(['₱1,450.00', '₱900.00', '₱350.00', '₱850.00', '₱350.00'], $stats, 'gross, collected, refunded, unverified external, pay at venue');
        $this->client->request('GET', '/admin/panel/ledger', ['view' => 'payments', 'q' => 'FX-', 'settlement' => 'external_unverified']);
        self::assertSelectorTextContains('.pk-pager__count', 'of 4', 'GCash/Maya bookings, including the cancelled one, are unverified — never "settled"');
    }

    public function testWalletMovementsReconcileToTheBalance(): void
    {
        $this->client->request('GET', '/admin/panel/ledger', ['user' => 'usr_fx_p2']);
        $stats = $this->client->getCrawler()->filter('.pk-stat__value')->each(fn($n) => trim($n->text()));
        self::assertSame(['₱850.00', '₱769.50', '₱80.50'], $stats);
        self::assertSame('80.50', (string)$this->scalar("SELECT wallet_balance FROM users WHERE id = 'usr_fx_p2'"), 'net movements equal the stored balance');
        $this->client->request('GET', '/admin/panel/ledger', ['user' => 'usr_fx_p2', 'kind' => 'admin_adjustment']);
        self::assertSelectorTextContains('.pk-pager__count', 'of 1');
        self::assertSelectorTextContains('table', 'Admin adjustment');
    }

    public function testCsvRespectsFiltersAndNeutralisesFormulas(): void
    {
        self::pdo()->exec("INSERT INTO wallet_transactions (id, user_id, type, amount, label, date, created_at) VALUES ('fx_txevil', 'usr_fx_p2', 'credit', 1.00, '=HYPERLINK(\"http://evil.test\",\"click\")', 'Oct 1, 2026', NOW())");
        $this->client->request('GET', '/admin/export/ledger-wallet', ['user' => 'usr_fx_p2']);
        self::assertResponseIsSuccessful();
        $csv = (string)$this->client->getInternalResponse()->getContent();
        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
        self::assertStringContainsString("\"'=HYPERLINK", $csv);
        self::assertStringNotContainsString(',=HYPERLINK', $csv);
        self::assertCount(6, explode("\n", trim($csv)), 'header + 5 rows for that user only');
        self::assertSame("'+1", CsvExporter::cell('+1'));
        self::assertSame("'@SUM(A1)", CsvExporter::cell('@SUM(A1)'));
        self::assertSame('plain', CsvExporter::cell('plain'));

        $this->client->request('GET', '/admin/export/users', ['role' => 'privileged']);
        $rows = array_filter(explode("\n", trim((string)$this->client->getInternalResponse()->getContent())));
        self::assertCount(2, $rows);
        self::assertStringNotContainsString('password', strtolower($rows[0]));
        self::assertStringNotContainsString('$2y$', (string)$this->client->getInternalResponse()->getContent());
        self::assertNotNull($this->lastAudit('export.users'));
    }

    public function testKpisComeFromOneDefinition(): void
    {
        $summary = static::getContainer()->get(MetricsService::class)->summary();
        self::assertSame((int)$this->scalar("SELECT COUNT(*) FROM users WHERE role <> 'deleted'"), $summary['active_accounts']);
        self::assertSame((int)$this->scalar("SELECT COUNT(*) FROM owner_applications WHERE status = 'pending_review'"), $summary['pending_applications']);
        $this->client->request('GET', '/admin');
        $rendered = (int)str_replace(',', '', $this->client->getCrawler()->filter('[data-kpi="active_accounts"]')->text());
        $refreshed = $this->action('admin_stats', [], null, '/admin/api', 'GET')['json'];
        self::assertSame($summary['active_accounts'], $rendered, 'initial render');
        self::assertSame($summary['active_accounts'], $refreshed['active_accounts'], 'refresh');
        self::assertSame($summary['cancellation_rate'], $refreshed['cancellation_rate']);
        self::assertStringNotContainsString('Dispute', (string)$this->client->getResponse()->getContent());
    }

    public function testAnalyticsHonourManilaDayBoundaries(): void
    {
        self::pdo()->exec("DELETE FROM bookings WHERE id IN ('FX-EDGE1','FX-EDGE2')");
        $ins = self::pdo()->prepare("INSERT INTO bookings (id, user_id, facility_id, facility_name, court_name, date, time, price, payment_method, status, created_at) VALUES (?, 'usr_fx_p1', 1, 'Edge', 'Court 1', 'x', 'x', 100, 'GCash', 'confirmed', ?)");
        $ins->execute(['FX-EDGE1', '2025-02-10 23:59:59']);
        $ins->execute(['FX-EDGE2', '2025-02-11 00:00:00']);
        $report = static::getContainer()->get(AnalyticsService::class)->report('2025-02-10', '2025-02-10');
        self::assertSame(1, $report['bookings_total']);
        self::assertSame('100.00', $report['booking_value_confirmed']);
        self::assertNull(static::getContainer()->get(AnalyticsService::class)->report('2001-01-01', '2001-01-02')['cancellation_rate'], 'no data → not available, not 0%');
        $range = AnalyticsService::range('2026-02-30', null);
        self::assertSame(30, $range['days'], 'invalid date falls back to the default 30-day window');
        $this->client->request('GET', '/admin', ['tab' => 'analytics', 'from' => '2025-02-10', 'to' => '2025-02-10']);
        self::assertSelectorTextContains('.pk-stats', '₱100.00');
        self::assertSelectorTextContains('[aria-labelledby="facility-report"] table', '2.5%', 'utilisation is estimated from operating hours (1 booking × 2 h of 5 courts × 16 h)');
    }

    public function testAuditTrailIsAppendOnlyRedactedAndReadOnly(): void
    {
        $this->action('admin_toggle_promo', ['promo_id' => 'fx_promo_live']);
        $id = (int)$this->lastAudit('promo.disable')['id'];
        foreach (["UPDATE audit_events SET action = 'x' WHERE id = {$id}", "DELETE FROM audit_events WHERE id = {$id}"] as $sql) {
            try {
                self::pdo()->exec($sql);
                self::fail('audit row was modified: ' . $sql);
            } catch (\PDOException $e) {
                self::assertStringContainsString('append-only', $e->getMessage());
            }
        }
        self::assertSame(['password' => '[redacted]', 'nested' => ['csrf_token' => '[redacted]', 'ok' => 1]], AuditLog::redact(['password' => 'x', 'nested' => ['csrf_token' => 'y', 'ok' => 1]]));
        $routes = static::getContainer()->get('router')->getRouteCollection();
        foreach ($routes as $name => $route) {
            self::assertStringNotContainsString('audit', strtolower($route->getPath()), "no route edits audit ({$name})");
        }
        $this->client->request('GET', '/admin/panel/system', ['action' => 'promo', 'outcome' => 'success']);
        self::assertSelectorTextContains('table', 'promo.disable');
    }

    public function testListBoundsAndValidation(): void
    {
        $this->client->request('GET', '/admin/panel/users', ['per_page' => '7', 'sort' => 'password_hash', 'role' => 'emperor', 'page' => '-2']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.pk-notice--warning', 'Ignored invalid');
        $this->client->request('GET', '/admin/panel/users', ['per_page' => '10', 'page' => '999']);
        self::assertSelectorTextContains('.pk-pager__page', 'Page');
        $total = (int)$this->scalar('SELECT COUNT(*) FROM users');
        self::assertSelectorTextContains('.pk-pager__count', 'of ' . number_format($total), 'clamped to the last page with an exact count');
        $this->client->request('GET', '/admin/panel/bookings', ['q' => 'definitely-no-such-booking']);
        self::assertSelectorTextContains('.pk-empty', 'No results match these filters');
        $this->client->request('GET', '/admin/panel/bookings', ['q' => str_repeat('a', 500)]);
        self::assertResponseIsSuccessful();
    }
}
