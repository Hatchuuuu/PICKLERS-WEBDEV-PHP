<?php
declare(strict_types=1);

namespace Picklers\Tests\Web;

use Picklers\Tests\Admin\AdminWebTestCase;

final class CourtWriteConcurrencyTest extends AdminWebTestCase
{
    private function job(string $action, array $params, string $user = 'usr_fx_owner1'): array
    {
        return compact('action', 'params', 'user');
    }

    public function testExplicitAutoAddRenameAndRenameRenameSerialize(): void
    {
        $facility = (string) $this->scalar("SELECT id FROM facilities WHERE owner_id = 'usr_fx_owner1' LIMIT 1");
        // Reproduce a stale map left by the previous version of this test deterministically.
        $this->db()->executeStatement("INSERT IGNORE INTO court_attribute_map (court_id, attribute_id) SELECT 'race_a', id FROM court_attributes WHERE slug='covered'");
        foreach (['explicit', 'auto', 'addRename', 'renameRename'] as $case) {
            // The map has no foreign key, so deleting/reusing a court ID must clear its tags explicitly.
            $this->db()->executeStatement("DELETE FROM court_attribute_map WHERE court_id IN ('race_a','race_b') OR court_id IN (SELECT id FROM courts WHERE facility_id = ? AND name IN ('Court 90','Court 91','Court 92'))", [$facility]);
            $this->db()->executeStatement("DELETE FROM courts WHERE facility_id = ? AND name IN ('Court 90','Court 91','Court 92')", [$facility]);
            $originalCourtIds = $this->db()->fetchFirstColumn('SELECT id FROM courts WHERE facility_id = ?', [$facility]);
            try {
                $this->db()->insert('courts', ['id' => 'race_a', 'facility_id' => $facility, 'name' => 'Court 90', 'price' => 200]);
                $this->db()->insert('courts', ['id' => 'race_b', 'facility_id' => $facility, 'name' => 'Court 91', 'price' => 250]);
                self::assertSame([], $this->db()->fetchFirstColumn("SELECT attribute_id FROM court_attribute_map WHERE court_id IN ('race_a','race_b')"), 'Each race starts with its intended empty tag baseline');
                $add = $this->job('add_court', ['facility_id' => $facility, 'name' => 'Court 92', 'rate' => 600, 'attributes' => ['outdoor']]);
                $renameA = $this->job('edit_court', ['court_id' => 'race_a', 'name' => 'Court 92', 'surface' => 'Race A', 'rate' => 650, 'attributes' => ['covered']]);
                $renameB = $this->job('edit_court', ['court_id' => 'race_b', 'name' => 'Court 92', 'surface' => 'Race B', 'rate' => 700, 'attributes' => ['indoor']]);
                $jobs = match ($case) {
                    'explicit' => [$add, $add],
                    'auto' => [$this->job('add_court', ['facility_id' => $facility, 'name' => '']), $this->job('add_court', ['facility_id' => $facility, 'name' => ''])],
                    'addRename' => [$add, $renameA],
                    default => [$renameA, $renameB],
                };
                $result = $this->race($jobs, [$facility]);
                $statuses = array_column($result, 'status');
                sort($statuses);
                self::assertSame($case === 'auto' ? [200, 200] : [200, 409], $statuses, $case . ': ' . json_encode($result));
                $names = $this->db()->fetchFirstColumn('SELECT name FROM courts WHERE facility_id = ?', [$facility]);
                self::assertCount(count(array_unique($names)), $names, $case . ' leaves no duplicate names');
                if ($case === 'auto') {
                    self::assertNotSame($result[0]['body']['court']['name'], $result[1]['body']['court']['name']);
                } else {
                    $winner = array_values(array_filter($result, fn($r) => $r['status'] === 200))[0]['body']['court'];
                    $stored = $this->db()->fetchAssociative('SELECT * FROM courts WHERE id = ?', [$winner['id']]);
                    self::assertSame((float) $winner['price'], (float) $stored['price']);
                    self::assertSame($winner['surface'], $stored['surface']);
                    self::assertSame($winner['attribute_slugs'], $this->db()->fetchFirstColumn('SELECT a.slug FROM court_attribute_map m JOIN court_attributes a ON a.id=m.attribute_id WHERE m.court_id=?', [$winner['id']]));
                    $loser = array_search(409, array_column($result, 'status'), true);
                    if ($jobs[$loser]['action'] === 'edit_court') {
                        $unchanged = $this->db()->fetchAssociative('SELECT * FROM courts WHERE id=?', [$jobs[$loser]['params']['court_id']]);
                        self::assertSame($jobs[$loser]['params']['court_id'] === 'race_a' ? 'Court 90' : 'Court 91', $unchanged['name']);
                        self::assertSame($jobs[$loser]['params']['court_id'] === 'race_a' ? 200.0 : 250.0, (float) $unchanged['price']);
                        self::assertSame([], $this->db()->fetchFirstColumn('SELECT attribute_id FROM court_attribute_map WHERE court_id=?', [$unchanged['id']]));
                    }
                    self::assertStringContainsString('already exists', $result[$loser]['body']['message']);
                }
            } finally {
                $createdCourtIds = array_diff($this->db()->fetchFirstColumn('SELECT id FROM courts WHERE facility_id = ?', [$facility]), $originalCourtIds);
                foreach (array_unique(array_merge(['race_a', 'race_b'], $createdCourtIds)) as $courtId) {
                    $this->db()->delete('court_attribute_map', ['court_id' => $courtId]);
                    $this->db()->delete('courts', ['id' => $courtId]);
                }
            }
        }
    }

