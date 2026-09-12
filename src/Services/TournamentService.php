<?php
declare(strict_types=1);

namespace Picklers\Services;

use Picklers\Core\Database;

/**
 * PICKLERS — Tournament Service
 *
 * Owns the tournament record end to end: roster management (fixed pairs or a
 * mix/partner draw pool), bracket generation via BracketEngine, result
 * reporting, and lifecycle status.
 *
 * Persistence is the same JSON store the rest of the owner portal uses, read
 * and written whole on every mutation so a record can never end up half-written.
 */
final class TournamentService
{
    public const STATUS_UPCOMING = 'upcoming';
    public const STATUS_ONGOING = 'ongoing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public const PAIRING_FIXED = 'fixed';
    public const PAIRING_MIX = 'mix';

    /** Roster ceiling for any single event. */
    public const MAX_TEAMS = 32;

    /** Play categories an owner can host. */
    public const CATEGORIES = [
        'Doubles',
        'Singles',
        'Mixed',
    ];

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    // --------------------------------------------------------------------------
    // Reads
    // --------------------------------------------------------------------------

    /** @return array<int,array<string,mixed>> */
    public function all(int|string|null $facilityId = null): array
    {
        $rows = $this->db->getJSONData('tournaments');
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ($facilityId !== null && (string)($row['facility_id'] ?? '') !== (string)$facilityId) {
                continue;
            }
            $out[] = $this->hydrate($row);
        }

        // Newest first — an owner is nearly always looking for what they just made.
        usort($out, fn($a, $b) => strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')));
        return $out;
    }

    public function find(string $id): ?array
    {
        foreach ($this->db->getJSONData('tournaments') as $row) {
            if (is_array($row) && (string)($row['id'] ?? '') === $id) {
                return $this->hydrate($row);
            }
        }
        return null;
    }

    /**
     * Tournaments bucketed by lifecycle stage, which is how every owner view
     * presents them.
     *
     * @return array{ongoing:array,upcoming:array,completed:array}
     */
    public function grouped(int|string|null $facilityId = null): array
    {
        $groups = ['ongoing' => [], 'upcoming' => [], 'completed' => []];
        foreach ($this->all($facilityId) as $t) {
            $status = (string)($t['status'] ?? self::STATUS_UPCOMING);
            if ($status === self::STATUS_ONGOING) {
                $groups['ongoing'][] = $t;
            } elseif ($status === self::STATUS_COMPLETED || $status === self::STATUS_CANCELLED) {
                $groups['completed'][] = $t;
            } else {
                $groups['upcoming'][] = $t;
            }
        }
        return $groups;
    }

    // --------------------------------------------------------------------------
    // Create / update / delete
    // --------------------------------------------------------------------------

