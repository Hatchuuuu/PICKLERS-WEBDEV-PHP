<?php
declare(strict_types=1);

namespace Picklers\Services;

/**
 * PICKLERS — Tournament Bracket Engine
 *
 * Pure, side-effect-free bracket mathematics: generation, bye resolution,
 * result application with downstream cascade, and standings.
 *
 * Every match carries explicit `next_win` / `next_lose` pointers, so advancing
 * a result is a plain graph write and undoing one is a deterministic cascade —
 * there is no round-scanning guesswork anywhere in this file.
 *
 * A slot holds one of three things:
 *   null        — still waiting on an upstream match
 *   self::BYE   — permanently empty (an upstream bye produced no loser)
 *   "<team id>" — an actual competitor
 */
final class BracketEngine
{
    public const FORMAT_SINGLE = 'single_elimination';
    public const FORMAT_DOUBLE = 'double_elimination';
    public const FORMAT_ROUND_ROBIN = 'round_robin';

    /** Sentinel occupying a slot that will never receive a team. */
    public const BYE = '__BYE__';

    public const MIN_TEAMS = 2;
    public const MAX_TEAMS = 32;

    /** @return string[] */
    public static function formats(): array
    {
        return [self::FORMAT_SINGLE, self::FORMAT_DOUBLE, self::FORMAT_ROUND_ROBIN];
    }

    /**
     * Accepts anything a human or a legacy record might have stored
     * ("Single Elimination", "single-elim", "double_elimination", "Round Robin")
     * and returns one of the three canonical constants.
     */
    public static function normalizeFormat(?string $raw): string
    {
        $key = strtolower(trim((string)$raw));
        $key = preg_replace('/[^a-z]/', '', $key) ?? '';

        if (str_contains($key, 'roundrobin') || $key === 'rr') {
            return self::FORMAT_ROUND_ROBIN;
        }
        if (str_contains($key, 'double')) {
            return self::FORMAT_DOUBLE;
        }
        return self::FORMAT_SINGLE;
    }

    public static function formatLabel(?string $format): string
    {
        return match (self::normalizeFormat($format)) {
            self::FORMAT_DOUBLE => 'Double Elimination',
            self::FORMAT_ROUND_ROBIN => 'Round Robin',
            default => 'Single Elimination',
        };
    }

    /** Smallest power of two that can seat every team. */
    public static function bracketSize(int $teamCount): int
    {
        $size = 2;
        while ($size < $teamCount) {
            $size *= 2;
        }
        return $size;
    }

    /**
     * Standard tournament seed order for a bracket of $size slots.
     * 8 -> [1,8,4,5,2,7,3,6] so the top two seeds can only meet in the final.
     *
     * @return int[]
     */
    public static function seedOrder(int $size): array
    {
        $order = [1, 2];
        while (count($order) < $size) {
            $mirror = count($order) * 2 + 1;
            $next = [];
            foreach ($order as $seed) {
                $next[] = $seed;
                $next[] = $mirror - $seed;
            }
            $order = $next;
        }
        return $order;
    }

    // --------------------------------------------------------------------------
    // Generation
    // --------------------------------------------------------------------------

    /**
     * Build the complete match graph for a roster.
     *
     * @param array<int,array<string,mixed>> $teams  Ordered by seed (index 0 = seed 1)
     * @return array<int,array<string,mixed>>        Matches, already bye-resolved
     */
    public static function generate(array $teams, ?string $format): array
    {
        $format = self::normalizeFormat($format);
        $teamIds = [];
        foreach ($teams as $t) {
            $id = (string)($t['id'] ?? '');
            if ($id !== '') {
                $teamIds[] = $id;
            }
        }

        if (count($teamIds) < self::MIN_TEAMS) {
            return [];
        }

        $matches = match ($format) {
            self::FORMAT_ROUND_ROBIN => self::buildRoundRobin($teamIds),
            self::FORMAT_DOUBLE => self::buildDoubleElimination($teamIds),
            default => self::buildSingleElimination($teamIds),
        };

        return self::propagate($matches);
    }

