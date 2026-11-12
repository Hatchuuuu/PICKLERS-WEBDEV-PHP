<?php
declare(strict_types=1);

namespace Picklers\Tests\Web;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\TestCase;
use Picklers\Repositories\BookingRepository;
use Picklers\Web\Service\DiscoverService;
use Picklers\Web\Twig\PlayerViewExtension;

final class DiscoverOccurrenceTest extends TestCase
{
    public function testJoinedStatusBelongsOnlyToTheSpecificSessionAndDate(): void
    {
        $rows = [
            ['id' => 'PKL-OP-A', 'status' => 'pending', 'match_id' => 'a', 'facility_id' => 1, 'court_name' => 'Court 1', 'time' => '7:00 AM - 8:00 AM', 'booking_date' => '2030-01-01'],
            ['id' => 'PKL-OP-C', 'status' => 'confirmed', 'match_id' => 'daily', 'facility_id' => 1, 'court_name' => 'Court 3', 'time' => '7:00 AM - 8:00 AM', 'booking_date' => date('Y-m-d', strtotime('-1 day'))],
            ['id' => 'PKL-regular', 'status' => 'confirmed', 'facility_id' => 1, 'court_name' => 'Court 4', 'time' => '7:00 AM - 8:00 AM', 'booking_date' => '2030-01-01'],
        ];
        $connection = $this->createMock(Connection::class);
        $driver = $this->createMock(\Doctrine\DBAL\Driver\Result::class);
        $driver->method('fetchAllAssociative')->willReturn($rows);
        $connection->method('executeQuery')->willReturn(new Result($driver, $connection));
        $bookings = (new \ReflectionClass(BookingRepository::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($bookings, 'db'))->setValue($bookings, $connection);
        $discover = (new \ReflectionClass(DiscoverService::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($discover, 'bookings'))->setValue($discover, $bookings);
        $courts = [];
        foreach (['a', 'b', 'daily', 'other'] as $i => $id) {
            $courts[] = ['name' => 'Court ' . ($i + 1), 'facility_id' => 1, 'open_play_match' => ['id' => $id, 'date' => $id === 'daily' ? 'Everyday' : '2030-01-01', 'type' => 'Court ' . ($i + 1), 'time' => '7:00 AM - 8:00 AM']];
        }
        $out = (new \ReflectionMethod($discover, 'markJoinedOpenPlay'))->invoke($discover, $courts, 1, 'player');
        self::assertTrue($out[0]['user_joined']);
        foreach (array_slice($out, 1) as $court) self::assertEmpty($court['user_joined'] ?? false, $court['name']);
        $keys = PlayerViewExtension::joinedOccurrenceKeys($rows);
        self::assertArrayHasKey('a|2030-01-01', $keys);
        self::assertArrayNotHasKey('a', $keys, 'an undated key must never mark another occurrence');
        self::assertArrayNotHasKey('1|Court 4|7:00 AM - 8:00 AM|2030-01-01', $keys, 'a court reservation is not an Open Play membership');
    }
}
