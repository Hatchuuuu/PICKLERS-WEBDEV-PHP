<?php
declare(strict_types=1);

namespace Picklers\Repositories;

use Picklers\Core\Database;

class MatchRepository {
    private Database $db;

    public function __construct(?Database $db = null) {
        $this->db = $db ?? Database::get();
    }

    public function getMatches(string $level = 'All', string $facilitySearch = ''): array {
        return $this->db->getMatches($level, $facilitySearch);
    }

    public function getMatchById(string $matchId): ?array {
        foreach ($this->db->getMatches('All', '') as $match) {
            if ((string)($match['id'] ?? '') === $matchId) {
                return $match;
            }
        }
        return null;
    }

    public function joinMatch(string $matchId, string $userId, string $paymentMethod = 'GCash', ?string $promoCode = null): array {
        return $this->db->joinMatch($matchId, $userId, $paymentMethod, $promoCode);
    }
}
