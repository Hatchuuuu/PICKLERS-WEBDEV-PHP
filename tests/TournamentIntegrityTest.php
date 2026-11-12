<?php
declare(strict_types=1);

namespace Picklers\Tests;

use Picklers\Services\TournamentService;

/**
 * Tournament persistence and bracket-correction integrity.
 *
 * Regression origins: create() and delete() called a persist() method that
 * never existed (both were fatal errors), and correcting the winners-bracket
 * final left a stale, still-active championship reset deciding the champion.
 */
final class TournamentIntegrityTest extends TestCase {

    public function run(): void {
        $svc = $this->service(TournamentService::class);
        $tag = bin2hex(random_bytes(3));
        $this->testUpdateIntegrity($svc, $tag);
        $this->testRosterCategoryIntegrity($svc, $tag);

        $created = $svc->create([
            'facility_id' => 'fac_test_' . $tag,
            'owner_id' => 'usr_test_' . $tag,
            'title' => 'Integrity Cup ' . $tag,
            'format' => 'double_elimination',
            'max_teams' => 4,
            'date' => date('Y-m-d', strtotime('+7 days')),
        ]);
        $this->assertNull($created['error'], 'REGRESSION: creating a tournament succeeds: ' . ($created['error'] ?? ''));
        $id = (string)($created['tournament']['id'] ?? '');
        $this->assertNotNull($svc->find($id), 'The created tournament is persisted and findable');

        $badDate = $svc->create(['title' => 'Bad Date ' . $tag, 'date' => 'not a date']);
        $this->assertSame('Please enter a valid date.', $badDate['error'], 'An unparseable date is rejected');

        $a = $svc->addEntrant($id, ['player1' => 'Ana Cruz', 'player2' => 'Ben Lim']);
        $b = $svc->addEntrant($id, ['player1' => 'Cara Diaz', 'player2' => 'Dan Uy']);
        $this->assertNull($b['error'], 'Two fixed teams register');
        $teams = $b['tournament']['teams'];
        $teamA = (string)$teams[0]['id'];
        $teamB = (string)$teams[1]['id'];

        $gen = $svc->generateBracket($id);
        $this->assertNull($gen['error'], 'A two-team double-elimination bracket generates');

        $svc->reportMatch($id, 'W1-1', $teamA);
        $svc->reportMatch($id, 'GF', $teamB);         // losers-side team wins: reset activates
        $decided = $svc->reportMatch($id, 'GF2', $teamB);
        $this->assertSame($teamB, $decided['tournament']['champion_team_id'], 'The reset match decides the champion');

        // Correct the winners final: the grand final is vacated, so the old reset must not survive.
        $corrected = $svc->reportMatch($id, 'W1-1', $teamB);
        $this->assertNull($corrected['error'], 'Correcting an earlier result is accepted');
        $reset = null;
        foreach ($corrected['tournament']['matches'] as $m) {
            if ($m['id'] === 'GF2') { $reset = $m; }
        }
        $this->assertFalse((bool)($reset['active'] ?? true), 'REGRESSION: the stale championship reset is retired');
        $this->assertNull($corrected['tournament']['champion_team_id'], 'REGRESSION: no champion until the new grand final is played');
        $this->assertSame('ongoing', $corrected['tournament']['status'], 'The event returns to ongoing');

        $this->assertTrue($svc->delete($id), 'REGRESSION: deleting a tournament succeeds');
        $this->assertNull($svc->find($id), 'The deleted tournament is gone');
    }