    /** @param string[] $teamIds */
    private static function buildSingleElimination(array $teamIds): array
    {
        $size = self::bracketSize(count($teamIds));
        $rounds = (int)round(log($size, 2));
        $slots = self::seatTeams($teamIds, $size);
        $matches = [];

        for ($r = 1; $r <= $rounds; $r++) {
            $matchCount = intdiv($size, 2 ** $r);
            for ($i = 0; $i < $matchCount; $i++) {
                $next = $r < $rounds
                    ? ['match' => self::matchId('W', $r + 1, intdiv($i, 2)), 'slot' => ($i % 2 === 0) ? 1 : 2]
                    : null;

                $matches[] = self::blankMatch([
                    'id' => self::matchId('W', $r, $i),
                    'bracket' => 'W',
                    'round' => $r,
                    'position' => $i + 1,
                    'name' => self::eliminationRoundName($matchCount, ''),
                    'team1' => $r === 1 ? ($slots[$i * 2] ?? self::BYE) : null,
                    'team2' => $r === 1 ? ($slots[$i * 2 + 1] ?? self::BYE) : null,
                    'team1_source' => $r === 1 ? null : ['type' => 'winner', 'match' => self::matchId('W', $r - 1, $i * 2)],
                    'team2_source' => $r === 1 ? null : ['type' => 'winner', 'match' => self::matchId('W', $r - 1, $i * 2 + 1)],
                    'next_win' => $next,
                    'next_lose' => null,
                    'is_final' => $r === $rounds,
                ]);
            }
        }

        return $matches;
    }

