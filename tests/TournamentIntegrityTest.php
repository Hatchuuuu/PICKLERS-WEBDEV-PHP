<?php
declare(strict_types=1);

namespace Picklers\Tests;

use Picklers\Core\Database;
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
        $svc = new TournamentService(Database::get());
        $tag = bin2hex(random_bytes(3));

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
}
