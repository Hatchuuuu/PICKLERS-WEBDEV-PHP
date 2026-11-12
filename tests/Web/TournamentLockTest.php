<?php
declare(strict_types=1);

namespace Picklers\Tests\Web;

use Doctrine\DBAL\DriverManager;
use Picklers\Services\TournamentService;
use Picklers\Tests\Admin\AdminWebTestCase;

final class TournamentLockTest extends AdminWebTestCase
{
    private function request(array $params, string $uri = '/api.php'): void
    {
        $_SESSION['_sf2_attributes']['_csrf/picklers'] = 'tournament-lock-test';
        $action = $params['action'];
        unset($params['action']);
        $this->client->request('POST', $uri . '?action=' . $action, $params, [], [
            'HTTP_X_CSRF_TOKEN' => 'tournament-lock-test',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);
    }

    public function testLegacyCreateContentionIsRetryable(): void
    {
        $this->signIn('usr_fx_owner1');
        $holder = DriverManager::getConnection($this->db()->getParams());
        $lock = hash('sha256', 'picklers_tournaments:' . $holder->getDatabase());
        $before = $this->db()->fetchAllAssociative('SELECT * FROM tournaments ORDER BY seq');
        try {
            self::assertSame(1, (int)$holder->fetchOne('SELECT GET_LOCK(?, 0)', [$lock]));
            $this->request(['action' => 'create_tournament', 'title' => 'Legacy lock'], '/owner.php');
            self::assertSame(503, $this->client->getResponse()->getStatusCode());
            $json = json_decode((string)$this->client->getResponse()->getContent(), true);
            self::assertSame('Tournament storage is busy. Please try again.', $json['message']);
            self::assertSame($before, $this->db()->fetchAllAssociative('SELECT * FROM tournaments ORDER BY seq'));
        } finally {
            $holder->executeQuery('SELECT RELEASE_LOCK(?)', [$lock]);
            $holder->close();
        }
        $this->request(['action' => 'create_tournament', 'title' => 'Legacy retry'], '/owner.php');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $json = json_decode((string)$this->client->getResponse()->getContent(), true);
        static::getContainer()->get(TournamentService::class)->delete($json['tournament']['id']);
    }

    public function testApiContentionIs503AndScopeErrorsRemainDistinct(): void
    {
        $svc = static::getContainer()->get(TournamentService::class);
        $facility = (string)$this->db()->fetchOne('SELECT id FROM facilities WHERE owner_id = ? LIMIT 1', ['usr_fx_owner1']);
        $id = $svc->create(['title' => 'HTTP lock', 'facility_id' => $facility])['tournament']['id'];
        $holder = DriverManager::getConnection($this->db()->getParams());
        $lock = hash('sha256', 'picklers_tournaments:' . $holder->getDatabase());
        try {
            self::assertSame(1, (int)$holder->fetchOne('SELECT GET_LOCK(?, 0)', [$lock]));
            $before = $this->db()->fetchAllAssociative('SELECT * FROM tournaments ORDER BY seq');
            $this->signIn('usr_fx_owner1');
            foreach (['create_tournament', 'update_tournament', 'delete_tournament', 'mix_tournament_teams'] as $action) {
                $this->request(['action' => $action, 'facility_id' => $facility, 'tournament_id' => $id, 'title' => 'Locked HTTP']);
                self::assertSame(503, $this->client->getResponse()->getStatusCode(), $action);
                $json = json_decode((string)$this->client->getResponse()->getContent(), true);
                self::assertFalse($json['success']);
                self::assertSame('Tournament storage is busy. Please try again.', $json['message']);
                self::assertSame($before, $this->db()->fetchAllAssociative('SELECT * FROM tournaments ORDER BY seq'));
            }
            $this->request(['action' => 'update_tournament', 'tournament_id' => 'missing_lock_test']);
            self::assertSame(404, $this->client->getResponse()->getStatusCode());
            $this->signIn('usr_fx_owner2');
            $this->request(['action' => 'update_tournament', 'tournament_id' => $id]);
            self::assertSame(404, $this->client->getResponse()->getStatusCode());
            $this->signIn('usr_fx_p1');
            $this->request(['action' => 'update_tournament', 'tournament_id' => $id]);
            self::assertSame(403, $this->client->getResponse()->getStatusCode());
        } finally {
            $holder->executeQuery('SELECT RELEASE_LOCK(?)', [$lock]);
            $holder->close();
            $svc->delete($id);
        }
        $this->signIn('usr_fx_owner1');
        $this->request(['action' => 'create_tournament', 'facility_id' => $facility, 'title' => 'Retried HTTP']);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $json = json_decode((string)$this->client->getResponse()->getContent(), true);
        self::assertSame(1, (int)$this->db()->fetchOne('SELECT COUNT(*) FROM tournaments WHERE id = ?', [$json['tournament']['id']]));
        $svc->delete($json['tournament']['id']);
    }

    public function testAllWritePathsRejectContentionAndRetryOnce(): void
    {
        $svc = static::getContainer()->get(TournamentService::class);
        $facility = (string)$this->db()->fetchOne('SELECT id FROM facilities WHERE owner_id = ? LIMIT 1', ['usr_fx_owner1']);
        $data = ['title' => 'Lock regression', 'facility_id' => $facility, 'owner_id' => 'usr_fx_owner1'];
        $id = $svc->create($data)['tournament']['id'];
        $holder = DriverManager::getConnection($this->db()->getParams());
        $lock = hash('sha256', 'picklers_tournaments:' . $holder->getDatabase());
        $failures = [];
        try {
            foreach (['create', 'update', 'delete'] as $operation) {
                self::assertSame(1, (int)$holder->fetchOne('SELECT GET_LOCK(?, 0)', [$lock]));
                $before = $this->db()->fetchAllAssociative('SELECT * FROM tournaments ORDER BY seq');
                $failure = null;
                try {
                    match ($operation) {
                        'create' => $svc->create($data),
                        'update' => $svc->update($id, ['title' => 'Retried title']),
                        'delete' => $svc->delete($id),
                    };
                } catch (\RuntimeException $e) {
                    $failure = $e->getMessage();
                } finally {
                    $holder->executeQuery('SELECT RELEASE_LOCK(?)', [$lock]);
                }
                $failures[$operation] = $failure;
                self::assertSame($before, $this->db()->fetchAllAssociative('SELECT * FROM tournaments ORDER BY seq'));
                if ($operation === 'create') {
                    $retry = $svc->create($data);
                    self::assertNull($retry['error']);
                    self::assertSame(count($before) + 1, (int)$this->db()->fetchOne('SELECT COUNT(*) FROM tournaments'));
                    $svc->delete($retry['tournament']['id']);
                } elseif ($operation === 'update') {
                    self::assertNull($svc->update($id, ['title' => 'Retried title'])['error']);
                    self::assertSame('Retried title', $svc->find($id)['title']);
                } else {
                    self::assertTrue($svc->delete($id));
                    self::assertNull($svc->find($id));
                }
            }
            self::assertSame('Tournament not found.', $svc->update($id, ['title' => 'Missing'])['error']);
            self::assertFalse($svc->delete($id));
            self::assertSame(array_fill_keys(['create', 'update', 'delete'], 'Tournament storage is busy. Please try again.'), $failures);
        } finally {
            $holder->executeQuery('SELECT RELEASE_LOCK(?)', [$lock]);
            $holder->close();
            $svc->delete($id);
        }
    }

    public function testSinglesEditUsesAddNormalizationAndPreservesMetadata(): void
    {
        $svc = static::getContainer()->get(TournamentService::class);
        $id = $svc->create(['title' => 'Singles normalization', 'category' => 'Singles'])['tournament']['id'];
        try {
            $added = $svc->addEntrant($id, ['name' => 'Solo', 'player1' => 'Alice', 'player2' => 'Discarded', 'player1_id' => 'participant1', 'player2_id' => 'participant2']);
            $team = $added['tournament']['teams'][0];
            self::assertSame('', $team['player2']);
            $edited = $svc->updateTeam($id, $team['id'], ['name' => 'Renamed', 'player2' => 'Ignored partner']);
            self::assertNull($edited['error']);
            self::assertSame('', $svc->find($id)['teams'][0]['player2']);
            foreach (['id', 'seed', 'player1_id', 'player2_id', 'source', 'created_at'] as $key) {
                self::assertSame($team[$key], $edited['tournament']['teams'][0][$key], $key);
            }
            self::assertSame('Renamed', $edited['tournament']['teams'][0]['name']);
        } finally {
            $svc->delete($id);
        }
    }
}