    /**
     * Winners bracket + losers bracket + grand final (+ a reset match that only
     * activates when the losers-bracket survivor wins the grand final, which is
     * what "double elimination" actually means — one loss is not elimination).
     *
     * @param string[] $teamIds
     */
    private static function buildDoubleElimination(array $teamIds): array
    {
        $size = self::bracketSize(count($teamIds));
        $wbRounds = (int)round(log($size, 2));
        $slots = self::seatTeams($teamIds, $size);
        $matches = [];

        // ---- Winners bracket -------------------------------------------------
        for ($r = 1; $r <= $wbRounds; $r++) {
            $matchCount = intdiv($size, 2 ** $r);
            for ($i = 0; $i < $matchCount; $i++) {
                $matches[] = self::blankMatch([
                    'id' => self::matchId('W', $r, $i),
                    'bracket' => 'W',
                    'round' => $r,
                    'position' => $i + 1,
                    'name' => self::eliminationRoundName($matchCount, 'W-'),
                    'team1' => $r === 1 ? ($slots[$i * 2] ?? self::BYE) : null,
                    'team2' => $r === 1 ? ($slots[$i * 2 + 1] ?? self::BYE) : null,
                    'team1_source' => $r === 1 ? null : ['type' => 'winner', 'match' => self::matchId('W', $r - 1, $i * 2)],
                    'team2_source' => $r === 1 ? null : ['type' => 'winner', 'match' => self::matchId('W', $r - 1, $i * 2 + 1)],
                    'next_win' => $r < $wbRounds
                        ? ['match' => self::matchId('W', $r + 1, intdiv($i, 2)), 'slot' => ($i % 2 === 0) ? 1 : 2]
                        : ['match' => 'GF', 'slot' => 1],
                    'next_lose' => null, // wired below
                    'is_final' => $r === $wbRounds,
                ]);
            }
        }

        // ---- Losers bracket --------------------------------------------------
        // Round widths for size 8 -> [2,2,1,1]; for 16 -> [4,4,2,2,1,1].
        $lbRounds = max(0, 2 * $wbRounds - 2);
        $lbCounts = [];
        for ($r = 1; $r <= $lbRounds; $r++) {
            $lbCounts[$r] = intdiv($size, 2 ** (intdiv($r + 1, 2) + 1));
        }

        for ($r = 1; $r <= $lbRounds; $r++) {
            $matchCount = $lbCounts[$r];
            for ($i = 0; $i < $matchCount; $i++) {
                $next = $r < $lbRounds
                    ? self::lbNextTarget($r, $i, $lbCounts)
                    : ['match' => 'GF', 'slot' => 2];

                $matches[] = self::blankMatch([
                    'id' => self::matchId('L', $r, $i),
                    'bracket' => 'L',
                    'round' => $r,
                    'position' => $i + 1,
                    'name' => self::losersRoundName($r, $lbRounds),
                    'next_win' => $next,
                    'next_lose' => null,
                ]);
            }
        }

        $byId = [];
        foreach ($matches as $idx => $m) {
            $byId[$m['id']] = $idx;
        }

        // ---- Wire winners-bracket losers into the losers bracket --------------
        if ($lbRounds > 0) {
            // Round-1 losers fill both slots of L-Round 1, taken in reverse order
            // so the two halves of the draw stay apart as long as possible.
            $wb1Count = intdiv($size, 2);
            $sources = array_reverse(range(0, $wb1Count - 1));
            for ($i = 0; $i < $lbCounts[1]; $i++) {
                foreach ([1, 2] as $k) {
                    $wbIndex = $sources[$i * 2 + ($k - 1)] ?? null;
                    if ($wbIndex === null) {
                        continue;
                    }
                    $wbId = self::matchId('W', 1, $wbIndex);
                    $matches[$byId[$wbId]]['next_lose'] = ['match' => self::matchId('L', 1, $i), 'slot' => $k];
                }
            }

            // Every "major" losers round (2, 4, 6 ...) absorbs the losers of the
            // next winners round, again reversed against the losers-bracket order.
            for ($r = 2; $r <= $lbRounds; $r += 2) {
                $wbRound = intdiv($r, 2) + 1;
                $matchCount = $lbCounts[$r];
                for ($i = 0; $i < $matchCount; $i++) {
                    $wbId = self::matchId('W', $wbRound, $matchCount - 1 - $i);
                    if (!isset($byId[$wbId])) {
                        continue;
                    }
                    $matches[$byId[$wbId]]['next_lose'] = ['match' => self::matchId('L', $r, $i), 'slot' => 2];
                }
            }
        } else {
            // Two-team bracket: the single winners match feeds both sides of the
            // grand final, because its loser still holds one life.
            $wbFinalId = self::matchId('W', $wbRounds, 0);
            $matches[$byId[$wbFinalId]]['next_lose'] = ['match' => 'GF', 'slot' => 2];
        }

        // ---- Grand final + conditional reset ---------------------------------
        $matches[] = self::blankMatch([
            'id' => 'GF',
            'bracket' => 'F',
            'round' => 1,
            'position' => 1,
            'name' => 'Championship',
            'team1_source' => ['type' => 'winner', 'match' => self::matchId('W', $wbRounds, 0)],
            'team2_source' => $lbRounds > 0
                ? ['type' => 'winner', 'match' => self::matchId('L', $lbRounds, 0)]
                : ['type' => 'loser', 'match' => self::matchId('W', $wbRounds, 0)],
            'next_win' => null,
            'next_lose' => null,
            'is_final' => true,
        ]);

        $matches[] = self::blankMatch([
            'id' => 'GF2',
            'bracket' => 'F',
            'round' => 2,
            'position' => 1,
            'name' => 'Championship Reset',
            'team1_source' => ['type' => 'winner', 'match' => 'GF'],
            'team2_source' => ['type' => 'loser', 'match' => 'GF'],
            'next_win' => null,
            'next_lose' => null,
            'is_final' => true,
            'conditional' => true,
            'active' => false,
            'status' => 'inactive',
        ]);

        return $matches;
    }

    /**
     * A losers-bracket winner either waits for a winners-bracket loser to drop
     * in (major round) or pairs off with another losers winner (minor round).
     *
     * @param array<int,int> $lbCounts
     * @return array{match:string,slot:int}
     */
    private static function lbNextTarget(int $round, int $index, array $lbCounts): array
    {
        $nextCount = $lbCounts[$round + 1] ?? 1;
        $thisCount = $lbCounts[$round];

        if ($nextCount === $thisCount) {
            // Major round: same width, this winner takes slot 1 and a winners
            // bracket loser drops into slot 2.
            return ['match' => self::matchId('L', $round + 1, $index), 'slot' => 1];
        }

        // Minor round: two losers-bracket winners pair off.
        return [
            'match' => self::matchId('L', $round + 1, intdiv($index, 2)),
            'slot' => ($index % 2 === 0) ? 1 : 2,
        ];
    }