    private function testUpdateIntegrity(TournamentService $svc, string $tag): void {
        $created = $svc->create(['title' => 'Edit Cup ' . $tag, 'category' => 'Doubles', 'max_teams' => 4]);
        $id = $created['tournament']['id'];
        $svc->addEntrant($id, ['name' => 'Alpha', 'player1' => 'Alice', 'player2' => 'Bob']);
        $added = $svc->addEntrant($id, ['name' => 'Beta', 'player1' => 'Cara', 'player2' => 'Dan']);
        $teamId = $added['tournament']['teams'][0]['id'];
        foreach ([['player1' => ' '], ['player2' => ' '], ['player2' => 'ALICE'], ['player1' => 'cara'], ['player2' => 'DAN'], ['name' => 'beta']] as $patch) {
            $before = $svc->find($id);
            $result = $svc->updateTeam($id, $teamId, $patch);
            $this->assertNotNull($result['error'], 'Invalid participant edit is rejected: ' . json_encode($patch));
            $this->assertSame($before, $svc->find($id), 'Rejected participant edit persists nothing');
            // Restore the fixture when running against the unfixed service.
            $svc->updateTeam($id, $teamId, ['player1' => 'Alice', 'player2' => 'Bob']);
        }
        $edited = $svc->updateTeam($id, $teamId, ['name' => 'Renamed', 'player1' => 'Alex']);
        $this->assertNull($edited['error'], 'Valid team rename and participant edit succeeds');
        $this->assertSame('Alex', $svc->find($id)['teams'][0]['player1'], 'Valid participant edit persists');
        $this->assertSame($teamId, $edited['tournament']['teams'][0]['id'], 'Team edit preserves its bracket identity');
        $this->assertSame(1, $edited['tournament']['teams'][0]['seed'], 'Participant edit preserves its seed');
        $svc->generateBracket($id);
        $renamed = $svc->updateTeam($id, $teamId, ['name' => 'Live rename']);
        $this->assertNull($renamed['error'], 'Live team rename remains supported');
        $this->assertNull($svc->updateTeam($id, $teamId, ['player1' => 'Alicia'])['error'], 'Valid live participant edit retains the existing policy');
        $svc->delete($id);

        $created = $svc->create(['title' => 'Mix Cup ' . $tag, 'category' => 'Doubles', 'pairing_mode' => 'mix', 'max_teams' => 8]);
        $id = $created['tournament']['id'];
        for ($i = 1; $i <= 8; $i++) { $svc->addEntrant($id, ['name' => 'Pool ' . $i]); }
        $before = $svc->find($id);
        $small = $svc->update($id, ['max_teams' => 2, 'title' => 'Must not persist']);
        $this->assertNotNull($small['error'], 'Eight pool players cannot fit a two-team maximum');
        $this->assertSame($before, $svc->find($id), 'Rejected capacity edit persists nothing');
        $this->assertNull($svc->update($id, ['max_teams' => 4])['error'], 'Eight pool players fit four teams');
        $draw = $svc->mixDraw($id);
        $this->assertSame(4, count($draw['tournament']['teams']), 'All eight entrants appear in four drawn teams');
        $this->assertSame(8, count($draw['tournament']['players_pool']), 'Draw retains all eight visible pool entrants');
        $this->assertNotNull($svc->update($id, ['max_teams' => 2])['error'], 'Existing fixed-team capacity floor remains enforced');
        $svc->generateBracket($id);
        $teamId = $svc->find($id)['teams'][0]['id'];
        $scored = $svc->reportMatch($id, 'W1-1', $teamId, 11, 5);
        $this->assertNull($scored['error'], 'Mix bracket accepts a scored result before the shape edit');
        $before = $svc->find($id);
        $shape = $svc->update($id, ['category' => 'Singles', 'pairing_mode' => 'fixed', 'title' => 'Must not persist']);
        $this->assertNotNull($shape['error'], 'Live Doubles/mix bracket cannot change to Singles/fixed');
        $this->assertSame($before, $svc->find($id), 'Rejected shape edit preserves roster, bracket and scores');
        $svc->resetBracket($id);
        $before = $svc->find($id);
        $changed = $svc->update($id, ['category' => 'Singles', 'pairing_mode' => 'fixed']);
        $this->assertNotNull($changed['error'], 'Resetting a bracket does not make paired entrants compatible with Singles');
        $this->assertSame($before, $svc->find($id), 'Rejected post-reset transition preserves the draw pool and teams');
        $svc->delete($id);
    }

