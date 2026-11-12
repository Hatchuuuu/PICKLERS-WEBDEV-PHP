<?php
declare(strict_types=1);

namespace Picklers\Tests\Web;

use Doctrine\DBAL\Connection;
use Picklers\Migrations\Version20261012000002;
use Picklers\Repositories\MatchRepository;
use Picklers\Tests\Admin\AdminWebTestCase;
use Psr\Log\NullLogger;

final class MatchBookingIndexTest extends AdminWebTestCase
{
    private function migrate(Connection $db, string $direction): Version20261012000002
    {
        $migration = new Version20261012000002($db, new NullLogger());
        $migration->$direction($db->createSchemaManager()->introspectSchema());
        foreach ($migration->getSql() as $sql) {
            $db->executeStatement($sql->getStatement(), $sql->getParameters(), $sql->getTypes());
        }
        return $migration;
    }

    public function testFreshAndMigratedIndexPreserveCountsAndAvoidWholeTableScan(): void
    {
        $db = $this->db();
        self::assertSame('picklers_admin_test', $db->getDatabase());
        $indexes = $db->createSchemaManager()->listTableIndexes('bookings');
        self::assertArrayHasKey('idx_booking_match_date', $indexes);
        $index = $indexes['idx_booking_match_date'];
        self::assertSame(['match_id', 'status', 'booking_date'], $index->getColumns());
        self::assertFalse($index->isUnique());
        $repo = static::getContainer()->get(MatchRepository::class);
        $target = '2026-10-12';
        $cases = [
            ['confirmed', $target, 'Today', '2026-10-11 09:00:00'],
            ['completed', $target, 'Today', '2026-10-11 09:00:00'],
            ['pending', $target, $target, '2026-10-12 09:00:00'],
            ['cancelled', $target, $target, '2026-10-12 09:00:00'],
            ['confirmed', '2026-10-11', $target, '2026-10-12 09:00:00'],
            ['confirmed', null, $target, '2026-10-11 09:00:00'],
            ['completed', null, 'legacy ' . $target, '2026-10-11 09:00:00'],
            ['confirmed', null, 'Today', '2026-10-12 09:00:00'],
            ['confirmed', null, '2026-10-11', '2026-10-11 09:00:00'],
        ];
        try {
            foreach ($cases as $i => $row) {
                $db->executeStatement('INSERT INTO bookings (id, match_id, status, booking_date, date, created_at) VALUES (?, ?, ?, ?, ?, ?)',
                    array_merge(['FX-INDEX-' . $i, 'op_index_target'], $row));
            }
            // Many unrelated matches reproduce the scan without adding a large fixture file.
            for ($batch = 0; $batch < 8; ++$batch) {
                $values = [];
                $params = [];
                for ($i = 0; $i < 1000; ++$i) {
                    $values[] = '(?, ?, ?, ?)';
                    array_push($params, 'FX-INDEX-noise-' . $batch . '-' . $i, 'op_noise_' . $i, 'confirmed', $target);
                }
                $db->executeStatement('INSERT INTO bookings (id, match_id, status, booking_date) VALUES ' . implode(',', $values), $params);
            }
            $query = "SELECT COUNT(*) FROM bookings WHERE match_id = ? AND status IN ('confirmed', 'completed')
                AND (booking_date = ? OR (booking_date IS NULL AND (date = ? OR date LIKE ? OR created_at LIKE ?)))";
            $params = ['op_index_target', $target, $target, "%$target%", "$target%"];
            $beforeRows = $db->fetchAllAssociative("SELECT * FROM bookings WHERE id LIKE 'FX-INDEX-%' ORDER BY id");
            $this->migrate($db, 'down');
            self::assertSame([], $this->migrate($db, 'down')->getSql());
            $beforePlan = $db->fetchAssociative('EXPLAIN ' . $query, $params);
            self::assertSame('ALL', $beforePlan['type']);
            self::assertSame(5, $repo->countConfirmedMatchBookings('op_index_target', $target));
            self::assertSame(7, $repo->countConfirmedMatchBookings('op_index_target'));
            $migration = $this->migrate($db, 'up');
            self::assertFalse($migration->isTransactional());
            self::assertSame([], $this->migrate($db, 'up')->getSql());
            self::assertEquals($index, $db->createSchemaManager()->listTableIndexes('bookings')['idx_booking_match_date']);
            $db->fetchAllAssociative('ANALYZE TABLE bookings');
            $afterPlan = $db->fetchAssociative('EXPLAIN ' . $query, $params);
            self::assertSame('idx_booking_match_date', $afterPlan['key']);
            self::assertNotSame('ALL', $afterPlan['type']);
            self::assertLessThan(100, (int)$afterPlan['rows']);
            self::assertSame(5, $repo->countConfirmedMatchBookings('op_index_target', $target));
            self::assertSame(7, $repo->countConfirmedMatchBookings('op_index_target'));
            self::assertSame(0, $repo->countConfirmedMatchBookings('op_index_target', '2026-10-13'));
            self::assertSame($beforeRows, $db->fetchAllAssociative("SELECT * FROM bookings WHERE id LIKE 'FX-INDEX-%' ORDER BY id"));
        } finally {
            $this->migrate($db, 'up');
            $db->executeStatement("DELETE FROM bookings WHERE id LIKE 'FX-INDEX-%'");
        }
    }
}