    /**
     * Circle-method round robin: every team meets every other team exactly once.
     *
     * @param string[] $teamIds
     */
    private static function buildRoundRobin(array $teamIds): array
    {
        $roster = $teamIds;
        if (count($roster) % 2 !== 0) {
            $roster[] = self::BYE; // one team sits out each round
        }

        $n = count($roster);
        $rounds = $n - 1;
        $half = intdiv($n, 2);
        $matches = [];

        $anchor = $roster[0];
        $rotating = array_slice($roster, 1);

        for ($r = 1; $r <= $rounds; $r++) {
            $lineup = array_merge([$anchor], $rotating);
            $position = 0;
            for ($i = 0; $i < $half; $i++) {
                $a = $lineup[$i];
                $b = $lineup[$n - 1 - $i];
                if ($a === self::BYE || $b === self::BYE) {
                    continue; // the sitting-out fixture is not a match
                }
                $matches[] = self::blankMatch([
                    'id' => self::matchId('R', $r, $position),
                    'bracket' => 'R',
                    'round' => $r,
                    'position' => $position + 1,
                    'name' => 'Round ' . $r,
                    'team1' => $a,
                    'team2' => $b,
                    'next_win' => null,
                    'next_lose' => null,
                ]);
                $position++;
            }
            // Rotate everything but the anchor.
            array_unshift($rotating, array_pop($rotating));
        }

        return $matches;
    }

    /**
     * Place teams into bracket slots by seed, leaving BYE sentinels in the
     * surplus slots so the top seeds get the walkovers.
     *
     * @param string[] $teamIds
     * @return array<int,string>
     */
    private static function seatTeams(array $teamIds, int $size): array
    {
        $order = self::seedOrder($size);
        $slots = [];
        foreach ($order as $slotIndex => $seed) {
            $slots[$slotIndex] = $teamIds[$seed - 1] ?? self::BYE;
        }
        return $slots;
    }

    private static function matchId(string $bracket, int $round, int $index): string
    {
        return sprintf('%s%d-%d', $bracket, $round, $index + 1);
    }

    /** @param array<string,mixed> $overrides */
    private static function blankMatch(array $overrides): array
    {
        return array_merge([
            'id' => '',
            'bracket' => 'W',
            'round' => 1,
            'position' => 1,
            'name' => 'Match',
            'team1' => null,
            'team2' => null,
            'team1_source' => null,
            'team2_source' => null,
            'winner' => null,
            'loser' => null,
            'score1' => null,
            'score2' => null,
            'score' => '',
            'status' => 'pending',
            'next_win' => null,
            'next_lose' => null,
            'is_final' => false,
            'conditional' => false,
            'active' => true,
            'court' => null,
            'scheduled_at' => null,
            'completed_at' => null,
        ], $overrides);
    }

    private static function eliminationRoundName(int $matchCount, string $prefix): string
    {
        return $prefix . match ($matchCount) {
            1 => 'Final',
            2 => 'Semifinal',
            4 => 'Quarterfinal',
            default => 'Round of ' . ($matchCount * 2),
        };
    }

    private static function losersRoundName(int $round, int $totalRounds): string
    {
        if ($round === $totalRounds) {
            return 'L-Final';
        }
        if ($round === $totalRounds - 1) {
            return 'L-Semifinal';
        }
        return 'L-Round ' . $round;
    }

    // --------------------------------------------------------------------------
    // Propagation
    // --------------------------------------------------------------------------