    /**
     * @param array<string,mixed> $data
     * @return array{tournament:?array,error:?string}
     */
    public function create(array $data): array
    {
        $title = trim((string)($data['title'] ?? ''));
        if ($title === '') {
            return ['tournament' => null, 'error' => 'Tournament name is required.'];
        }
        if (mb_strlen($title) > 120) {
            return ['tournament' => null, 'error' => 'Tournament name must be 120 characters or fewer.'];
        }

        $category = $this->normalizeCategory((string)($data['category'] ?? ''));
        $format = BracketEngine::normalizeFormat((string)($data['format'] ?? ''));
        $maxTeams = $this->clampMaxTeams($data['max_teams'] ?? 16);
        $pairingMode = $this->resolvePairingMode((string)($data['pairing_mode'] ?? self::PAIRING_FIXED), $category);

        $dateError = $this->validateDates((string)($data['date'] ?? ''), (string)($data['end_date'] ?? ''));
        if ($dateError !== null) {
            return ['tournament' => null, 'error' => $dateError];
        }

        $startDate = trim((string)($data['date'] ?? ''));
        $endDate = trim((string)($data['end_date'] ?? ''));
        if ($endDate === '' && $startDate !== '') {
            $endDate = $startDate;
        }

        $now = date('Y-m-d H:i:s');
        $record = [
            'id' => $this->nextId(),
            'facility_id' => (string)($data['facility_id'] ?? ''),
            'owner_id' => (string)($data['owner_id'] ?? ''),
            'title' => $title,
            'category' => $category,
            'format' => $format,
            'status' => self::STATUS_UPCOMING,
            'pairing_mode' => $pairingMode,
            'date' => $startDate,
            'end_date' => $endDate,
            'time' => trim((string)($data['time'] ?? '')),
            'venue' => trim((string)($data['venue'] ?? '')),
            'max_teams' => $maxTeams,
            'prize_pool' => trim((string)($data['prize_pool'] ?? '')),
            'entry_fee' => trim((string)($data['entry_fee'] ?? '')),
            'description' => trim((string)($data['description'] ?? '')),
            'players_pool' => [],
            'teams' => [],
            'matches' => [],
            'champion_team_id' => null,
            'runner_up_team_id' => null,
            'bracket_generated_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        // Roster supplied inline by the create form.
        foreach ((array)($data['teams'] ?? []) as $team) {
            if (!is_array($team)) {
                continue;
            }
            $built = $this->buildTeam($team, $record);
            if ($built !== null && count($record['teams']) < $maxTeams) {
                $record['teams'][] = $built;
            }
        }
        foreach ((array)($data['players'] ?? []) as $player) {
            if (!is_array($player)) {
                continue;
            }
            $built = $this->buildPoolPlayer($player, $record);
            if ($built !== null && count($record['players_pool']) < $maxTeams * 2) {
                $record['players_pool'][] = $built;
            }
        }

        $rows = $this->db->getJSONData('tournaments');
        $rows[] = $record;
        $this->persist($rows);

        return ['tournament' => $this->hydrate($record), 'error' => null];
    }

    /**
     * @param array<string,mixed> $data
     * @return array{tournament:?array,error:?string}
     */
    public function update(string $id, array $data): array
    {
        return $this->mutate($id, function (array $t) use ($data): array {
            if (array_key_exists('title', $data)) {
                $title = trim((string)$data['title']);
                if ($title === '') {
                    return ['record' => $t, 'error' => 'Tournament name is required.'];
                }
                $t['title'] = mb_substr($title, 0, 120);
            }

            foreach (['date', 'end_date', 'time', 'venue', 'prize_pool', 'entry_fee', 'description'] as $key) {
                if (array_key_exists($key, $data)) {
                    $t[$key] = trim((string)$data[$key]);
                }
            }

            if (array_key_exists('date', $data) || array_key_exists('end_date', $data)) {
                $dateError = $this->validateDates((string)($t['date'] ?? ''), (string)($t['end_date'] ?? ''));
                if ($dateError !== null) {
                    return ['record' => $t, 'error' => $dateError];
                }
            }

            $bracketLive = !empty($t['matches']);

            if (array_key_exists('category', $data)) {
                $t['category'] = $this->normalizeCategory((string)$data['category']);
                $t['pairing_mode'] = $this->resolvePairingMode((string)($t['pairing_mode'] ?? self::PAIRING_FIXED), $t['category']);
            }

            // Format and pairing mode define the shape of a bracket that already
            // exists; changing them mid-event would silently invalidate every
            // result already reported.
            if (array_key_exists('format', $data)) {
                $newFormat = BracketEngine::normalizeFormat((string)$data['format']);
                if ($bracketLive && $newFormat !== ($t['format'] ?? '')) {
                    return ['record' => $t, 'error' => 'Reset the bracket before changing the format.'];
                }
                $t['format'] = $newFormat;
            }

            if (array_key_exists('pairing_mode', $data)) {
                $newMode = $this->resolvePairingMode((string)$data['pairing_mode'], (string)($t['category'] ?? ''));
                if ($bracketLive && $newMode !== ($t['pairing_mode'] ?? '')) {
                    return ['record' => $t, 'error' => 'Reset the bracket before changing the pairing mode.'];
                }
                $t['pairing_mode'] = $newMode;
            }

            if (array_key_exists('max_teams', $data)) {
                $newMax = $this->clampMaxTeams($data['max_teams']);
                if ($newMax < count($t['teams'] ?? [])) {
                    return ['record' => $t, 'error' => 'Max teams cannot be lower than the ' . count($t['teams']) . ' teams already registered.'];
                }
                $t['max_teams'] = $newMax;
            }

            if (array_key_exists('status', $data)) {
                $requested = strtolower(trim((string)$data['status']));
                $allowed = [self::STATUS_UPCOMING, self::STATUS_ONGOING, self::STATUS_COMPLETED, self::STATUS_CANCELLED];
                if (!in_array($requested, $allowed, true)) {
                    return ['record' => $t, 'error' => 'Unknown tournament status.'];
                }
                if ($requested === self::STATUS_ONGOING && empty($t['matches'])) {
                    return ['record' => $t, 'error' => 'Generate the bracket before setting this tournament live.'];
                }
                $t['status'] = $requested;
            }

            return ['record' => $t, 'error' => null];
        });
    }

    public function delete(string $id): bool
    {
        $rows = $this->db->getJSONData('tournaments');
        $kept = [];
        $found = false;
        foreach ($rows as $row) {
            if (is_array($row) && (string)($row['id'] ?? '') === $id) {
                $found = true;
                continue;
            }
            $kept[] = $row;
        }
        if (!$found) {
            return false;
        }
        $this->persist($kept);
        return true;
    }

    // --------------------------------------------------------------------------
    // Roster
    // --------------------------------------------------------------------------

    /**
     * Register a team. In fixed mode that is a full pair (or a single player for
     * singles categories); in mix mode the entrant joins the draw pool instead.
     *
     * @param array<string,mixed> $data
     * @return array{tournament:?array,error:?string}
     */
    public function addEntrant(string $id, array $data): array
    {
        return $this->mutate($id, function (array $t) use ($data): array {
            if (($t['status'] ?? '') === self::STATUS_CANCELLED) {
                return ['record' => $t, 'error' => 'This tournament has been cancelled.'];
            }
            if (!empty($t['matches'])) {
                return ['record' => $t, 'error' => 'The bracket is already drawn. Reset it before changing the roster.'];
            }

            if (($t['pairing_mode'] ?? self::PAIRING_FIXED) === self::PAIRING_MIX) {
                $player = $this->buildPoolPlayer($data, $t);
                if ($player === null) {
                    return ['record' => $t, 'error' => 'Player name is required.'];
                }
                foreach ($t['players_pool'] as $existing) {
                    if ($this->sameParticipant($existing, $player)) {
                        return ['record' => $t, 'error' => $player['name'] . ' is already in the draw pool.'];
                    }
                }
                if (count($t['players_pool']) >= (int)$t['max_teams'] * 2) {
                    return ['record' => $t, 'error' => 'The draw pool is full for a ' . $t['max_teams'] . '-team event.'];
                }
                $t['players_pool'][] = $player;
                return ['record' => $t, 'error' => null];
            }

            if (count($t['teams']) >= (int)$t['max_teams']) {
                return ['record' => $t, 'error' => 'This tournament is full at ' . $t['max_teams'] . ' teams.'];
            }

            $team = $this->buildTeam($data, $t);
            if ($team === null) {
                return [
                    'record' => $t,
                    'error' => $this->isSingles((string)$t['category'])
                        ? 'Player name is required.'
                        : 'Both player names are required for a fixed team.',
                ];
            }

            foreach ($t['teams'] as $existing) {
                if (strcasecmp((string)$existing['name'], (string)$team['name']) === 0) {
                    return ['record' => $t, 'error' => 'A team called "' . $team['name'] . '" is already registered.'];
                }
                foreach ([$team['player1'], $team['player2']] as $p) {
                    if ($p === '') {
                        continue;
                    }
                    if (strcasecmp((string)$existing['player1'], $p) === 0
                        || strcasecmp((string)$existing['player2'], $p) === 0
                    ) {
                        return ['record' => $t, 'error' => $p . ' is already registered with "' . $existing['name'] . '".'];
                    }
                }
            }

            $t['teams'][] = $team;
            $t['teams'] = $this->resequenceSeeds($t['teams']);
            return ['record' => $t, 'error' => null];
        });
    }

    /** @return array{tournament:?array,error:?string} */
    public function removeTeam(string $id, string $teamId): array
    {
        return $this->mutate($id, function (array $t) use ($teamId): array {
            if (($t['status'] ?? '') === self::STATUS_CANCELLED) {
                return ['record' => $t, 'error' => 'This tournament has been cancelled.'];
            }
            if (!empty($t['matches'])) {
                return ['record' => $t, 'error' => 'The bracket is already drawn. Reset it before changing the roster.'];
            }
            $before = count($t['teams']);
            $t['teams'] = array_values(array_filter($t['teams'], fn($tm) => (string)($tm['id'] ?? '') !== $teamId));
            if (count($t['teams']) === $before) {
                return ['record' => $t, 'error' => 'That team is not on this roster.'];
            }
            $t['teams'] = $this->resequenceSeeds($t['teams']);
            return ['record' => $t, 'error' => null];
        });
    }

    /** @return array{tournament:?array,error:?string} */
    public function removePoolPlayer(string $id, string $playerId): array
    {
        return $this->mutate($id, function (array $t) use ($playerId): array {
            if (($t['status'] ?? '') === self::STATUS_CANCELLED) {
                return ['record' => $t, 'error' => 'This tournament has been cancelled.'];
            }
            if (!empty($t['matches'])) {
                return ['record' => $t, 'error' => 'The bracket is already drawn. Reset it before changing the roster.'];
            }
            $before = count($t['players_pool']);
            $t['players_pool'] = array_values(array_filter(
                $t['players_pool'],
                fn($p) => (string)($p['id'] ?? '') !== $playerId
            ));
            if (count($t['players_pool']) === $before) {
                return ['record' => $t, 'error' => 'That player is not in the draw pool.'];
            }
            return ['record' => $t, 'error' => null];
        });
    }

    /**
     * Rename a team or hand-set its seed.
     *
     * @param array<string,mixed> $data
     * @return array{tournament:?array,error:?string}
     */
    public function updateTeam(string $id, string $teamId, array $data): array
    {
        return $this->mutate($id, function (array $t) use ($teamId, $data): array {
            if (($t['status'] ?? '') === self::STATUS_CANCELLED) {
                return ['record' => $t, 'error' => 'This tournament has been cancelled.'];
            }
            $found = false;
            foreach ($t['teams'] as $i => $team) {
                if ((string)($team['id'] ?? '') !== $teamId) {
                    continue;
                }
                $found = true;

                if (array_key_exists('name', $data)) {
                    $name = trim((string)$data['name']);
                    if ($name === '') {
                        return ['record' => $t, 'error' => 'Team name cannot be empty.'];
                    }
                    foreach ($t['teams'] as $j => $other) {
                        if ($i !== $j && strcasecmp((string)$other['name'], $name) === 0) {
                            return ['record' => $t, 'error' => 'Another team already uses that name.'];
                        }
                    }
                    $t['teams'][$i]['name'] = mb_substr($name, 0, 60);
                }

                foreach (['player1', 'player2'] as $slot) {
                    if (array_key_exists($slot, $data)) {
                        $t['teams'][$i][$slot] = mb_substr(trim((string)$data[$slot]), 0, 60);
                    }
                }

                if (array_key_exists('seed', $data)) {
                    if (!empty($t['matches'])) {
                        return ['record' => $t, 'error' => 'Reset the bracket before reseeding.'];
                    }
                    $seed = max(1, min(count($t['teams']), (int)$data['seed']));
                    $moving = $t['teams'][$i];
                    $rest = array_values(array_filter($t['teams'], fn($x) => (string)$x['id'] !== $teamId));
                    array_splice($rest, $seed - 1, 0, [$moving]);
                    $t['teams'] = $this->resequenceSeeds($rest);
                }
                break;
            }

            if (!$found) {
                return ['record' => $t, 'error' => 'That team is not on this roster.'];
            }
            return ['record' => $t, 'error' => null];
        });
    }

    /**
     * Pair the draw pool into random partnerships. Re-running it redraws from
     * scratch, which is the whole point of a partner draw.
     *
     * @return array{tournament:?array,error:?string}
     */
    public function mixDraw(string $id): array
    {
        return $this->mutate($id, function (array $t): array {
            if (($t['status'] ?? '') === self::STATUS_CANCELLED) {
                return ['record' => $t, 'error' => 'This tournament has been cancelled.'];
            }
            if (($t['pairing_mode'] ?? '') !== self::PAIRING_MIX) {
                return ['record' => $t, 'error' => 'This tournament uses fixed teammates, so there is nothing to draw.'];
            }
            if (!empty($t['matches'])) {
                return ['record' => $t, 'error' => 'The bracket is already drawn. Reset it before redrawing partners.'];
            }

            $pool = array_values($t['players_pool'] ?? []);
            if (count($pool) < 4) {
                return ['record' => $t, 'error' => 'Add at least 4 players to the pool before drawing partners.'];
            }

            shuffle($pool);

            $teams = [];
            $pairCount = intdiv(count($pool), 2);
            $maxTeams = (int)$t['max_teams'];

            for ($i = 0; $i < $pairCount && count($teams) < $maxTeams; $i++) {
                $p1 = $pool[$i * 2];
                $p2 = $pool[$i * 2 + 1];
                $teams[] = [
                    'id' => $this->uid('tm'),
                    'name' => $this->shortName($p1['name']) . ' / ' . $this->shortName($p2['name']),
                    'player1' => (string)$p1['name'],
                    'player2' => (string)$p2['name'],
                    'player1_id' => (string)($p1['user_id'] ?? ''),
                    'player2_id' => (string)($p2['user_id'] ?? ''),
                    'seed' => count($teams) + 1,
                    'source' => 'draw',
                    'created_at' => date('Y-m-d H:i:s'),
                ];
            }

            // An odd player out is recorded rather than silently dropped, so the
            // owner can see exactly who still needs a partner.
            $unpaired = [];
            if (count($pool) % 2 !== 0) {
                $unpaired[] = (string)$pool[count($pool) - 1]['name'];
            }
            for ($i = $pairCount * 2; $i < count($pool); $i++) {
                $name = (string)$pool[$i]['name'];
                if (!in_array($name, $unpaired, true)) {
                    $unpaired[] = $name;
                }
            }

            $t['teams'] = $teams;
            $t['unpaired_players'] = $unpaired;
            $t['last_draw_at'] = date('Y-m-d H:i:s');
            return ['record' => $t, 'error' => null];
        });
    }

    // --------------------------------------------------------------------------
    // Bracket lifecycle
    // --------------------------------------------------------------------------

    /**
     * Draw the bracket and put the tournament live.
     *
     * @return array{tournament:?array,error:?string}
     */
    public function generateBracket(string $id, bool $randomizeSeeds = false): array
    {
        return $this->mutate($id, function (array $t) use ($randomizeSeeds): array {
            if (!empty($t['matches'])) {
                return ['record' => $t, 'error' => 'A bracket already exists. Reset it before regenerating.'];
            }

            $teams = array_values($t['teams'] ?? []);

            if (count($teams) < BracketEngine::MIN_TEAMS) {
                return ['record' => $t, 'error' => 'At least ' . BracketEngine::MIN_TEAMS . ' teams are needed to draw a bracket.'];
            }
            if (count($teams) > self::MAX_TEAMS) {
                return ['record' => $t, 'error' => 'A bracket supports at most ' . self::MAX_TEAMS . ' teams.'];
            }

            if ($randomizeSeeds) {
                shuffle($teams);
                $teams = $this->resequenceSeeds($teams);
                $t['teams'] = $teams;
            }

            $matches = BracketEngine::generate($teams, (string)$t['format']);
            if ($matches === []) {
                return ['record' => $t, 'error' => 'Could not build a bracket from this roster.'];
            }

            $t['matches'] = $matches;
            $t['status'] = self::STATUS_ONGOING;
            $t['bracket_generated_at'] = date('Y-m-d H:i:s');
            $t['champion_team_id'] = null;
            $t['runner_up_team_id'] = null;
            return ['record' => $this->syncOutcome($t), 'error' => null];
        });
    }

    /** Tear the bracket down and reopen registration. */
    public function resetBracket(string $id): array
    {
        return $this->mutate($id, function (array $t): array {
            $t['matches'] = [];
            $t['status'] = self::STATUS_UPCOMING;
            $t['champion_team_id'] = null;
            $t['runner_up_team_id'] = null;
            $t['bracket_generated_at'] = null;
            $t['completed_at'] = null;
            return ['record' => $t, 'error' => null];
        });
    }

    /**
     * Report (or correct) a result. The engine cascades the consequences.
     *
     * @return array{tournament:?array,error:?string}
     */
    public function reportMatch(
        string $id,
        string $matchId,
        string $winnerTeamId,
        ?int $score1 = null,
        ?int $score2 = null
    ): array {
        return $this->mutate($id, function (array $t) use ($matchId, $winnerTeamId, $score1, $score2): array {
            if (empty($t['matches'])) {
                return ['record' => $t, 'error' => 'This tournament has no bracket yet.'];
            }
            if (($t['status'] ?? '') === self::STATUS_CANCELLED) {
                return ['record' => $t, 'error' => 'This tournament has been cancelled.'];
            }

            $result = BracketEngine::applyResult($t['matches'], $matchId, $winnerTeamId, $score1, $score2);
            if ($result['error'] !== null) {
                return ['record' => $t, 'error' => $result['error']];
            }

            $t['matches'] = $result['matches'];
            return ['record' => $this->syncOutcome($t), 'error' => null];
        });
    }

    /** @return array{tournament:?array,error:?string} */
    public function resetMatchResult(string $id, string $matchId): array
    {
        return $this->mutate($id, function (array $t) use ($matchId): array {
            if (empty($t['matches'])) {
                return ['record' => $t, 'error' => 'This tournament has no bracket yet.'];
            }
            if (BracketEngine::find($t['matches'], $matchId) === null) {
                return ['record' => $t, 'error' => 'Match not found in this bracket.'];
            }
            $t['matches'] = BracketEngine::resetMatch($t['matches'], $matchId);
            return ['record' => $this->syncOutcome($t), 'error' => null];
        });
    }

    /**
     * Keep champion / runner-up / status in step with what the bracket actually
     * says, so no caller has to remember to do it.
     *
     * @param array<string,mixed> $t
     * @return array<string,mixed>
     */
    private function syncOutcome(array $t): array
    {
        $matches = $t['matches'] ?? [];
        if ($matches === []) {
            $t['champion_team_id'] = null;
            $t['runner_up_team_id'] = null;
            return $t;
        }

        $champion = BracketEngine::champion($matches, (string)$t['format']);
        $t['champion_team_id'] = $champion;
        $t['runner_up_team_id'] = BracketEngine::runnerUp($matches, (string)$t['format']);

        if ($champion !== null) {
            if (($t['status'] ?? '') !== self::STATUS_CANCELLED) {
                $t['status'] = self::STATUS_COMPLETED;
                $t['completed_at'] = $t['completed_at'] ?? date('Y-m-d H:i:s');
            }
        } elseif (($t['status'] ?? '') === self::STATUS_COMPLETED) {
            // A corrected result can un-decide a finished event.
            $t['status'] = self::STATUS_ONGOING;
            $t['completed_at'] = null;
        }

        return $t;
    }

    // --------------------------------------------------------------------------
    // Hydration — every read goes through here
    // --------------------------------------------------------------------------

    /**
     * Fill in defaults, normalise legacy shapes, and attach the derived view data
     * (team lookup, resolved match names, rounds, progress, standings) that both
     * the PHP views and the JSON API need.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public function hydrate(array $row): array
    {
        $t = array_merge([
            'id' => '',
            'facility_id' => '',
            'owner_id' => '',
            'title' => 'Untitled Tournament',
            'category' => self::CATEGORIES[0],
            'format' => BracketEngine::FORMAT_SINGLE,
            'status' => self::STATUS_UPCOMING,
            'pairing_mode' => self::PAIRING_FIXED,
            'date' => '',
            'end_date' => '',
            'time' => '',
            'venue' => '',
            'max_teams' => 16,
            'prize_pool' => '',
            'entry_fee' => '',
            'description' => '',
            'players_pool' => [],
            'teams' => [],
            'matches' => [],
            'unpaired_players' => [],
            'champion_team_id' => null,
            'runner_up_team_id' => null,
            'bracket_generated_at' => null,
            'completed_at' => null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], $row);

        $t['format'] = BracketEngine::normalizeFormat((string)$t['format']);
        $t['format_label'] = BracketEngine::formatLabel($t['format']);
        $t['category'] = $this->normalizeCategory((string)$t['category']);
        $t['pairing_mode'] = $this->resolvePairingMode((string)$t['pairing_mode'], (string)$t['category']);
        $t['max_teams'] = $this->clampMaxTeams($t['max_teams']);
        $t['team_size'] = $this->isSingles((string)$t['category']) ? 1 : 2;
        $t['is_singles'] = $t['team_size'] === 1;

        $t['status'] = strtolower(trim((string)$t['status']));
        if (!in_array($t['status'], [self::STATUS_UPCOMING, self::STATUS_ONGOING, self::STATUS_COMPLETED, self::STATUS_CANCELLED], true)) {
            // Legacy records used "Upcoming"/"Live"/"Active"/"Finished".
            $t['status'] = match ($t['status']) {
                'live', 'active', 'in_progress' => self::STATUS_ONGOING,
                'finished', 'archived', 'done' => self::STATUS_COMPLETED,
                default => self::STATUS_UPCOMING,
            };
        }

        $t['teams'] = $this->resequenceSeeds(array_values(array_filter(
            (array)$t['teams'],
            fn($x) => is_array($x) && ($x['id'] ?? '') !== ''
        )));
        $t['players_pool'] = array_values(array_filter(
            (array)$t['players_pool'],
            fn($x) => is_array($x) && trim((string)($x['name'] ?? '')) !== ''
        ));
        $t['matches'] = array_values(array_filter((array)$t['matches'], 'is_array'));

        $t['teams_registered'] = count($t['teams']);
        $t['pool_size'] = count($t['players_pool']);
        $t['has_bracket'] = $t['matches'] !== [];

        // --- Derived, read-only view data ------------------------------------
        $lookup = [];
        foreach ($t['teams'] as $team) {
            $lookup[(string)$team['id']] = $team;
        }
        $t['team_lookup'] = $lookup;

        $t['progress'] = BracketEngine::progress($t['matches']);
        $t['standings'] = $t['format'] === BracketEngine::FORMAT_ROUND_ROBIN
            ? $this->decorateStandings(BracketEngine::standings($t['matches']), $lookup)
            : [];

        $t['champion'] = $t['champion_team_id'] !== null
            ? (string)($lookup[$t['champion_team_id']]['name'] ?? '')
            : '';
        $t['runner_up'] = $t['runner_up_team_id'] !== null
            ? (string)($lookup[$t['runner_up_team_id']]['name'] ?? '')
            : '';

        $t['rounds'] = $this->buildRounds($t['matches']);
        $t['can_generate'] = !$t['has_bracket'] && $t['teams_registered'] >= BracketEngine::MIN_TEAMS;
        $t['bracket_size'] = $t['teams_registered'] >= BracketEngine::MIN_TEAMS
            ? BracketEngine::bracketSize($t['teams_registered'])
            : 0;

        return $t;
    }

    /**
     * Group matches into display columns: winners rounds, losers rounds, and the
     * final(s), each in draw order.
     *
     * @param array<int,array<string,mixed>> $matches
     * @return array{W:array,L:array,F:array,R:array}
     */
    private function buildRounds(array $matches): array
    {
        $rounds = ['W' => [], 'L' => [], 'F' => [], 'R' => []];

        foreach ($matches as $m) {
            $bracket = (string)($m['bracket'] ?? 'W');
            if (!isset($rounds[$bracket])) {
                $bracket = 'W';
            }
            $round = (int)($m['round'] ?? 1);
            if (!isset($rounds[$bracket][$round])) {
                $rounds[$bracket][$round] = [
                    'round' => $round,
                    'name' => (string)($m['name'] ?? 'Round ' . $round),
                    'matches' => [],
                ];
            }
            $rounds[$bracket][$round]['matches'][] = $m;
        }

        foreach ($rounds as $bracket => $byRound) {
            ksort($byRound);
            foreach ($byRound as $r => $group) {
                usort($group['matches'], fn($a, $b) => ((int)($a['position'] ?? 0)) <=> ((int)($b['position'] ?? 0)));
                $byRound[$r] = $group;
            }
            $rounds[$bracket] = array_values($byRound);
        }

        return $rounds;
    }

    /**
     * @param array<int,array<string,mixed>> $standings
     * @param array<string,array<string,mixed>> $lookup
     * @return array<int,array<string,mixed>>
     */
    private function decorateStandings(array $standings, array $lookup): array
    {
        foreach ($standings as $i => $row) {
            $team = $lookup[(string)$row['team_id']] ?? null;
            $standings[$i]['team_name'] = (string)($team['name'] ?? 'Unknown');
            $standings[$i]['player1'] = (string)($team['player1'] ?? '');
            $standings[$i]['player2'] = (string)($team['player2'] ?? '');
        }
        return $standings;
    }

    // --------------------------------------------------------------------------
    // Internals
    // --------------------------------------------------------------------------

    /**
     * Load, transform, validate and persist one record. A callback that returns
     * an error leaves the store completely untouched.
     *
     * @param callable(array):array{record:array,error:?string} $fn
     * @return array{tournament:?array,error:?string}
     */
    private function mutate(string $id, callable $fn): array
    {
        $outcome = ['tournament' => null, 'error' => 'Tournament not found.'];

        $this->db->lockedJSONUpdate('tournaments', function (array $rows) use ($id, $fn, &$outcome): array {
            $index = null;
            foreach ($rows as $i => $row) {
                if (is_array($row) && (string)($row['id'] ?? '') === $id) {
                    $index = $i;
                    break;
                }
            }
            if ($index === null) {
                return $rows;
            }

            $current = $this->hydrate($rows[$index]);
            $result  = $fn($current);

            if (($result['error'] ?? null) !== null) {
                $outcome = ['tournament' => $this->hydrate($rows[$index]), 'error' => (string)$result['error']];
                return $rows;
            }

            $record = $result['record'];
            $record['updated_at'] = date('Y-m-d H:i:s');
            $rows[$index] = $this->strip($record);
            $outcome = ['tournament' => $this->hydrate($rows[$index]), 'error' => null];
            return array_values($rows);
        });

        if ($outcome['error'] === null) {
            $this->db->bumpSync('tournaments');
        }

        return $outcome;
    }

    /**
     * Drop the derived fields hydrate() adds, so they never get written back and
     * drift out of sync with the data they were computed from.
     *
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    private function strip(array $record): array
    {
        foreach ([
            'team_lookup', 'progress', 'standings', 'rounds', 'champion', 'runner_up',
            'teams_registered', 'pool_size', 'has_bracket', 'can_generate', 'bracket_size',
            'format_label', 'team_size', 'is_singles',
        ] as $derived) {
            unset($record[$derived]);
        }
        return $record;
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $tournament
     * @return array<string,mixed>|null
     */
    private function buildTeam(array $data, array $tournament): ?array
    {
        $singles = $this->isSingles((string)($tournament['category'] ?? ''));

        $p1 = mb_substr(trim((string)($data['player1'] ?? ($data['player1_name'] ?? ($data['name'] ?? '')))), 0, 60);
        $p2 = $singles ? '' : mb_substr(trim((string)($data['player2'] ?? ($data['player2_name'] ?? ''))), 0, 60);

        if ($p1 === '') {
            return null;
        }
        if (!$singles && $p2 === '') {
            return null;
        }
        if (!$singles && strcasecmp($p1, $p2) === 0) {
            return null;
        }

        $name = mb_substr(trim((string)($data['name'] ?? ($data['team_name'] ?? ''))), 0, 60);
        if ($name === '') {
            $name = $singles ? $p1 : ($this->shortName($p1) . ' / ' . $this->shortName($p2));
        }

        return [
            'id' => $this->uid('tm'),
            'name' => $name,
            'player1' => $p1,
            'player2' => $p2,
            'player1_id' => (string)($data['player1_id'] ?? ''),
            'player2_id' => (string)($data['player2_id'] ?? ''),
            'seed' => count($tournament['teams'] ?? []) + 1,
            'source' => 'registration',
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $tournament
     * @return array<string,mixed>|null
     */
    private function buildPoolPlayer(array $data, array $tournament): ?array
    {
        $name = mb_substr(trim((string)($data['name'] ?? ($data['player1'] ?? ''))), 0, 60);
        if ($name === '') {
            return null;
        }

        return [
            'id' => $this->uid('pp'),
            'name' => $name,
            'email' => mb_substr(trim((string)($data['email'] ?? '')), 0, 120),
            'user_id' => (string)($data['user_id'] ?? ($data['id'] ?? '')),
            'joined_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     */
    private function sameParticipant(array $a, array $b): bool
    {
        $aUser = trim((string)($a['user_id'] ?? ''));
        $bUser = trim((string)($b['user_id'] ?? ''));
        if ($aUser !== '' && $aUser === $bUser) {
            return true;
        }
        return strcasecmp(trim((string)($a['name'] ?? '')), trim((string)($b['name'] ?? ''))) === 0;
    }

    /**
     * @param array<int,array<string,mixed>> $teams
     * @return array<int,array<string,mixed>>
     */
    private function resequenceSeeds(array $teams): array
    {
        $teams = array_values($teams);
        foreach ($teams as $i => $team) {
            $teams[$i]['seed'] = $i + 1;
        }
        return $teams;
    }

    private function normalizeCategory(string $raw): string
    {
        $raw = trim($raw);
        foreach (self::CATEGORIES as $c) {
            if (strcasecmp($c, $raw) === 0) {
                return $c;
            }
        }
        // Legacy labels carried suffixes like "Men's Doubles Open".
        foreach (self::CATEGORIES as $c) {
            if ($raw !== '' && stripos($raw, $c) === 0) {
                return $c;
            }
        }
        return $raw !== '' ? mb_substr($raw, 0, 60) : self::CATEGORIES[0];
    }

    public function isSingles(string $category): bool
    {
        return stripos($category, 'singles') !== false;
    }

    /** Mix/partner draw is meaningless when a "team" is one person. */
    private function resolvePairingMode(string $mode, string $category): string
    {
        if ($this->isSingles($category)) {
            return self::PAIRING_FIXED;
        }
        return strtolower(trim($mode)) === self::PAIRING_MIX ? self::PAIRING_MIX : self::PAIRING_FIXED;
    }

    private function clampMaxTeams(mixed $value): int
    {
        $n = (int)$value;
        if ($n < BracketEngine::MIN_TEAMS) {
            $n = BracketEngine::MIN_TEAMS;
        }
        return min(self::MAX_TEAMS, $n);
    }

    /** Both dates are optional; when both are present they must be in order. */
    private function validateDates(string $start, string $end): ?string
    {
        if ($start === '' || $end === '') {
            return null;
        }
        $s = strtotime($start);
        $e = strtotime($end);
        if ($s === false || $e === false) {
            return null;
        }
        return $e < $s ? 'The end date cannot be before the start date.' : null;
    }

    private function shortName(string $full): string
    {
        $parts = preg_split('/\s+/', trim($full)) ?: [];
        if (count($parts) <= 1) {
            return trim($full);
        }
        return $parts[0] . ' ' . mb_strtoupper(mb_substr((string)end($parts), 0, 1)) . '.';
    }

    private function uid(string $prefix): string
    {
        return $prefix . '_' . bin2hex(random_bytes(5));
    }

    private function nextId(): string
    {
        return 'tourn_' . date('ymd') . '_' . bin2hex(random_bytes(4));
    }
}
