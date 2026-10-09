<?php
declare(strict_types=1);

namespace Picklers\Web\Service;

use Picklers\Core\Database;
use Picklers\Repositories\FacilityRepository;
use Picklers\Services\TournamentService;

/**
 * Which facilities (and tournaments) an account may act on. Admins see every
 * facility, which is what makes admin support possible without a separate path.
 */
final class OwnerScope
{
    public function __construct(
        private readonly FacilityRepository $facilities,
        private readonly TournamentService $tournaments,
        private readonly Database $db,
    ) {
    }

    /** Owners host tournaments and run the API's owner actions; admins may act on any venue. */
    public static function isOwnerAccount(?array $user): bool
    {
        return $user !== null && (!empty($user['is_owner']) || !empty($user['is_admin']));
    }

    /** Who may open the owner portal: owners (flag or role), admins and facility staff. */
    public function canUsePortal(array $user): bool
    {
        return (int)($user['is_owner'] ?? 0) === 1
            || (int)($user['is_admin'] ?? 0) === 1
            || (string)($user['role'] ?? '') === 'owner'
            || $this->db->getStaffFacilityIdsForUser((string)($user['id'] ?? ''), (string)($user['email'] ?? '')) !== [];
    }

    /**
     * Facilities this account owns (API rule: ownership only).
     *
     * @return list<array<string,mixed>>
     */
    public function ownedFacilities(?array $user): array
    {
        if ($user === null) {
            return [];
        }
        $all = $this->facilities->getFacilities();
        if (!empty($user['is_admin'])) {
            return $all;
        }

        return array_values(array_filter($all, static fn($f) => isset($f['owner_id']) && (string)$f['owner_id'] === (string)$user['id']));
    }

    /** The requested owned facility, or the first one; null when the account does not hold it. */
    public function ownedFacility(?array $user, string $requestedId = ''): ?array
    {
        return self::pick($this->ownedFacilities($user), $requestedId);
    }

    /**
     * Facilities the owner portal manages for this account: owned or staffed
     * (portal rule), all of them for admins.
     *
     * @return list<array<string,mixed>>
     */
    public function portalFacilities(array $user): array
    {
        $all = $this->facilities->getFacilities();
        if (!empty($user['is_admin'])) {
            return $all;
        }
        $staffFacilityIds = array_map('strval', $this->db->getStaffFacilityIdsForUser((string)($user['id'] ?? ''), (string)($user['email'] ?? '')));

        return array_values(array_filter($all, static function ($f) use ($user, $staffFacilityIds) {
            $isOwner = isset($f['owner_id']) && (string)$f['owner_id'] === (string)($user['id'] ?? '');

            return $isOwner || in_array((string)($f['id'] ?? ''), $staffFacilityIds, true);
        }));
    }

    /**
     * The one facility a portal write applies to: the requested one when this
     * account holds it, otherwise its first. Null when it holds none, or asked
     * for one it does not hold — the action then fails rather than writing rows
     * that belong to no facility.
     */
    public function portalFacility(array $user, string $requestedId = ''): ?array
    {
        return self::pick($this->portalFacilities($user), trim($requestedId));
    }

    /**
     * A tournament only if this account may act on it. Someone else's tournament
     * returns the same "not found" as a missing one, so ids are never confirmed.
     *
     * @return array{tournament:?array,error:?string,status:int}
     */
    public function ownedTournament(?array $user, string $tournamentId): array
    {
        if (!self::isOwnerAccount($user)) {
            return ['tournament' => null, 'error' => 'Unauthorized: owner access required.', 'status' => 403];
        }
        if ($tournamentId === '') {
            return ['tournament' => null, 'error' => 'Tournament id is required.', 'status' => 400];
        }
        $tournament = $this->tournaments->find($tournamentId);
        if ($tournament === null) {
            return ['tournament' => null, 'error' => 'Tournament not found.', 'status' => 404];
        }
        if (!empty($user['is_admin'])) {
            return ['tournament' => $tournament, 'error' => null, 'status' => 200];
        }
        foreach ($this->ownedFacilities($user) as $facility) {
            if ((string)$facility['id'] === (string)$tournament['facility_id']) {
                return ['tournament' => $tournament, 'error' => null, 'status' => 200];
            }
        }

        return ['tournament' => null, 'error' => 'Tournament not found.', 'status' => 404];
    }

    /** @param list<array<string,mixed>> $facilities */
    private static function pick(array $facilities, string $requestedId): ?array
    {
        if ($facilities === []) {
            return null;
        }
        if ($requestedId === '') {
            return $facilities[0];
        }
        foreach ($facilities as $f) {
            if ((string)($f['id'] ?? '') === $requestedId) {
                return $f;
            }
        }

        return null;
    }
}