    /**
     * Settle every match whose outcome is already determined — walkovers, dead
     * fixtures where both feeds were byes, and any completed result whose winner
     * has not yet been written into the next round.
     *
     * Runs to a fixed point, so it is safe to call after any mutation.
     *
     * @param array<int,array<string,mixed>> $matches
     * @return array<int,array<string,mixed>>
     */
    public static function propagate(array $matches): array
    {
        $byId = [];
        foreach ($matches as $idx => $m) {
            $byId[$m['id']] = $idx;
        }

        // Bounded by the longest feed chain, with headroom in case a hand-edited
        // record ever produces a longer path than generation would.
        $guard = count($matches) + 8;

        do {
            $changed = false;
            $guard--;

            foreach ($matches as $idx => $m) {
                if (($m['active'] ?? true) === false) {
                    continue;
                }

                $t1 = $matches[$idx]['team1'];
                $t2 = $matches[$idx]['team2'];
                $hasWinner = !empty($matches[$idx]['winner']);

                // Resolve outcomes nobody needs to play.
                if (!$hasWinner && $t1 !== null && $t2 !== null) {
                    if ($t1 === self::BYE && $t2 === self::BYE) {
                        $matches[$idx]['winner'] = self::BYE;
                        $matches[$idx]['loser'] = self::BYE;
                        $matches[$idx]['status'] = 'void';
                        $hasWinner = true;
                        $changed = true;
                    } elseif ($t1 === self::BYE || $t2 === self::BYE) {
                        $matches[$idx]['winner'] = ($t1 === self::BYE) ? $t2 : $t1;
                        $matches[$idx]['loser'] = self::BYE;
                        $matches[$idx]['status'] = 'bye';
                        $matches[$idx]['score'] = 'Walkover';
                        $hasWinner = true;
                        $changed = true;
                    } elseif (($matches[$idx]['status'] ?? 'pending') === 'pending') {
                        $matches[$idx]['status'] = 'ready';
                        $changed = true;
                    }
                } elseif (!$hasWinner
                    && ($matches[$idx]['status'] ?? '') === 'ready'
                    && ($t1 === null || $t2 === null)
                ) {
                    $matches[$idx]['status'] = 'pending';
                    $changed = true;
                }

                if (!$hasWinner) {
                    continue;
                }

                $winner = $matches[$idx]['winner'];
                $loser = $matches[$idx]['loser'] ?? self::BYE;

                foreach ([['next_win', $winner], ['next_lose', $loser]] as $pair) {
                    $target = $matches[$idx][$pair[0]] ?? null;
                    if (!$target || !isset($byId[$target['match']])) {
                        continue;
                    }
                    $tIdx = $byId[$target['match']];
                    if (($matches[$tIdx]['active'] ?? true) === false) {
                        continue;
                    }
                    $slotKey = 'team' . ((int)$target['slot'] === 2 ? '2' : '1');
                    if ($matches[$tIdx][$slotKey] !== $pair[1]) {
                        $matches[$tIdx][$slotKey] = $pair[1];
                        $changed = true;
                    }
                }
            }
        } while ($changed && $guard > 0);

        return $matches;
    }

    // --------------------------------------------------------------------------
    // Results
    // --------------------------------------------------------------------------

