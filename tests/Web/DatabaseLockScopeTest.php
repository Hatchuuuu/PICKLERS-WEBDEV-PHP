<?php
declare(strict_types=1);

namespace Picklers\Tests\Web;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Picklers\Exceptions\TournamentBusyException;
use Picklers\Repositories\OwnerRepository;
use Picklers\Services\TournamentService;
use Picklers\Tests\Admin\AdminWebTestCase;

final class DatabaseLockScopeTest extends AdminWebTestCase
{
    public function testTournamentLockInAnotherDatabaseDoesNotBlockThisInstallation(): void
    {
        $holder = $this->foreignDatabase();
        $locks = ['picklers_tournaments', hash('sha256', 'picklers_tournaments:' . $holder->getDatabase())];
        $service = static::getContainer()->get(TournamentService::class);
        $created = null;
        try {
            foreach ($locks as $lock) {
                self::assertSame(1, (int)$holder->fetchOne('SELECT GET_LOCK(?, 0)', [$lock]));
            }
            $error = null;
            try {
                $created = $service->create(['title' => 'Independent database tournament']);
            } catch (TournamentBusyException $exception) {
                $error = $exception->getMessage();
            }
            self::assertNull($error, 'A foreign database lock cannot make this installation busy.');
            self::assertNull($created['error']);
            self::assertSame(1, (int)$this->db()->fetchOne('SELECT COUNT(*) FROM tournaments WHERE id = ?', [$created['tournament']['id']]));
        } finally {
            foreach ($locks as $lock) {
                $holder->fetchOne('SELECT RELEASE_LOCK(?)', [$lock]);
            }
            $this->closeForeignDatabase($holder);
            if (!empty($created['tournament']['id'])) {
                $service->delete($created['tournament']['id']);
            }
        }
    }

    public function testPayoutLockForSameFacilityIdInAnotherDatabaseDoesNotBlockThisInstallation(): void
    {
        $facility = (string)$this->scalar("SELECT facility_id FROM bookings WHERE id = 'FX-DONE01'");
        $this->db()->update('wallet_transactions', ['booking_id' => 'FX-DONE01', 'entry_kind' => 'booking_payment'], ['id' => 'fx_tx02']);
        $holder = $this->foreignDatabase();
        $locks = ['picklers_payout_' . $facility, hash('sha256', 'picklers_payout:' . $holder->getDatabase() . ':' . $facility)];
        try {
            foreach ($locks as $lock) {
                self::assertSame(1, (int)$holder->fetchOne('SELECT GET_LOCK(?, 0)', [$lock]));
            }
            $result = static::getContainer()->get(OwnerRepository::class)->createPayoutRequest('usr_fx_owner1', $facility, 100, 'GCash');
            self::assertTrue($result['success'], $result['message'] ?? '');
            self::assertSame('100.00', $this->scalar('SELECT amount FROM payout_requests WHERE id = ?', [$result['payout']['id']]));
        } finally {
            foreach ($locks as $lock) {
                $holder->fetchOne('SELECT RELEASE_LOCK(?)', [$lock]);
            }
            $this->closeForeignDatabase($holder);
        }
    }

    public function testPayoutStillRejectsContentionInItsOwnDatabaseAndRetriesAfterRelease(): void
    {
        $facility = (string)$this->scalar("SELECT facility_id FROM bookings WHERE id = 'FX-DONE01'");
        $this->db()->update('wallet_transactions', ['booking_id' => 'FX-DONE01', 'entry_kind' => 'booking_payment'], ['id' => 'fx_tx02']);
        $holder = DriverManager::getConnection($this->db()->getParams());
        $lock = hash('sha256', 'picklers_payout:' . $holder->getDatabase() . ':' . $facility);
        $owners = static::getContainer()->get(OwnerRepository::class);
        $before = $this->db()->fetchAllAssociative('SELECT * FROM payout_requests ORDER BY id');
        try {
            self::assertSame(1, (int)$holder->fetchOne('SELECT GET_LOCK(?, 0)', [$lock]));
            $result = $owners->createPayoutRequest('usr_fx_owner1', $facility, 100, 'GCash');
            self::assertFalse($result['success']);
            self::assertSame($before, $this->db()->fetchAllAssociative('SELECT * FROM payout_requests ORDER BY id'));
        } finally {
            $holder->fetchOne('SELECT RELEASE_LOCK(?)', [$lock]);
            $holder->close();
        }
        self::assertTrue($owners->createPayoutRequest('usr_fx_owner1', $facility, 100, 'GCash')['success']);
    }

    private function foreignDatabase(): Connection
    {
        // Exercise the maximum database-name length without touching an existing schema.
        $name = 'picklers_lock_' . str_repeat('x', 37) . bin2hex(random_bytes(4)) . '_test';
        $this->db()->executeStatement('CREATE DATABASE `' . $name . '`');
        $params = $this->db()->getParams();
        $params['dbname'] = $name;
        $database = DriverManager::getConnection($params);
        self::assertSame($name, $database->getDatabase());
        self::assertNotSame($this->db()->getDatabase(), $database->getDatabase());
        return $database;
    }

    private function closeForeignDatabase(Connection $database): void
    {
        $name = $database->getParams()['dbname'];
        $database->close();
        $this->db()->executeStatement('DROP DATABASE `' . $name . '`');
    }
}
