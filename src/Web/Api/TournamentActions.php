<?php
declare(strict_types=1);

namespace Picklers\Web\Api;

use Picklers\Services\TournamentService;
use Picklers\Web\Http\LegacyInput;
use Picklers\Web\Http\LegacyResponses;
use Picklers\Web\Service\OwnerScope;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Tournament API. Every action is owner-scoped twice: the account must be an
 * owner (or admin), and the tournament must belong to a facility it holds.
 */
final class TournamentActions
{
    use LegacyResponses;

    public function __construct(
        private readonly TournamentService $tournaments,
        private readonly OwnerScope $scope,
    ) {
    }

    public function list(LegacyInput $in, ?array $user): JsonResponse
    {
        if (!OwnerScope::isOwnerAccount($user)) {
            return $this->jsonError('Unauthorized: owner access required.', 403);
        }
        $facility = $this->scope->ownedFacility($user, (string)$in->input('facility_id', ''));

        return $this->jsonSuccess(['tournaments' => $this->tournaments->grouped($facility ? (string)$facility['id'] : null)]);
    }

    public function get(LegacyInput $in, ?array $user): JsonResponse
    {
        return $this->withOwned($in, $user, fn(array $t) => $this->jsonSuccess(['tournament' => $t]));
    }

    public function create(LegacyInput $in, ?array $user): JsonResponse
    {
        if (!OwnerScope::isOwnerAccount($user)) {
            return $this->jsonError('Unauthorized: owner access required.', 403);
        }
        $facility = $this->scope->ownedFacility($user, (string)$in->input('facility_id', ''));
        if ($facility === null) {
            return $this->jsonError('Complete your owner application before hosting a tournament.', 403);
        }
        $created = $this->tournaments->create([
            'facility_id' => (string)$facility['id'],
            'owner_id' => (string)($user['id'] ?? ''),
            'title' => (string)$in->input('title', ''),
            'category' => (string)$in->input('category', $in->input('type', '')),
            'format' => (string)$in->input('format', 'single_elimination'),
            'pairing_mode' => (string)$in->input('pairing_mode', 'fixed'),
            'max_teams' => $in->input('max_teams', 16),
            'date' => (string)$in->input('date', ''),
            'end_date' => (string)$in->input('end_date', ''),
            'time' => (string)$in->input('time', ''),
            'venue' => (string)$in->input('venue', (string)($facility['name'] ?? '')),
            'prize_pool' => (string)$in->input('prize_pool', ''),
            'entry_fee' => (string)$in->input('entry_fee', ''),
            'description' => (string)$in->input('description', ''),
            'teams' => (array)$in->input('teams', []),
            'players' => (array)$in->input('players', []),
        ]);
        if ($created['error'] !== null) {
            return $this->jsonError($created['error'], 422);
        }

        return $this->jsonSuccess(['tournament' => $created['tournament']], 'Tournament published successfully.');
    }

    /** Only keys the client actually sent, so a partial edit never blanks a field. */
    public function update(LegacyInput $in, ?array $user): JsonResponse
    {
        return $this->withOwned($in, $user, function (array $t) use ($in) {
            $patch = self::sent($in, ['title', 'category', 'format', 'pairing_mode', 'max_teams', 'date', 'end_date', 'time', 'venue', 'prize_pool', 'entry_fee', 'description', 'status']);

            return $this->result($this->tournaments->update((string)$t['id'], $patch), 'Tournament updated.');
        });
    }

    public function delete(LegacyInput $in, ?array $user): JsonResponse
    {
        return $this->withOwned($in, $user, fn(array $t) => $this->tournaments->delete((string)$t['id'])
            ? $this->jsonSuccess([], 'Tournament deleted.')
            : $this->jsonError('Tournament could not be deleted.', 500));
    }

    /** add_tournament_player, add_tournament_team and add_tournament_entrant. */
    public function addEntrant(LegacyInput $in, ?array $user): JsonResponse
    {
        return $this->withOwned($in, $user, fn(array $t) => $this->result($this->tournaments->addEntrant((string)$t['id'], [
            'name' => (string)$in->input('name', ''),
            'team_name' => (string)$in->input('team_name', ''),
            'player1' => (string)$in->input('player1', $in->input('player1_name', '')),
            'player2' => (string)$in->input('player2', $in->input('player2_name', '')),
            'player1_id' => (string)$in->input('player1_id', ''),
            'player2_id' => (string)$in->input('player2_id', ''),
            'email' => (string)$in->input('email', ''),
            'user_id' => (string)$in->input('user_id', ''),
        ]), 'Entrant registered.'));
    }