    /**
     * Record (or overwrite) a match result and re-derive everything downstream.
     *
     * Overwriting is deliberately destructive downstream: a bracket where the
     * quarterfinal winner changed but the semifinal still shows the old team is
     * exactly the silent corruption this engine exists to prevent.
     *
     * @param array<int,array<string,mixed>> $matches
     * @return array{matches:array<int,array<string,mixed>>,error:?string}
     */
    public static function applyResult(
        array $matches,
        string $matchId,
        string $winnerTeamId,
        ?int $score1 = null,
        ?int $score2 = null
    ): array {
        $idx = self::indexOf($matches, $matchId);
        if ($idx === null) {
            return ['matches' => $matches, 'error' => 'Match not found in this bracket.'];
        }

        $match = $matches[$idx];

        if (($match['active'] ?? true) === false) {
            return ['matches' => $matches, 'error' => 'This match is not active yet.'];
        }
        if (in_array($match['status'] ?? '', ['bye', 'void'], true)) {
            return ['matches' => $matches, 'error' => 'This match resolved automatically and cannot be scored.'];
        }

        $t1 = $match['team1'];
        $t2 = $match['team2'];
        if (!self::isRealTeam($t1) || !self::isRealTeam($t2)) {
            return ['matches' => $matches, 'error' => 'Both teams must be decided before reporting a result.'];
        }
        if ($winnerTeamId !== $t1 && $winnerTeamId !== $t2) {
            return ['matches' => $matches, 'error' => 'The selected winner is not playing in this match.'];
        }

        // A score that contradicts the chosen winner is a data-entry mistake, not
        // an override — reject it rather than persisting a bracket that disagrees
        // with its own scoreline.
        if ($score1 !== null && $score2 !== null) {
            if ($score1 === $score2) {
                return ['matches' => $matches, 'error' => 'A match cannot end level. Enter a decisive score.'];
            }
            $scoreWinner = $score1 > $score2 ? $t1 : $t2;
            if ($scoreWinner !== $winnerTeamId) {
                return ['matches' => $matches, 'error' => 'The score does not match the selected winner.'];
            }
        }

        // Undo whatever this result used to imply before writing the new one.
        $matches = self::clearDownstream($matches, $matchId);
        $idx = (int)self::indexOf($matches, $matchId);

        $matches[$idx]['winner'] = $winnerTeamId;
        $matches[$idx]['loser'] = ($winnerTeamId === $t1) ? $t2 : $t1;
        $matches[$idx]['score1'] = $score1;
        $matches[$idx]['score2'] = $score2;
        $matches[$idx]['score'] = ($score1 !== null && $score2 !== null) ? ($score1 . ' - ' . $score2) : '';
        $matches[$idx]['status'] = 'completed';
        $matches[$idx]['completed_at'] = date('Y-m-d H:i:s');

        // The reset match only exists when the losers-bracket survivor takes the
        // grand final — otherwise it stays dormant and invisible.
        if ($matchId === 'GF') {
            $resetIdx = self::indexOf($matches, 'GF2');
            if ($resetIdx !== null) {
                $needsReset = ($winnerTeamId === $t2);
                $matches[$resetIdx]['active'] = $needsReset;
                $matches[$resetIdx]['status'] = $needsReset ? 'ready' : 'inactive';
                $matches[$resetIdx]['team1'] = $needsReset ? $winnerTeamId : null;
                $matches[$resetIdx]['team2'] = $needsReset ? $matches[$idx]['loser'] : null;
            }
        }

        return ['matches' => self::propagate($matches), 'error' => null];
    }

    /**
     * Wipe a result and everything it fed, returning the bracket to exactly the
     * state it held before that match was ever reported.
     *
     * @param array<int,array<string,mixed>> $matches
     * @return array<int,array<string,mixed>>
     */
    public static function resetMatch(array $matches, string $matchId): array
    {
        $idx = self::indexOf($matches, $matchId);
        if ($idx === null) {
            return $matches;
        }

        $matches = self::clearDownstream($matches, $matchId);
        $idx = (int)self::indexOf($matches, $matchId);

        if ($matchId === 'GF') {
            $resetIdx = self::indexOf($matches, 'GF2');
            if ($resetIdx !== null) {
                $matches[$resetIdx]['active'] = false;
                $matches[$resetIdx]['status'] = 'inactive';
                $matches[$resetIdx]['team1'] = null;
                $matches[$resetIdx]['team2'] = null;
                $matches[$resetIdx]['winner'] = null;
                $matches[$resetIdx]['loser'] = null;
                $matches[$resetIdx]['score1'] = null;
                $matches[$resetIdx]['score2'] = null;
                $matches[$resetIdx]['score'] = '';
                $matches[$resetIdx]['completed_at'] = null;
            }
        }

        $matches[$idx]['winner'] = null;
        $matches[$idx]['loser'] = null;
        $matches[$idx]['score1'] = null;
        $matches[$idx]['score2'] = null;
        $matches[$idx]['score'] = '';
        $matches[$idx]['status'] = ($matches[$idx]['conditional'] ?? false) ? 'ready' : 'pending';
        $matches[$idx]['completed_at'] = null;

        return self::propagate($matches);
    }

