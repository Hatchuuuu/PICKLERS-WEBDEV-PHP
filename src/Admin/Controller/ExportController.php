<?php
declare(strict_types=1);

namespace Picklers\Admin\Controller;

use Picklers\Admin\Repository\AccountRepository;
use Picklers\Admin\Repository\AuditRepository;
use Picklers\Admin\Security\Capability;
use Picklers\Admin\Security\RoleMapper;
use Picklers\Admin\Service\AnalyticsService;
use Picklers\Admin\Service\ApplicationReviewService;
use Picklers\Admin\Service\BookingAdminService;
use Picklers\Admin\Service\CsvExporter;
use Picklers\Admin\Service\FacilityAdminService;
use Picklers\Admin\Service\LedgerService;
use Picklers\Admin\Service\ListQuery;
use Picklers\Admin\Service\PanelBuilder;
use Picklers\Admin\Service\PromoAdminService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * CSV exports of exactly what the corresponding panel shows for the same filters
 * (built from the same ListQuery), capped at CsvExporter::MAX_ROWS. Credentials,
 * private document file names and secrets are never exported.
 */
final class ExportController extends AbstractAdminController
{
    /** dataset => [panel tab, view, extra capability] */
    private const DATASETS = [
        'users' => ['users', null, Capability::VIEW_CONSOLE],
        'applications' => ['applications', null, Capability::REVIEW_APPLICATIONS],
        'facilities' => ['facilities', null, Capability::MANAGE_FACILITIES],
        'bookings' => ['bookings', null, Capability::MANAGE_BOOKINGS],
        'ledger-wallet' => ['ledger', 'wallet', Capability::VIEW_LEDGER],
        'ledger-payments' => ['ledger', 'payments', Capability::VIEW_LEDGER],
        'promos' => ['promos', null, Capability::MANAGE_PROMOS],
        'audit' => ['system', null, Capability::VIEW_AUDIT],
        'analytics' => ['analytics', null, Capability::VIEW_REPORTS],
    ];

    public function __construct(
        private readonly PanelBuilder $panels,
        private readonly CsvExporter $csv,
        private readonly AccountRepository $accounts,
        private readonly ApplicationReviewService $applications,
        private readonly FacilityAdminService $facilities,
        private readonly BookingAdminService $bookings,
        private readonly LedgerService $ledger,
        private readonly PromoAdminService $promos,
        private readonly AuditRepository $audit,
        private readonly AnalyticsService $analytics,
    ) {
    }

    #[Route('/admin/export/{dataset}', name: 'admin_export', methods: ['GET'], requirements: ['dataset' => 'users|applications|facilities|bookings|ledger-wallet|ledger-payments|promos|audit|analytics'])]
    public function export(Request $request, string $dataset): Response
    {
        [$tab, $view, $capability] = self::DATASETS[$dataset];
        $this->requireCapability(Capability::EXPORT_DATA, 'export.' . $dataset, 'export', $dataset);
        $this->requireCapability($capability, 'export.' . $dataset, 'export', $dataset);
        $params = $request->query->all();

        if ($dataset === 'analytics') {
            $range = AnalyticsService::range($params['from'] ?? null, $params['to'] ?? null);
            $report = $this->analytics->report($range['from'], $range['to']);
            $rows = array_map(static fn(array $f) => [$f['id'], $f['name'], $f['location'], $f['operating_status'], $f['courts'], $f['bookings'], $f['confirmed_value'], $f['cancelled'], 'Not available'], $report['facilities']);
            $this->auditLog->record('export.analytics', 'export', 'analytics', ['from' => $range['from'], 'to' => $range['to'], 'rows' => count($rows)]);

            return $this->csv->response("picklers-facility-report-{$range['from']}-to-{$range['to']}",
                ['Facility ID', 'Facility', 'Location', 'Status', 'Courts', 'Bookings created in range', 'Confirmed/completed value (PHP)', 'Cancelled/declined', 'Utilisation'], $rows);
        }

        $query = $this->panels->query($tab, $params, $view)->unpaged(CsvExporter::MAX_ROWS + 1);
        [$headers, $rows] = $this->rows($dataset, $query);
        $truncated = count($rows) > CsvExporter::MAX_ROWS;
        $rows = array_slice($rows, 0, CsvExporter::MAX_ROWS);
        $this->auditLog->record('export.' . $dataset, 'export', $dataset, ['filters' => $query->params(['page' => null, 'per_page' => null]), 'rows' => count($rows), 'truncated' => $truncated]);

        return $this->csv->response('picklers-' . $dataset, $headers, $rows, $truncated);
    }