    private function testRosterCategoryIntegrity(TournamentService $svc, string $tag): void {
        $db = $this->service(\Doctrine\DBAL\Connection::class);
        foreach ([['Singles', 'Doubles'], ['Doubles', 'Singles']] as [$category, $target]) {
            $created = $svc->create(['title' => 'Roster Shape ' . $tag, 'category' => $category]);
            $id = $created['tournament']['id'];
            try {
                foreach ([['Alice', 'Bob'], ['Cara', 'Dan']] as [$p1, $p2]) {
                    $svc->addEntrant($id, ['player1' => $p1, 'player2' => $p2]);
                }
                $before = $svc->find($id);
                $changed = $svc->update($id, ['category' => $target, 'title' => 'Must not persist']);
                $this->assertNotNull($changed['error'], $category . ' roster rejects a pre-draw change to ' . $target);
                $this->assertSame($before, $svc->find($id), 'Rejected category edit persists no title or roster changes');
                if ($category === 'Doubles') {
                    $this->assertNull($svc->update($id, ['category' => 'Mixed'])['error'], 'Fixed Doubles roster supports Mixed category');
                    $this->assertSame($before['teams'], $svc->find($id)['teams'], 'Fixed same-shape edit preserves team metadata');
                }
                $this->assertNull($svc->generateBracket($id)['error'], 'Compatible ' . $category . ' roster still draws');
                $svc->resetBracket($id);

                // Simulate a historical invalid record without going through update validation.
                $raw = json_decode($db->fetchOne('SELECT data FROM tournaments WHERE id = ?', [$id]), true);
                $raw['category'] = $target;
                $db->executeStatement('UPDATE tournaments SET data = ? WHERE id = ?', [json_encode($raw), $id]);
                $before = $svc->find($id);
                $draw = $svc->generateBracket($id, true);
                $this->assertNotNull($draw['error'], 'Historical incompatible ' . $target . ' roster cannot draw a bracket');
                $this->assertSame($before, $svc->find($id), 'Rejected historical draw preserves roster, seeds and status');
            } finally {
                $svc->delete($id);
            }
        }

        $created = $svc->create(['title' => 'Empty Shape ' . $tag, 'category' => 'Singles']);
        $id = $created['tournament']['id'];
        try {
            $this->assertNull($svc->update($id, ['category' => 'Doubles', 'pairing_mode' => 'mix'])['error'], 'Empty roster can change category and pairing');
            $this->assertNull($svc->update($id, ['category' => 'Singles'])['error'], 'Empty paired category can return to Singles');
            $this->assertSame('fixed', $svc->find($id)['pairing_mode'], 'Singles continues to normalize pairing mode to fixed');
            $svc->update($id, ['category' => 'Doubles', 'pairing_mode' => 'mix']);
            for ($i = 1; $i <= 5; $i++) { $svc->addEntrant($id, ['name' => 'Pool ' . $i]); }
            $before = $svc->find($id);
            $this->assertNotNull($svc->update($id, ['category' => 'Singles'])['error'], 'Undrawn mix pool cannot become Singles');
            $this->assertSame($before, $svc->find($id), 'Rejected pool transition keeps all registered players');
            $this->assertNull($svc->update($id, ['category' => 'Mixed'])['error'], 'Doubles mix pool supports same-shape Mixed category');
            $this->assertSame($before['players_pool'], $svc->find($id)['players_pool'], 'Same-shape pool transition preserves participant metadata');
            $draw = $svc->mixDraw($id);
            $this->assertNull($draw['error'], 'Same-shape mix pool still draws pairs');
            $this->assertSame(1, count($draw['tournament']['unpaired_players']), 'Odd mix pool retains its unpaired participant');
            $teams = $draw['tournament']['teams'];
            $this->assertNull($svc->update($id, ['category' => 'Doubles'])['error'], 'Populated paired roster supports Mixed to Doubles');
            $this->assertSame($teams, $svc->find($id)['teams'], 'Same-shape transition preserves team IDs, seeds and metadata');
            $this->assertNull($svc->generateBracket($id)['error'], 'Valid drawn pairs can generate their bracket');
        } finally {
            $svc->delete($id);
        }
    }
}