    public function updateTeam(LegacyInput $in, ?array $user): JsonResponse
    {
        return $this->withOwned($in, $user, fn(array $t) => $this->result(
            $this->tournaments->updateTeam((string)$t['id'], (string)$in->input('team_id', ''), self::sent($in, ['name', 'player1', 'player2', 'seed'])),
            'Team updated.'
        ));
    }

    public function removeTeam(LegacyInput $in, ?array $user): JsonResponse
    {
        return $this->withOwned($in, $user, fn(array $t) => $this->result($this->tournaments->removeTeam((string)$t['id'], (string)$in->input('team_id', '')), 'Team removed.'));
    }

    public function removePlayer(LegacyInput $in, ?array $user): JsonResponse
    {
        return $this->withOwned($in, $user, fn(array $t) => $this->result($this->tournaments->removePoolPlayer((string)$t['id'], (string)$in->input('player_id', '')), 'Player removed from the pool.'));
    }

    public function mixTeams(LegacyInput $in, ?array $user): JsonResponse
    {
        return $this->withOwned($in, $user, function (array $t) {
            $drawn = $this->tournaments->mixDraw((string)$t['id']);
            if ($drawn['error'] !== null) {
                return $this->jsonError($drawn['error'], 422);
            }

            return $this->jsonSuccess(['tournament' => $drawn['tournament']], 'Partners drawn — ' . count($drawn['tournament']['teams']) . ' teams formed.');
        });
    }

    public function generateBracket(LegacyInput $in, ?array $user): JsonResponse
    {
        return $this->withOwned($in, $user, fn(array $t) => $this->result(
            $this->tournaments->generateBracket((string)$t['id'], filter_var($in->input('randomize_seeds', false), FILTER_VALIDATE_BOOLEAN)),
            'Bracket drawn. The tournament is live.'
        ));
    }

    public function resetBracket(LegacyInput $in, ?array $user): JsonResponse
    {
        return $this->withOwned($in, $user, fn(array $t) => $this->result($this->tournaments->resetBracket((string)$t['id']), 'Bracket cleared. Registration is open again.'));
    }

    /**
     * A one-sided score is ambiguous and a negative one is not a pickleball
     * result: one-sided scores are dropped, out-of-range ones refused.
     */
    public function reportMatch(LegacyInput $in, ?array $user): JsonResponse
    {
        return $this->withOwned($in, $user, function (array $t) use ($in) {
            $raw1 = $in->input('score1', null);
            $raw2 = $in->input('score2', null);
            $score1 = ($raw1 === null || $raw1 === '') ? null : (int)$raw1;
            $score2 = ($raw2 === null || $raw2 === '') ? null : (int)$raw2;
            if ($score1 === null || $score2 === null) {
                $score1 = $score2 = null;
            } elseif ($score1 < 0 || $score2 < 0 || $score1 > 999 || $score2 > 999) {
                return $this->jsonError('Scores must be between 0 and 999.', 422);
            }

            return $this->result($this->tournaments->reportMatch(
                (string)$t['id'],
                (string)$in->input('match_id', ''),
                (string)$in->input('winner_team_id', ''),
                $score1,
                $score2
            ), 'Result recorded.');
        });
    }

    public function resetMatch(LegacyInput $in, ?array $user): JsonResponse
    {
        return $this->withOwned($in, $user, fn(array $t) => $this->result($this->tournaments->resetMatchResult((string)$t['id'], (string)$in->input('match_id', '')), 'Match result cleared.'));
    }

    /** @param callable(array):JsonResponse $then */
    private function withOwned(LegacyInput $in, ?array $user, callable $then): JsonResponse
    {
        $owned = $this->scope->ownedTournament($user, (string)$in->input('tournament_id', ''));
        if ($owned['error'] !== null) {
            return $this->jsonError($owned['error'], $owned['status']);
        }

        return $then($owned['tournament']);
    }

    /** @param array{error:?string,tournament?:array} $result */
    private function result(array $result, string $message): JsonResponse
    {
        if ($result['error'] !== null) {
            return $this->jsonError($result['error'], 422);
        }

        return $this->jsonSuccess(['tournament' => $result['tournament']], $message);
    }

    /** @param list<string> $keys */
    private static function sent(LegacyInput $in, array $keys): array
    {
        $patch = [];
        foreach ($keys as $key) {
            $value = $in->input($key, null);
            if ($value !== null) {
                $patch[$key] = $value;
            }
        }

        return $patch;
    }
}
