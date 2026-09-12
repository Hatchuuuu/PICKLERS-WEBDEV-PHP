<?php
declare(strict_types=1);

namespace Picklers\Models;

use Picklers\Services\MatchService;

class MatchSession {
    private MatchService $matchService;

    public function __construct(?MatchService $matchService = null) {
        $this->matchService = $matchService ?? new MatchService();
    }

    public function all(string $level = 'All', string $facilitySearch = ''): array {
        return $this->matchService->getMatches($level, $facilitySearch);
    }

    public function join(string $matchId, string $userId): array {
        return $this->matchService->joinMatch($matchId, $userId);
    }
}
