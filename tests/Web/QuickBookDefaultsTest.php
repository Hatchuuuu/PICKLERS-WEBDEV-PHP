<?php
declare(strict_types=1);

namespace Picklers\Tests\Web;

use Picklers\Tests\Admin\AdminWebTestCase;

final class QuickBookDefaultsTest extends AdminWebTestCase
{
    public function testInitialQuickBookDateMatchesItsSelectedDatePill(): void
    {
        $this->signIn('usr_fx_p1');
        $crawler = $this->client->request('GET', '/app.php');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $active = $crawler->filter('#qbDatePillsContainer .active');
        self::assertCount(1, $active);
        preg_match("/selectQuickBookDate\(this, '([^']+)'/", (string)$active->attr('onclick'), $matches);
        self::assertArrayHasKey(1, $matches);
        foreach (['qbSelectedDate', 'qbSelectedDateFull'] as $id) {
            self::assertSame($matches[1], $crawler->filter('#' . $id)->attr('value'));
        }
        self::assertSame($matches[1], $crawler->filter('#qbSummaryDateText')->text());
    }
}