    /**
     * Remove this match's winner/loser from the slots it feeds, recursively
     * clearing any result those downstream matches had already produced.
     *
     * @param array<int,array<string,mixed>> $matches
     * @return array<int,array<string,mixed>>
     */
    private static function clearDownstream(array $matches, string $matchId, int $depth = 0): array
    {
        if ($depth > 64) {
            return $matches;
        }

        $idx = self::indexOf($matches, $matchId);
        if ($idx === null) {
            return $matches;
        }

        foreach (['next_win', 'next_lose'] as $key) {
            $target = $matches[$idx][$key] ?? null;
            if (!$target) {
                continue;
            }
            $targetId = (string)$target['match'];
            $tIdx = self::indexOf($matches, $targetId);
            if ($tIdx === null) {
                continue;
            }

            $slotKey = 'team' . ((int)$target['slot'] === 2 ? '2' : '1');
            if ($matches[$tIdx][$slotKey] === null) {
                continue;
            }

            // Clear the child's own result first, then vacate the slot.
            $matches = self::clearDownstream($matches, $targetId, $depth + 1);
            $tIdx = (int)self::indexOf($matches, $targetId);

            $isConditional = (bool)($matches[$tIdx]['conditional'] ?? false);
            $matches[$tIdx][$slotKey] = null;
            $matches[$tIdx]['winner'] = null;
            $matches[$tIdx]['loser'] = null;
            $matches[$tIdx]['score1'] = null;
            $matches[$tIdx]['score2'] = null;
            $matches[$tIdx]['score'] = '';
            $matches[$tIdx]['completed_at'] = null;
            $matches[$tIdx]['status'] = $isConditional ? 'inactive' : 'pending';
            if ($isConditional) {
                $matches[$tIdx]['active'] = false;
                $matches[$tIdx]['team1'] = null;
                $matches[$tIdx]['team2'] = null;
            }
        }

        return $matches;
    }

    // --------------------------------------------------------------------------
    // Derived state
    // --------------------------------------------------------------------------

    /**
     * The match that decides the whole event, or null for formats without one.
     *
     * @param array<int,array<string,mixed>> $matches
     */
    public static function decidingMatch(array $matches): ?array
    {
        $reset = self::find($matches, 'GF2');
        if ($reset && ($reset['active'] ?? false)) {
            return $reset;
        }
        $gf = self::find($matches, 'GF');
        if ($gf) {
            return $gf;
        }

        foreach ($matches as $m) {
            if (($m['bracket'] ?? '') === 'W' && !empty($m['is_final'])) {
                return $m;
            }
        }
        return null;
    }

    /**
     * Champion team id once the event is mathematically decided, else null.
     *
     * @param array<int,array<string,mixed>> $matches
     */
    public static function champion(array $matches, ?string $format): ?string
    {
        if (self::normalizeFormat($format) === self::FORMAT_ROUND_ROBIN) {
            if (!self::allPlayed($matches)) {
                return null;
            }
            $standings = self::standings($matches);
            return $standings[0]['team_id'] ?? null;
        }

        $deciding = self::decidingMatch($matches);
        if (!$deciding || !self::isRealTeam($deciding['winner'] ?? null)) {
            return null;
        }

        return (string)$deciding['winner'];
    }

    /**
     * Runner-up: whoever lost the match that decided the title.
     *
     * @param array<int,array<string,mixed>> $matches
     */
    public static function runnerUp(array $matches, ?string $format): ?string
    {
        if (self::normalizeFormat($format) === self::FORMAT_ROUND_ROBIN) {
            if (!self::allPlayed($matches)) {
                return null;
            }
            $standings = self::standings($matches);
            return $standings[1]['team_id'] ?? null;
        }

        $deciding = self::decidingMatch($matches);
        if (!$deciding || !self::isRealTeam($deciding['winner'] ?? null)) {
            return null;
        }
        return self::isRealTeam($deciding['loser'] ?? null) ? (string)$deciding['loser'] : null;
    }