    public function testDistinctVenuesAreIndependentAndStaffRetainScope(): void
    {
        $first = (string) $this->scalar("SELECT id FROM facilities WHERE owner_id='usr_fx_owner1' LIMIT 1");
        $second = (string) $this->scalar("SELECT id FROM facilities WHERE owner_id='usr_fx_owner2' LIMIT 1");
        $this->db()->executeStatement("DELETE FROM staff WHERE user_id='usr_fx_p1'");
        $this->db()->insert('staff', ['id' => 'race_staff', 'facility_id' => $first, 'user_id' => 'usr_fx_p1', 'name' => 'Staff', 'role' => 'Front Desk', 'status' => 'Active']);
        try {
            $result = $this->race([$this->job('add_court', ['facility_id' => $first, 'name' => 'Court 92'], 'usr_fx_p1'), $this->job('add_court', ['facility_id' => $second, 'name' => 'Court 92'], 'usr_fx_owner2')], [$first], true);
            self::assertSame([200, 200], array_column($result, 'status'));
            foreach ($result as $i => $saved) {
                self::assertSame($i === 0 ? $first : $second, (string) $saved['body']['court']['facility_id']);
            }
            $this->signIn('usr_fx_p1');
            self::assertSame(403, $this->write('add_court', ['facility_id' => $second, 'name' => 'Court 93'])[0]);
            self::assertSame(403, $this->write('edit_court', ['court_id' => $result[1]['body']['court']['id'], 'name' => 'Court 93'])[0]);
        } finally {
            $this->db()->delete('staff', ['id' => 'race_staff']);
            $this->db()->executeStatement("DELETE FROM courts WHERE facility_id IN (?,?) AND name='Court 92'", [$first, $second]);
        }
    }

    public function testAddAttributeFailureDoesNotLeaveACourtOrSyncChange(): void
    {
        $facility = (string)$this->scalar("SELECT id FROM facilities WHERE owner_id='usr_fx_owner1' LIMIT 1");
        $this->signIn('usr_fx_owner1');
        $sync = $this->db()->fetchAllAssociative('SELECT * FROM sync_versions ORDER BY channel');
        $this->db()->executeStatement("CREATE TRIGGER court_attribute_failure BEFORE INSERT ON court_attribute_map FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='forced attribute failure'");
        try {
            self::assertSame(500, $this->write('add_court', ['facility_id' => $facility, 'name' => 'Court 93', 'attributes' => ['indoor']])[0]);
            self::assertFalse($this->db()->fetchOne("SELECT id FROM courts WHERE facility_id=? AND name='Court 93'", [$facility]));
            self::assertSame($sync, $this->db()->fetchAllAssociative('SELECT * FROM sync_versions ORDER BY channel'));
        } finally {
            $this->db()->executeStatement('DROP TRIGGER IF EXISTS court_attribute_failure');
            $this->db()->executeStatement("DELETE FROM courts WHERE facility_id=? AND name='Court 93'", [$facility]);
        }
        [$status, $saved] = $this->write('add_court', ['facility_id' => $facility, 'name' => 'Court 93', 'rate' => 750, 'attributes' => ['indoor']]);
        self::assertSame(200, $status);
        $stored = $this->db()->fetchAssociative('SELECT * FROM courts WHERE id=?', [$saved['court']['id']]);
        self::assertSame('Court 93', $stored['name']);
        self::assertSame(750.0, (float)$stored['price']);
        self::assertSame(['indoor'], $this->db()->fetchFirstColumn('SELECT a.slug FROM court_attribute_map m JOIN court_attributes a ON a.id=m.attribute_id WHERE m.court_id=?', [$stored['id']]));
        $this->db()->delete('courts', ['id' => $stored['id']]);
    }

