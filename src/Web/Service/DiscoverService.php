<?php
declare(strict_types=1);

namespace Picklers\Web\Service;

use Picklers\Domain\BookingRules;
use Picklers\Domain\Schedule;
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
            $favIds = $this->facilities->getFavoriteFacilityIds($userId);
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
            $facility['is_favorited'] = in_array((int)$id, $this->facilities->getFavoriteFacilityIds($userId), true);
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
        $date = trim($date);
        $ts = $date === '' ? time() : strtotime($date);
        $parsed = $date === '' ? null : date_parse($date);
        if ($ts === false || ($parsed !== null && ($parsed['warning_count'] > 0 || $parsed['error_count'] > 0))) {
            throw new \InvalidArgumentException('Please choose a valid date.');
        }
        $day = date('Y-m-d', $ts);

        return ['date' => $day, 'slots' => $this->bookings->getSlotAvailability($facilityId, $courtId, $day)];
    }

    /** @return array<string,mixed> the legacy {success, favorited, ...} result */
    public function toggleFavorite(string $userId, string $facilityId): array
    {
        return $this->facilities->toggleFavoriteFacility($userId, $facilityId);
    }

    /**
     * Flag Open Play courts this player has already joined (pending/confirmed
     * booking matched by session and occurrence date).
     */
    private function markJoinedOpenPlay(array $courts, int|string $facilityId, string $userId): array
    {
        $joined = BookingRules::joinedOccurrenceKeys($this->bookings->getBookings($userId));

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
            $date = Schedule::getMatchTargetDate($op);
            $opKey = $cFac . '|' . Schedule::normalizeCourtName((string)($op['type'] ?? ($c['name'] ?? ''))) . '|' . (string)($op['time'] ?? '') . '|' . $date;
            if (($opId !== '' && isset($joined[$opId . '|' . $date])) || isset($joined[$opKey])) {
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
