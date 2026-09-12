<?php
declare(strict_types=1);

namespace Picklers\Services;

use Picklers\Repositories\MatchRepository;

class MatchService {
    private MatchRepository $matchRepo;

    public function __construct(?MatchRepository $matchRepo = null) {
        $this->matchRepo = $matchRepo ?? new MatchRepository();
    }

    public function getMatches(string $level = 'All', string $facilitySearch = ''): array {
        return $this->matchRepo->getMatches($level, $facilitySearch);
    }

    public function getMatchById(string $matchId): ?array {
        return $this->matchRepo->getMatchById($matchId);
    }

    public function joinMatch(string $matchId, string $userId, string $paymentMethod = 'GCash', ?string $promoCode = null): array {
        return $this->matchRepo->joinMatch($matchId, $userId, $paymentMethod, $promoCode);
    }
}