    public function testEditAttributeFailureRollsBackFieldsTagsAndSyncAndRetryClearsTags(): void
    {
        $facility = (string) $this->scalar("SELECT id FROM facilities WHERE owner_id='usr_fx_owner1' LIMIT 1");
        $this->signIn('usr_fx_owner1');
        [$status, $saved] = $this->write('add_court', ['facility_id' => $facility, 'name' => 'Court 92', 'rate' => 500, 'attributes' => ['outdoor', 'covered']]);
        self::assertSame(200, $status);
        $id = $saved['court']['id'];
        $row = $this->db()->fetchAssociative('SELECT * FROM courts WHERE id=?', [$id]);
        $tags = $this->db()->fetchAllAssociative('SELECT * FROM court_attribute_map WHERE court_id=? ORDER BY attribute_id', [$id]);
        $sync = $this->db()->fetchAllAssociative('SELECT * FROM sync_versions ORDER BY channel');
        $this->db()->executeStatement("CREATE TRIGGER court_attribute_failure BEFORE INSERT ON court_attribute_map FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='forced attribute failure'");
        try {
            self::assertSame(500, $this->write('edit_court', ['court_id' => $id, 'name' => 'Court 94', 'surface' => 'Changed', 'rate' => 700, 'attributes' => ['indoor']])[0]);
            self::assertSame($row, $this->db()->fetchAssociative('SELECT * FROM courts WHERE id=?', [$id]));
            self::assertSame($tags, $this->db()->fetchAllAssociative('SELECT * FROM court_attribute_map WHERE court_id=? ORDER BY attribute_id', [$id]));
            self::assertSame($sync, $this->db()->fetchAllAssociative('SELECT * FROM sync_versions ORDER BY channel'));
        } finally {
            $this->db()->executeStatement('DROP TRIGGER IF EXISTS court_attribute_failure');
        }
        self::assertSame(200, $this->write('edit_court', ['court_id' => $id, 'name' => 'Court 94', 'surface' => 'Changed', 'rate' => 700, 'attributes' => ['indoor', 'unknown-slug']])[0]);
        $retried = $this->db()->fetchAssociative('SELECT * FROM courts WHERE id=?', [$id]);
        self::assertSame('Court 94', $retried['name']);
        self::assertSame('Changed', $retried['surface']);
        self::assertSame(700.0, (float) $retried['price']);
        self::assertSame(['indoor'], $this->db()->fetchFirstColumn('SELECT a.slug FROM court_attribute_map m JOIN court_attributes a ON a.id=m.attribute_id WHERE m.court_id=?', [$id]));
        self::assertSame(200, $this->write('edit_court', ['court_id' => $id, 'name' => 'Court 94', 'surface' => 'Changed', 'rate' => 700, 'attributes' => []])[0]);
        self::assertSame([], $this->db()->fetchAllAssociative('SELECT * FROM court_attribute_map WHERE court_id=?', [$id]));
        $this->db()->delete('courts', ['id' => $id]);
    }

    public function testRepositoryWritersRejectNormalizedDuplicatesAndJoinTheCallerTransaction(): void
    {
        $repository = static::getContainer()->get(\Picklers\Repositories\FacilityRepository::class);
        $facility = (string)$this->scalar("SELECT id FROM facilities WHERE owner_id='usr_fx_owner1' LIMIT 1");
        $first = $repository->insertCourt(['facility_id' => $facility, 'name' => 'Court 90']);
        $second = $repository->insertCourt(['facility_id' => $facility, 'name' => 'Court 91']);
        try {
            foreach ([
                fn() => $repository->insertCourt(['facility_id' => $facility, 'name' => 'Court 90 - duplicate']),
                fn() => $repository->updateCourt($second['id'], ['name' => 'Court 90 - duplicate']),
            ] as $write) {
                try {
                    $write();
                    self::fail('The shared writer must reject a normalized duplicate');
                } catch (\DomainException $e) {
                    self::assertStringContainsString('already exists', $e->getMessage());
                }
            }
            self::assertFalse($this->db()->isTransactionActive(), 'A rejected standalone write releases its transaction');
            $this->db()->beginTransaction();
            try {
                $repository->updateCourt($first['id'], ['surface' => 'Temporary']);
                $repository->setCourtAttributes($first['id'], ['indoor']);
                self::assertTrue($this->db()->isTransactionActive());
            } finally {
                $this->db()->rollBack();
            }
            self::assertSame('Hard Court', $repository->getCourtById($first['id'])['surface']);
            self::assertSame([], $repository->getCourtAttributes($first['id']));
        } finally {
            $this->db()->delete('courts', ['id' => $first['id']]);
            $this->db()->delete('courts', ['id' => $second['id']]);
        }
    }