    /** @param array<int,array<string,mixed>> $matches */
    public static function allPlayed(array $matches): bool
    {
        foreach ($matches as $m) {
            if (($m['active'] ?? true) === false) {
                continue;
            }
            if (in_array($m['status'] ?? '', ['bye', 'void'], true)) {
                continue;
            }
            if (empty($m['winner'])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Round-robin table, ordered by wins, then point differential, then points
     * scored, then head-to-head.
     *
     * @param array<int,array<string,mixed>> $matches
     * @return array<int,array<string,mixed>>
     */
    public static function standings(array $matches): array
    {
        $table = [];
        $ensure = function (string $teamId) use (&$table): void {
            if (!isset($table[$teamId])) {
                $table[$teamId] = [
                    'team_id' => $teamId,
                    'played' => 0,
                    'wins' => 0,
                    'losses' => 0,
                    'points_for' => 0,
                    'points_against' => 0,
                    'diff' => 0,
                ];
            }
        };

        $headToHead = [];

        foreach ($matches as $m) {
            $t1 = $m['team1'] ?? null;
            $t2 = $m['team2'] ?? null;
            if (!self::isRealTeam($t1) || !self::isRealTeam($t2)) {
                continue;
            }
            $ensure((string)$t1);
            $ensure((string)$t2);

            $winner = $m['winner'] ?? null;
            if (!self::isRealTeam($winner)) {
                continue;
            }
            $loser = ($winner === $t1) ? (string)$t2 : (string)$t1;

            $table[(string)$t1]['played']++;
            $table[(string)$t2]['played']++;
            $table[(string)$winner]['wins']++;
            $table[$loser]['losses']++;
            $headToHead[(string)$winner . '|' . $loser] = true;

            $s1 = $m['score1'] ?? null;
            $s2 = $m['score2'] ?? null;
            if ($s1 !== null && $s2 !== null) {
                $table[(string)$t1]['points_for'] += (int)$s1;
                $table[(string)$t1]['points_against'] += (int)$s2;
                $table[(string)$t2]['points_for'] += (int)$s2;
                $table[(string)$t2]['points_against'] += (int)$s1;
            }
        }

        foreach ($table as $id => $row) {
            $table[$id]['diff'] = $row['points_for'] - $row['points_against'];
        }

        $rows = array_values($table);
        usort($rows, function ($a, $b) use ($headToHead) {
            if ($a['wins'] !== $b['wins']) {
                return $b['wins'] <=> $a['wins'];
            }
            if ($a['diff'] !== $b['diff']) {
                return $b['diff'] <=> $a['diff'];
            }
            if ($a['points_for'] !== $b['points_for']) {
                return $b['points_for'] <=> $a['points_for'];
            }
            if (isset($headToHead[$a['team_id'] . '|' . $b['team_id']])) {
                return -1;
            }
            if (isset($headToHead[$b['team_id'] . '|' . $a['team_id']])) {
                return 1;
            }
            return strcmp((string)$a['team_id'], (string)$b['team_id']);
        });

        foreach ($rows as $i => $row) {
            $rows[$i]['rank'] = $i + 1;
        }

        return $rows;
    }

    /** @param array<int,array<string,mixed>> $matches */
    public static function progress(array $matches): array
    {
        $total = 0;
        $done = 0;
        foreach ($matches as $m) {
            if (($m['active'] ?? true) === false) {
                continue;
            }
            if (in_array($m['status'] ?? '', ['bye', 'void'], true)) {
                continue;
            }
            $total++;
            if (!empty($m['winner'])) {
                $done++;
            }
        }
        return [
            'total' => $total,
            'completed' => $done,
            'percent' => $total > 0 ? (int)round(($done / $total) * 100) : 0,
        ];
    }

    // --------------------------------------------------------------------------
    // Small helpers
    // --------------------------------------------------------------------------

    public static function isRealTeam(mixed $value): bool
    {
        return is_string($value) && $value !== '' && $value !== self::BYE;
    }

    /** @param array<int,array<string,mixed>> $matches */
    private static function indexOf(array $matches, string $matchId): ?int
    {
        foreach ($matches as $idx => $m) {
            if (($m['id'] ?? '') === $matchId) {
                return (int)$idx;
            }
        }
        return null;
    }

    /** @param array<int,array<string,mixed>> $matches */
    public static function find(array $matches, string $matchId): ?array
    {
        $idx = self::indexOf($matches, $matchId);
        return $idx === null ? null : $matches[$idx];
    }
}
