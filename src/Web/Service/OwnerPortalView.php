<?php
declare(strict_types=1);

namespace Picklers\Web\Service;

use Picklers\Core\Database;
use Picklers\Services\TournamentService;

/**
 * Everything the owner portal page shows for one facility: dashboard KPIs, the
 * live courts grid, the requests queue, courts, Open Play sessions, tournaments,
 * earnings, conversations and staff — from real records only.
 */
final class OwnerPortalView
{
    public const TABS = ['dashboard', 'courts', 'tournaments', 'messages', 'staff', 'earnings', 'settings'];

    public function __construct(
        private readonly Database $db,
        private readonly TournamentService $tournaments,
    ) {
    }

    /**
     * @param list<array<string,mixed>> $facilities the facilities this account manages (non-empty)
     * @return array<string,mixed> template context
     */
    public function build(array $user, array $facilities, string $facilityId, string $tab): array
    {
        $facility = $facilities[0];
        foreach ($facilities as $f) {
            if ($facilityId !== '' && (string)$f['id'] === $facilityId) {
                $facility = $f;
                break;
            }
        }
        $fid = $facility['id'] ?? 0;
        $fin = $this->db->getOwnerFinancials($fid);
        $matches = $this->db->getMatchesByFacility((int)($facility['id'] ?? 1));
        $courts = $this->courts($this->db->getCourtsWithAttributes($facility['id'] ?? 1), $matches);
        $notifications = $this->db->getNotifications((string)$user['id']);

        return [
            'activeTab' => in_array($tab, self::TABS, true) ? $tab : 'dashboard',
            'currentFacility' => $facility,
            'facilities' => $facilities,
            'metrics' => $this->metrics($fin),
            'financials' => $fin,
            'liveCourts' => array_map($this->liveCourt(...), $courts),
            'courts' => $courts,
            // Shared with api?action=get_pending_requests, so the first render and every live refresh agree.
            'pendingRequests' => $this->db->getPendingBookingRequests($facility['id'] ?? ''),
            'openPlayMatches' => $this->openPlay($matches),
            'tournaments' => $this->tournaments->grouped((string)($facility['id'] ?? '')),
            'tournamentCategories' => TournamentService::CATEGORIES,
            'earnings' => array_intersect_key($fin, array_flip(['gross', 'fee_pct', 'fee_amount', 'net', 'available', 'ledger'])),
            'conversations' => $this->db->getConversationPartners((string)($user['id'] ?? '')),
            'staffMembers' => array_map(static fn($s) => [
                'id' => $s['id'] ?? '',
                'name' => $s['name'] ?? 'Staff Member',
                'email' => $s['email'] ?? 'staff@facility.com',
                'date' => $s['date_joined'] ?? date('M Y'),
                'role' => $s['role'] ?? 'Front Desk',
                'status' => $s['status'] ?? 'Active',
                'avatar_url' => $s['avatar_url'] ?? ($s['avatar'] ?? ''),
            ], $this->db->getStaffByFacility((int)($facility['id'] ?? 1))),
            'amenities' => [
                'showers' => ['label' => 'Showers & Changing Rooms', 'enabled' => true, 'icon' => 'droplets'],
                'ac' => ['label' => 'Air Conditioning / High-Velocity Fans', 'enabled' => true, 'icon' => 'wind'],
                'pro_shop' => ['label' => 'Pro Shop & Paddle Rentals', 'enabled' => true, 'icon' => 'shopping-bag'],
                'cafe' => ['label' => 'Cafe & Lounge Area', 'enabled' => true, 'icon' => 'coffee'],
                'parking' => ['label' => 'Free Parking / Valet', 'enabled' => true, 'icon' => 'car'],
            ],
            'userNotifications' => $notifications,
            'unreadNotifsCount' => count(array_filter($notifications, static fn($n) => empty($n['is_read']))),
            'courtAttributeCatalog' => $this->db->getCourtAttributeCatalog(),
            'currentUser' => $user,
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private function metrics(array $fin): array
    {
        $spark = $fin['spark'];

        return [
            'monthly_revenue' => ['label' => 'MONTHLY REVENUE', 'value' => '₱' . number_format($fin['monthly_gross'], 0), 'delta' => $fin['monthly_gross'] > 0 ? '↗ This Month' : '— No data yet', 'color' => 'emerald', 'icon' => 'peso', 'spark' => $spark],
            'repeaters_rate' => ['label' => 'REPEATERS', 'value' => $fin['repeater_rate'] . '%', 'delta' => $fin['repeater_rate'] >= 40 ? '↗ Strong' : ($fin['repeater_rate'] > 0 ? '↘ Growing' : '— No data yet'), 'color' => 'crimson', 'icon' => 'user', 'spark' => array_reverse($spark)],
            'today_revenue' => ['label' => "TODAY'S REVENUE", 'value' => '₱' . number_format($fin['today_gross'], 0), 'delta' => $fin['today_gross'] > 0 ? '↗ Today' : '— No data yet', 'color' => 'cyan', 'icon' => 'trend', 'spark' => $spark],
            'active_bookings' => ['label' => 'ACTIVE BOOKINGS', 'value' => (string)$fin['active_bookings'], 'delta' => $fin['active_bookings'] > 0 ? '↗ Live' : '— None yet', 'color' => 'amber', 'icon' => 'clock', 'spark' => $spark],
            'new_players' => ['label' => 'NEW PLAYERS', 'value' => (string)$fin['new_players_today'], 'delta' => $fin['new_players_today'] > 0 ? '↗ Today' : '— None yet', 'color' => 'purple', 'icon' => 'users', 'spark' => $spark],
        ];
    }

    /**
     * Courts as the portal shows them, each with its unexpired Open Play session
     * (matched by court id, then exact court name — "Court 1" never claims Court
     * 10's session — or the only court), sorted naturally by name.
     *
     * @return list<array<string,mixed>>
     */
    private function courts(array $rows, array $matches): array
    {
        $total = count($rows);
        $courts = array_map(function ($c) use ($matches, $total) {
            $status = strtoupper(trim((string)($c['status'] ?? 'AVAILABLE')));
            if (!in_array($status, ['AVAILABLE', 'UNAVAILABLE', 'OCCUPIED'], true)) {
                $status = 'UNAVAILABLE';
            }
            $name = trim((string)($c['name'] ?? ''));
            $id = trim((string)($c['id'] ?? ''));
            $openPlay = null;
            foreach ($matches as $m) {
                if (Database::isMatchExpired($m)) {
                    continue;
                }
                $mCourtId = trim((string)($m['court_id'] ?? ''));
                $mCourtName = trim((string)($m['court_name'] ?? ''));
                if (($id !== '' && $mCourtId !== '' && $mCourtId === $id)
                    || ($name !== '' && $mCourtName !== '' && strcasecmp($mCourtName, $name) === 0)
                    || ($name !== '' && strcasecmp(trim((string)($m['type'] ?? '')), $name) === 0)
                    || $total === 1) {
                    $openPlay = $m;
                    break;
                }
            }

            return [
                'id' => $c['id'] ?? '',
                'name' => Database::normalizeCourtName((string)($c['name'] ?? 'Court')),
                'type' => $c['type'] ?? 'Indoor',
                'surface' => $c['surface'] ?? 'Hard Court',
                'rate' => $c['price'] ?? 0,
                'active' => $status !== 'UNAVAILABLE',
                'status' => $status,
                'player' => $c['occupied_by'] ?? null,
                'player_avatar' => $c['player_avatar'] ?? ($c['user_avatar'] ?? ($c['avatar_url'] ?? ($c['avatar'] ?? null))),
                'player_time' => $c['occupied_until'] ?? null,
                'attributes' => $c['attributes'] ?? [],
                'attribute_slugs' => $c['attribute_slugs'] ?? [],
                'has_open_play' => $openPlay !== null,
                'open_play_match' => $openPlay,
                'next_booking' => $c['next_booking'] ?? null,
                'upcoming_bookings' => $c['upcoming_bookings'] ?? [],
                'completed_bookings' => $c['completed_bookings'] ?? [],
                'start_min' => $c['start_min'] ?? null,
                'end_min' => $c['end_min'] ?? null,
            ];
        }, $rows);
        usort($courts, static fn($a, $b) => strnatcasecmp((string)$a['name'], (string)$b['name']));

        return $courts;
    }

    /** One tile of the dashboard's live courts grid. */
    private function liveCourt(array $rc): array
    {
        $status = strtoupper(trim((string)($rc['status'] ?? 'AVAILABLE')));
        $player = (string)($rc['player'] ?? '');
        $hasOpenPlay = !empty($rc['has_open_play']) || ($player !== '' && stripos($player, 'Open Play') !== false);
        $base = [
            'id' => $rc['id'],
            'name' => $rc['name'],
            'upcoming_bookings' => $rc['upcoming_bookings'] ?? [],
            'completed_bookings' => $rc['completed_bookings'] ?? [],
        ];

        if ($hasOpenPlay) {
            $match = $rc['open_play_match'] ?? [];
            $everyday = strtolower(trim((string)($match['date'] ?? ''))) === 'everyday';
            $time = $match['time'] ?? '6:00 AM – 11:00 PM';

            return $base + [
                'dot' => 'amber', 'player_name' => null, 'status' => 'open_play', 'timer' => null, 'has_open_play' => true,
                'open_play_title' => $match['title'] ?? 'Community Open Play',
                'joined_players' => $this->openPlaySpotsTaken($match),
                'max_players' => (int)($match['max_players'] ?? $match['capacity'] ?? 12),
                'time_range' => $everyday ? 'Everyday • ' . $time : $time,
                'is_everyday' => $everyday,
            ];
        }
        if ($status === 'OCCUPIED' && $player !== '' && strcasecmp($player, 'Hosted Open Play') !== 0 && stripos($player, 'Open Play') === false) {
            $startMin = isset($rc['start_min']) ? (int)$rc['start_min'] : 660;
            $endMin = isset($rc['end_min']) ? (int)$rc['end_min'] : $startMin + 60;
            if ($endMin <= $startMin) {
                $endMin = $startMin + 60;
            }
            $now = (int)date('H') * 3600 + (int)date('i') * 60 + (int)date('s');
            $total = max(60, ($endMin - $startMin) * 60);
            $left = max(0, $endMin * 60 - $now);
            $pct = (int)min(100, max(0, round((($total - $left) / $total) * 100)));

            return $base + [
                'dot' => 'cyan', 'player_name' => $rc['player'], 'status' => 'occupied',
                'timer' => sprintf('%02d:%02d', floor($left / 60), $left % 60),
                'seconds_left' => $left, 'total_seconds' => $total, 'progress_percent' => $pct,
                'progress_color' => $pct >= 85 ? 'red' : ($pct >= 65 ? 'amber' : 'green'),
                'has_open_play' => false,
            ];
        }
        if ($status === 'UNAVAILABLE') {
            return $base + ['dot' => 'gray', 'player_name' => null, 'status' => 'maintenance', 'timer' => null, 'has_open_play' => false];
        }

        return $base + [
            'dot' => !empty($rc['next_booking']) ? 'cyan' : 'green', 'player_name' => null, 'status' => 'waiting',
            'timer' => null, 'has_open_play' => false, 'next_booking' => $rc['next_booking'] ?? null,
        ];
    }

    /** @return array{active:list<array<string,mixed>>,completed:list<array<string,mixed>>} */
    private function openPlay(array $matches): array
    {
        $out = ['active' => [], 'completed' => []];
        $seen = [];
        foreach ($matches as $m) {
            $id = (string)($m['id'] ?? '');
            if ($id !== '' && isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $level = (string)($m['level'] ?? 'All Levels');
            $expired = Database::isMatchExpired($m);
            $out[$expired ? 'completed' : 'active'][] = [
                'id' => $m['id'] ?? '',
                'court_name' => $m['type'] ?? 'Court',
                'tag' => strtoupper($level) . ' OPEN PLAY',
                'title' => $m['title'] ?? ($m['type'] ?? 'Open Play Session'),
                'date' => $m['date'] ?? 'TBD',
                'time' => $m['time'] ?? 'TBD',
                'level' => $level,
                'price' => $m['price'] ?? 0,
                'joined' => $this->openPlaySpotsTaken($m),
                'capacity' => (int)($m['max_players'] ?? 4),
                'is_completed' => $expired,
            ];
        }

        return $out;
    }

    /**
     * Seats taken on a session's current occurrence — the figure players see.
     * current_players is a lifetime counter an Everyday session never resets.
     */
    private function openPlaySpotsTaken(array $match): int
    {
        $id = (string)($match['id'] ?? '');
        if ($id === '') {
            return 0;
        }
        $active = $this->db->countConfirmedMatchBookings($id, Database::getMatchTargetDate($match));
        $date = strtolower((string)($match['date'] ?? ''));
        if (str_contains($date, 'everyday') || str_contains($date, 'daily')) {
            return $active;
        }

        return max((int)($match['current_players'] ?? 0), $active);
    }
}