    public function testLegacyNameOnlyReservationStillPreventsARename(): void
    {
        $facility = (string)$this->scalar("SELECT id FROM facilities WHERE owner_id='usr_fx_owner1' LIMIT 1");
        $court = 'crt_' . $facility . '_1';
        $day = date('Y-m-d', strtotime('+10 days'));
        $this->db()->insert('bookings', [
            'id' => 'FX-COURT-RENAME', 'user_id' => 'usr_fx_p1', 'facility_id' => $facility,
            'court_id' => null, 'court_name' => 'Court 1', 'date' => $day, 'booking_date' => $day,
            'time' => '9:00 AM - 10:00 AM', 'start_min' => 540, 'end_min' => 600,
            'duration' => 1, 'price' => 400, 'payment_method' => 'GCash', 'status' => 'confirmed',
        ]);
        $before = $this->db()->fetchAssociative('SELECT * FROM courts WHERE id=?', [$court]);
        $this->signIn('usr_fx_owner1');
        [$status, $body] = $this->write('edit_court', ['court_id' => $court, 'name' => 'Court 93', 'surface' => 'Changed', 'rate' => 750]);
        self::assertSame(409, $status);
        self::assertStringContainsString('upcoming bookings', $body['message']);
        self::assertSame($before, $this->db()->fetchAssociative('SELECT * FROM courts WHERE id=?', [$court]));
    }

    private function write(string $action, array $params): array
    {
        $_SESSION['_sf2_attributes']['_csrf/picklers'] = 'court-write';
        $this->client->request('POST', '/owner.php?action=' . $action, $params, [], ['HTTP_X_CSRF_TOKEN' => 'court-write', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        return [$this->client->getResponse()->getStatusCode(), json_decode((string) $this->client->getResponse()->getContent(), true)];
    }

    private function race(array $jobs, array $facilities, bool $independent = false): array
    {
        $lock = self::pdo();
        $lock->beginTransaction();
        foreach ($facilities as $facility) {
            $s = $lock->prepare('SELECT id FROM facilities WHERE id=? FOR UPDATE');
            $s->execute([$facility]);
        }
        $procs = $pipes = $ready = [];
        try {
            foreach ($jobs as $i => $job) {
                $procs[$i] = proc_open([PHP_BINARY, __DIR__ . '/court-write-worker.php', base64_encode(json_encode($job))], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i], dirname(__DIR__, 2), ['PICKLERS_SKIP_DB_REBUILD' => '1'] + getenv());
                self::assertIsResource($procs[$i]);
                stream_set_timeout($pipes[$i][1], 15);
                $ready[$i] = json_decode(trim((string) fgets($pipes[$i][1])), true);
                self::assertIsArray($ready[$i]);
            }
            self::assertNotSame($ready[0]['connection'], $ready[1]['connection']);
            self::assertNotSame($ready[0]['session'], $ready[1]['session']);
            foreach ($pipes as $p) {
                fwrite($p[0], "go\n");
                fflush($p[0]);
            }
            $deadline = microtime(true) + 8;
            $waiting = 0;
            do {
                $waiting = (int) $this->scalar("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID IN (?,?) AND COMMAND='Query' AND INFO LIKE 'SELECT id FROM facilities WHERE id = % FOR UPDATE'", [$ready[0]['connection'], $ready[1]['connection']]);
                if ($waiting === ($independent ? 1 : 2)) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            self::assertSame($independent ? 1 : 2, $waiting, 'Requests overlap at the actual facility lock');
            if ($independent) {
                $other = json_decode(trim((string) fgets($pipes[1][1])), true);
                self::assertSame(200, $other['status'] ?? null, 'Other venue commits while the first venue stays locked');
            }
            $lock->commit();
            $results = [];
            foreach ($procs as $i => $proc) {
                $out = stream_get_contents($pipes[$i][1]);
                $err = stream_get_contents($pipes[$i][2]);
                $exit = proc_close($proc);
                unset($procs[$i]);
                self::assertSame(0, $exit, $err);
                $results[$i] = $independent && $i === 1 ? $other : json_decode(trim($out), true);
                self::assertIsArray($results[$i], $out . $err);
            }
            return $results;
        } finally {
            if ($lock->inTransaction()) {
                $lock->rollBack();
            }
            foreach ($procs as $proc) {
                proc_terminate($proc);
                proc_close($proc);
            }
            foreach ($pipes as $p) {
                foreach ($p as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
            }
        }
    }
}
