<?php
declare(strict_types=1);

namespace Picklers\Tests\Web;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\TestCase;
use Picklers\Domain\BookingRules;
use Picklers\Repositories\MatchRepository;
use Picklers\Web\Service\OwnerPortalView;

final class OwnerPortalViewTest extends TestCase
{
    public function testCourtsIgnoreExpiredSessionsAndMatchExactCourtNames(): void
    {
        $view = (new \ReflectionClass(OwnerPortalView::class))->newInstanceWithoutConstructor();
        $courts = (new \ReflectionMethod($view, 'courts'))->invoke($view, [
            ['id' => 'court10', 'name' => 'Court 10'],
            ['id' => 'court1', 'name' => 'Court 1 · Main Arena'],
        ], [
            ['id' => 'expired', 'court_id' => 'court1', 'date' => '2020-01-01', 'time' => '7:00 AM - 8:00 AM'],
            ['id' => 'daily', 'court_id' => 'court1', 'date' => 'Everyday', 'time' => '7:00 AM - 8:00 AM'],
        ]);

        self::assertSame(['Court 1', 'Court 10'], array_column($courts, 'name'));
        self::assertSame('daily', $courts[0]['open_play_match']['id']);
        self::assertFalse($courts[1]['has_open_play']);

        $exact = (new \ReflectionMethod($view, 'courts'))->invoke($view, [
            ['name' => 'Court 1'], ['name' => 'Court 10'],
        ], [['id' => 'ten', 'court_name' => 'Court 10', 'date' => 'Everyday']]);
        self::assertFalse($exact[0]['has_open_play']);
        self::assertSame('ten', $exact[1]['open_play_match']['id']);
    }

    public function testJoinedSeatsUseCurrentOccurrenceForDailySessions(): void
    {
        $connection = $this->createMock(Connection::class);
        $driverResult = $this->createMock(\Doctrine\DBAL\Driver\Result::class);
        $driverResult->method('fetchOne')->willReturn(2);
        $occurrences = [];
        $connection->expects(self::exactly(2))->method('executeQuery')->willReturnCallback(
            static function (string $sql, array $params) use (&$occurrences, $driverResult, $connection): Result {
                $occurrences[] = [$params[0], $params[1]];
                return new Result($driverResult, $connection);
            }
        );
        $rules = (new \ReflectionClass(BookingRules::class))->newInstanceWithoutConstructor();
        $matches = new MatchRepository($connection, $rules);
        $view = (new \ReflectionClass(OwnerPortalView::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($view, 'matches'))->setValue($view, $matches);
        $openPlay = new \ReflectionMethod($view, 'openPlay');
        $daily = ['id' => 'daily', 'date' => 'Everyday', 'time' => '7:00 AM - 8:00 AM', 'current_players' => 50, 'max_players' => 12];
        $once = ['id' => 'once', 'date' => '2099-01-01', 'time' => '7:00 AM - 8:00 AM', 'current_players' => 3, 'max_players' => 4];

        $out = $openPlay->invoke($view, [$daily, $daily, $once]);
        self::assertCount(2, $out['active']);
        self::assertSame([2, 3], array_column($out['active'], 'joined'));
        self::assertSame([12, 4], array_column($out['active'], 'capacity'));
        self::assertSame([], $out['completed']);
        self::assertSame([['daily', \Picklers\Domain\Schedule::getMatchTargetDate($daily)], ['once', '2099-01-01']], $occurrences);
    }
}