    /** @return array{0:list<string>,1:list<list<mixed>>} */
    private function rows(string $dataset, ListQuery $q): array
    {
        return match ($dataset) {
            'users' => [
                ['User ID', 'Name', 'Email', 'Phone', 'Roles', 'Verification', 'Wallet balance (PHP)', 'Created'],
                array_map(static fn(array $u) => [$u['id'], $u['name'], $u['email'], $u['phone'], implode(' + ', RoleMapper::labels($u, $u['is_privileged'])), $u['verification_status'], $u['wallet_balance'], $u['created_at']], $this->accounts->search($q)->items),
            ],
            'applications' => [
                ['Application ID', 'Facility', 'Address', 'Applicant', 'Business email', 'Entity', 'Registration no.', 'Courts', 'Permit', 'Government ID', 'Status', 'Submitted', 'Reviewed by', 'Reviewed at', 'Review reason'],
                array_map(static fn(array $a) => [$a['id'], $a['facility_name'], $a['address'], $a['owner_name'], $a['business_email'], $a['entity_name'], $a['reg_number'], $a['courts_count'], $a['permit_state'], $a['gov_id_state'], $a['status'], $a['created_at'], $a['reviewer_name'], $a['reviewed_at'], $a['review_reason']], $this->applications->search($q)->items),
            ],
            'facilities' => [
                ['Facility ID', 'Name', 'Location', 'Owner', 'Owner email', 'Status', 'Status reason', 'Courts', 'Courts under maintenance', 'Min rate (PHP)', 'Max rate (PHP)', 'Upcoming bookings'],
                array_map(static fn(array $f) => [$f['id'], $f['name'], $f['location'], $f['owner_name'], $f['owner_email'], $f['operating_status'], $f['status_reason'], $f['court_count'], $f['courts_down'], $f['min_rate'], $f['max_rate'], $f['upcoming_bookings']], $this->facilities->search($q)->items),
            ],
            'bookings' => [
                ['Booking ID', 'Player', 'Player email', 'Facility', 'Court', 'Play date', 'Time', 'Price (PHP)', 'Payment method', 'Status', 'Open Play', 'Created'],
                array_map(static fn(array $b) => [$b['id'], $b['player_name'], $b['player_email'], $b['facility_name'], $b['court_name'], $b['booking_date'] ?? $b['date'], $b['time'], $b['price'], $b['payment_method'], $b['status'], $b['is_open_play'], $b['created_at']], $this->bookings->search($q)->items),
            ],
            'ledger-wallet' => [
                ['Transaction ID', 'Date', 'User', 'User email', 'Type', 'Kind', 'Kind source', 'Amount (PHP)', 'Balance after (PHP)', 'Label', 'Booking', 'Facility', 'Actor', 'Reason'],
                array_map(static fn(array $t) => [$t['id'], $t['created_at'], $t['user_name'], $t['user_email'], $t['type'], $t['kind'], $t['kind_derived'] ? 'derived from label' : 'recorded', $t['amount'], $t['balance_after'], $t['label'], $t['booking_ref'], $t['facility_name'], $t['actor_name'], $t['reason']], $this->ledger->walletEntries($q)->items),
            ],
            'ledger-payments' => [
                ['Booking ID', 'Created', 'Player', 'Facility', 'Court', 'Price (PHP)', 'Payment method', 'Booking status', 'Payment evidence'],
                array_map(static fn(array $b) => [$b['id'], $b['created_at'], $b['player_name'], $b['facility_name'], $b['court_name'], $b['price'], $b['payment_method'], $b['status'], $b['settlement']], $this->ledger->bookingPayments($q)->items),
            ],
            'promos' => [
                ['Promo ID', 'Code', 'Type', 'Value', 'Min spend (PHP)', 'Usage limit', 'Times used', 'Per-user limit', 'Expires', 'State', 'Redemptions', 'Discount granted (PHP)', 'Created'],
                array_map(static fn(array $p) => [$p['id'], $p['code'], $p['discount_type'], $p['discount_value'], $p['min_spend'], $p['usage_limit'], $p['times_used'], $p['user_limit'], $p['expires_at'], $p['state'], $p['redemptions'], $p['discount_total'], $p['created_at']], $this->promos->search($q)->items),
            ],
            'audit' => [
                ['Event ID', 'Time', 'Actor', 'Actor ID', 'Effective user', 'Action', 'Target type', 'Target ID', 'Outcome', 'Reason', 'Changes', 'Reference'],
                array_map(static fn(array $e) => [$e['id'], $e['occurred_at'], $e['actor_name'], $e['actor_user_id'], $e['effective_user_id'], $e['action'], $e['target_type'], $e['target_id'], $e['outcome'], $e['reason'], json_encode($e['changes'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $e['correlation_id']], $this->audit->search($q)->items),
            ],
        };
    }
}
