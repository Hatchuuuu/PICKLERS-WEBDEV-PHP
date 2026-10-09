<?php
declare(strict_types=1);

namespace Picklers\Repositories;

/**
 * Open Play session rows and their seat counts.
 */
final class MatchRepository
{
    private readonly \Doctrine\DBAL\Connection $db;
    private readonly \Picklers\Domain\BookingRules $bookingRules;

    public function __construct(
        ?\Doctrine\DBAL\Connection $db = null,
        ?\Picklers\Domain\BookingRules $bookingRules = null,
    ) {
        $this->db = $db ?? \Picklers\Core\Database::get()->dbal();
        $this->bookingRules = $bookingRules ?? \Picklers\Core\Database::get()->bookingRules();
    }

    public function getMatches(): array {
        $stmt = $this->db->executeQuery("SELECT * FROM matches ORDER BY date ASC");
        return $stmt->fetchAllAssociative();
    }

    public function getMatchesByFacility(int|string $facilityId): array {
        $stmt = $this->db->executeQuery("SELECT * FROM matches WHERE facility_id = ?", [$facilityId]);
        return $stmt->fetchAllAssociative();
    }

    public function insertMatch(array $matchData): array {
        $id = $matchData['id'] ?? ('op_' . bin2hex(random_bytes(4)));

        $match = [
            'id' => $id,
            'facility_id' => $matchData['facility_id'] ?? null,
            'facility_name' => $matchData['facility_name'] ?? '',
            'location' => $matchData['location'] ?? '',
            'date' => $matchData['date'] ?? '',
            'time' => $matchData['time'] ?? '',
            'level' => $matchData['level'] ?? 'Intermediate',
            'current_players' => $matchData['current_players'] ?? 0,
            'max_players' => $matchData['max_players'] ?? 4,
            'price' => $matchData['price'] ?? 150.00,
            'type' => $matchData['type'] ?? 'Doubles Open Play',
            'host' => trim((string)($matchData['host'] ?? '')) !== '' ? $matchData['host'] : 'Facility Staff',
            'title' => $matchData['title'] ?? null
        ];

        $this->db->executeStatement(
            "INSERT INTO matches (id, facility_id, facility_name, location, date, time, level, current_players, max_players, price, type, host, title) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)",
            [
                $match['id'], $match['facility_id'], $match['facility_name'], $match['location'],
                $match['date'], $match['time'], $match['level'], $match['current_players'],
                $match['max_players'], $match['price'], $match['type'], $match['host'], $match['title']
            ]
        );
        return $match;
    }

    public function getMatchById(string $matchId): ?array {
        $stmt = $this->db->executeQuery("SELECT * FROM matches WHERE id = ?", [$matchId]);
        $row = $stmt->fetchAssociative();
        return $row ?: null;
    }

    /**
     * How many seats on this match are actually spoken for — pending requests
     * included, not just owner-approved ones. This is the number joinMatch()
     * must compare against max_players; current_players alone only tracks
     * approved seats and under-counts demand while requests await review.
     */
    public function countActiveMatchBookings(string $matchId, ?string $targetDate = null): int {
        if ($targetDate !== null && $targetDate !== '') {
            return $this->bookingRules->countActiveMatchBookings($matchId, $targetDate);
        }

        $stmt = $this->db->executeQuery(
            "SELECT COUNT(*) FROM bookings WHERE match_id = ? AND status IN ('pending', 'confirmed')",
            [$matchId]
        );
        return (int)$stmt->fetchOne();
    }

    /**
     * Count only owner-accepted (confirmed/completed) players for an Open Play session.
     * Pending requests awaiting owner approval in the Requests queue do not count
     * as joined players on the court card until the owner clicks Accept.
     */
    public function countConfirmedMatchBookings(string $matchId, ?string $targetDate = null): int {
        if ($targetDate !== null && $targetDate !== '') {
            $stmt = $this->db->executeQuery(
                "SELECT COUNT(*) FROM bookings WHERE match_id = ? AND status IN ('confirmed', 'completed')
                   AND (booking_date = ? OR (booking_date IS NULL AND (date = ? OR date LIKE ? OR created_at LIKE ?)))",
                [$matchId, $targetDate, $targetDate, "%$targetDate%", "$targetDate%"]
            );
            return (int)$stmt->fetchOne();
        }

        $stmt = $this->db->executeQuery(
            "SELECT COUNT(*) FROM bookings WHERE match_id = ? AND status IN ('confirmed', 'completed')",
            [$matchId]
        );
        return (int)$stmt->fetchOne();
    }

    /**
     * Adjust a match's reserved seat count, e.g. to release a seat when a
     * payment fails after the seat was taken.
     */
    public function adjustMatchPlayerCount(string $matchId, int $delta): bool {
        $this->bookingRules->adjustMatchPlayerCount($matchId, $delta);
        return true;
    }
}
