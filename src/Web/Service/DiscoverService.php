<?php
declare(strict_types=1);

namespace Picklers\Web\Service;

use Picklers\Core\Database;
use Picklers\Repositories\BookingRepository;
use Picklers\Repositories\FacilityRepository;

/**
 * Discover: the facility list, a facility's courts, slot availability and
 * favourites. Moved from the legacy ApiController unchanged in behaviour; the
 * queries and rules themselves stay in FacilityService/BookingService/Database.
 */
final class DiscoverService
{
    public function __construct(
        private readonly FacilityRepository $facilities,
        private readonly BookingRepository $bookings,
        private readonly Database $db,
    ) {
    }

    /** Facility ids are numeric in MySQL; anything else is passed through (and simply not found). */
    public static function facilityId(string $raw): int|string
    {
        return ctype_digit($raw) ? (int)$raw : $raw;
    }

    /** @return list<array<string,mixed>> */
    public function list(string $search, string $type, string $sort, ?string $userId): array
    {
        $facilities = $this->facilities->getFacilities($search, $type, $sort);
        if ($userId !== null) {
            // So a client-side re-render (PickSync 'facilities'/'courts') keeps
            // this player's real favourite hearts filled in.
            $favIds = $this->db->getFavoriteFacilityIds($userId);
            foreach ($facilities as &$facility) {
                $facility['is_favorited'] = in_array((int)($facility['id'] ?? 0), $favIds, true);
            }
            unset($facility);
        }

        return $facilities;
    }

    /** @return array{facility:array<string,mixed>,courts:array,images:array,amenities:array}|null */
    public function detail(int|string $id, ?string $userId): ?array
    {
        $facility = $this->facilities->getFacility($id);
        if (!$facility) {
            return null;
        }
        // Per-court Indoor/Outdoor/Covered/Air Conditioned tags (F-17).
        $courts = $this->facilities->getCourtsWithAttributes($id);
        if ($userId !== null) {
            $courts = $this->markJoinedOpenPlay($courts, $id, $userId);
        }

        return [
            'facility' => $facility,
            'courts' => $courts,
            'images' => $this->facilities->getCourtImagesByFacility($id),
            'amenities' => $this->facilities->getFacilityAmenities($id),
        ];
    }

    /** @return array{date:string,slots:array} */
    public function slots(int|string $facilityId, string $courtId, string $date): array
    {
        // The checkout sends display dates ("Sun, Sep 14, 2026"); availability is
        // keyed on the canonical Y-m-d booking_date.
        $ts = strtotime($date);
        $day = $ts !== false ? date('Y-m-d', $ts) : date('Y-m-d');

        return ['date' => $day, 'slots' => $this->bookings->getSlotAvailability($facilityId, $courtId, $day)];
    }

    /** @return array<string,mixed> the legacy {success, favorited, ...} result */
    public function toggleFavorite(string $userId, string $facilityId): array
    {
        return $this->db->toggleFavoriteFacility($userId, $facilityId);
    }

    /**
     * Flag Open Play courts this player has already joined (pending/confirmed
     * booking matched by match id, by facility+court+time, or any Open Play
     * booking at the facility).
     */
    private function markJoinedOpenPlay(array $courts, int|string $facilityId, string $userId): array
    {
        $joined = [];
        foreach ($this->db->getBookings($userId) as $b) {
            if (!in_array($b['status'] ?? '', ['pending', 'confirmed'], true)) {
                continue;
            }
            $bId = (string)($b['id'] ?? '');
            $bCourt = (string)($b['court_name'] ?? '');
            $bFac = (string)($b['facility_id'] ?? '');
            if (!empty($b['match_id'])) {
                $joined[(string)$b['match_id']] = true;
            }
            $isOpenPlay = str_starts_with($bId, 'PKL-OP-')
                || stripos($bCourt, 'open play') !== false
                || stripos((string)($b['label'] ?? ''), 'open play') !== false
                || !empty($b['match_id']);
            if ($isOpenPlay && $bFac !== '') {
                $joined["fac_{$bFac}"] = true;
            }
            $joined[$bFac . '|' . $bCourt . '|' . (string)($b['time'] ?? '')] = true;
        }

        foreach ($courts as &$c) {
            $cFac = (string)($c['facility_id'] ?? $facilityId);
            $isOpenPlayCourt = !empty($c['has_open_play'])
                || (!empty($c['occupied_by']) && (stripos($c['occupied_by'], 'open play') !== false || stripos($c['occupied_by'], 'hosted') !== false))
                || !empty($c['open_play_match']);
            if (!$isOpenPlayCourt) {
                continue;
            }
            $op = $c['open_play_match'] ?? [];
            $opId = (string)($op['id'] ?? '');
            $opKey = $cFac . '|' . (string)($op['type'] ?? ($c['name'] ?? '')) . '|' . (string)($op['time'] ?? '');
            if (($opId !== '' && isset($joined[$opId])) || isset($joined[$opKey]) || isset($joined["fac_{$cFac}"])) {
                $c['user_joined'] = true;
                if (!empty($c['open_play_match'])) {
                    $c['open_play_match']['is_joined'] = true;
                    $c['open_play_match']['user_joined'] = true;
                }
            }
        }
        unset($c);

        return $courts;
    }
}
