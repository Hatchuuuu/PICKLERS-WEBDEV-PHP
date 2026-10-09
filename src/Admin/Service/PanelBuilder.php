<?php
declare(strict_types=1);

namespace Picklers\Admin\Service;

use Picklers\Admin\Repository\AccountRepository;
use Picklers\Admin\Repository\AuditRepository;
use Picklers\Admin\Security\AdminUser;

/**
 * Builds the data for ONE admin panel from validated query parameters. Only the
 * active panel is queried (the old page loaded every user, booking, facility and
 * application on every visit).
 */
final class PanelBuilder
{
    public const TABS = ['overview', 'applications', 'facilities', 'bookings', 'users', 'moderation', 'ledger', 'promos', 'analytics', 'system'];

    /** Panels with a server-side text search, and what it searches (shown in the top search). */
    public const SEARCH_SCOPES = [
        'applications' => 'Search applications by facility, applicant, email, entity or ID',
        'facilities' => 'Search facilities by name, location or owner',
        'bookings' => 'Search bookings by ID, player, facility or court',
        'users' => 'Search users by name, email, phone or ID',
        'moderation' => 'Search cases by ID, reason or content',
        'ledger' => 'Search ledger by reference, label or person',
        'promos' => 'Search promo codes',
        'system' => 'Search audit by action, target, reason or reference',
    ];

    public function __construct(
        private readonly MetricsService $metrics,
        private readonly ApplicationReviewService $applications,
        private readonly FacilityAdminService $facilities,
        private readonly BookingAdminService $bookings,
        private readonly AccountRepository $accounts,
        private readonly ModerationService $moderation,
        private readonly LedgerService $ledger,
        private readonly PromoAdminService $promos,
        private readonly AnalyticsService $analytics,
        private readonly SystemHealthService $system,
        private readonly AuditRepository $audit,
        private readonly NotificationAdminService $notifications,
    ) {
    }

    public static function normalizeTab(?string $tab): string
    {
        return in_array($tab, self::TABS, true) ? (string)$tab : 'overview';
    }

    /** Validated list state for a tab (also used by CSV exports so files match the screen). */
    public function query(string $tab, array $q, ?string $view = null): ListQuery
    {
        return match ($tab) {
            'applications' => ListQuery::from($q, ['status' => ApplicationReviewService::STATUSES], ApplicationReviewService::SORTS, 'created_at'),
            'facilities' => ListQuery::from($q, ['status' => FacilityAdminService::STATUSES, 'maintenance' => ['1']], FacilityAdminService::SORTS, 'name', 'asc'),
            'bookings' => ListQuery::from($q, [
                'status' => BookingAdminService::STATUSES, 'payment' => array_keys(BookingAdminService::PAYMENTS),
                'kind' => BookingAdminService::KINDS, 'facility' => 'id', 'user' => 'id', 'from' => 'date', 'to' => 'date',
            ], BookingAdminService::SORTS, 'created_at'),
            'users' => ListQuery::from($q, ['role' => AccountRepository::ROLE_FILTERS, 'verification' => AccountRepository::VERIFICATION_FILTERS], AccountRepository::SORTS, 'created_at'),
            'moderation' => $view === 'content'
                ? ListQuery::from($q, ['ctype' => ['post', 'comment']], ['created_at'], 'created_at')
                : ListQuery::from($q, ['status' => ModerationService::STATUSES, 'type' => ModerationService::CONTENT_TYPES, 'category' => ModerationService::CATEGORIES], ModerationService::SORTS, 'opened_at'),
            'ledger' => $view === 'payments'
                ? ListQuery::from($q, ['settlement' => LedgerService::SETTLEMENTS, 'status' => BookingAdminService::STATUSES, 'facility' => 'id', 'user' => 'id', 'from' => 'date', 'to' => 'date'], LedgerService::BOOKING_SORTS, 'created_at')
                : ListQuery::from($q, ['type' => LedgerService::TYPES, 'kind' => LedgerService::KINDS, 'facility' => 'id', 'user' => 'id', 'from' => 'date', 'to' => 'date'], LedgerService::WALLET_SORTS, 'created_at'),
            'promos' => ListQuery::from($q, ['state' => PromoAdminService::STATES], PromoAdminService::SORTS, 'created_at'),
            'system' => ListQuery::from($q, ['outcome' => AuditRepository::OUTCOMES, 'target_type' => AuditRepository::TARGET_TYPES, 'actor' => 'id', 'action' => 'text', 'from' => 'date', 'to' => 'date'], AuditRepository::SORTS, 'occurred_at'),
            default => ListQuery::from([], [], ['created_at'], 'created_at'),
        };
    }

    /** @return array<string,mixed> */
    public function build(string $tab, array $q, AdminUser $admin): array
    {
        $tab = self::normalizeTab($tab);
        $view = isset($q['view']) && is_string($q['view']) ? $q['view'] : null;

        return ['tab' => $tab] + match ($tab) {
            'overview' => [
                'kpis' => $this->metrics->summary(),
                'definitions' => MetricsService::DEFINITIONS,
                'pending' => $this->applications->search(ListQuery::from(['status' => 'pending_review', 'per_page' => 10], ['status' => ApplicationReviewService::STATUSES], ApplicationReviewService::SORTS, 'created_at')),
                'activity' => $this->metrics->recentActivity(8),
            ],
            'applications' => ['page' => $this->applications->search($this->query($tab, $q))],
            'facilities' => ['page' => $this->facilities->search($this->query($tab, $q))],
            'bookings' => [
                'page' => $this->bookings->search($this->query($tab, $q)),
                'facility_options' => $this->ledger->facilityOptions(),
                'payments' => BookingAdminService::PAYMENTS,
            ],
            'users' => ['page' => $this->accounts->search($this->query($tab, $q)), 'counts' => $this->accounts->roleCounts()],
            'moderation' => $view === 'content'
                ? ['view' => 'content', 'page' => $this->moderation->searchContent($this->query($tab, $q, 'content')), 'open_count' => $this->moderation->openCount()]
                : ['view' => 'cases', 'page' => $this->moderation->searchCases($this->query($tab, $q, 'cases')), 'open_count' => $this->moderation->openCount()],
            'ledger' => $view === 'payments'
                ? ['view' => 'payments', 'page' => $this->ledger->bookingPayments($this->query($tab, $q, 'payments')), 'facility_options' => $this->ledger->facilityOptions()]
                : ['view' => 'wallet', 'page' => $this->ledger->walletEntries($this->query($tab, $q, 'wallet')), 'facility_options' => $this->ledger->facilityOptions()],
            'promos' => ['page' => $this->promos->search($this->query($tab, $q)), 'stats' => $this->promos->stats()],
            'analytics' => (function () use ($q): array {
                $range = AnalyticsService::range(is_string($q['from'] ?? null) ? $q['from'] : null, is_string($q['to'] ?? null) ? $q['to'] : null);

                return ['range' => $range, 'report' => $this->analytics->report($range['from'], $range['to']), 'definitions' => AnalyticsService::DEFINITIONS];
            })(),
            'system' => [
                'facts' => $this->system->facts(),
                'page' => $this->audit->search($this->query($tab, $q)),
                'action_groups' => $this->audit->actionGroups(),
                'broadcasts' => $this->notifications->recent(8),
                'audiences' => NotificationAdminService::AUDIENCES,
            ],
        };
    }

    /** Sidebar badges: real counts, cheap queries. @return array<string,int> */
    public function badges(): array
    {
        return [
            'applications' => $this->applications->pendingCount(),
            'moderation' => $this->moderation->openCount(),
        ];
    }
}
