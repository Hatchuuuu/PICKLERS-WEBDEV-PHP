<?php
declare(strict_types=1);

namespace Picklers\Tests\Web;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFunction;

final class OwnerScheduleHandlerTest extends TestCase
{
    public function testScheduleButtonPassesPopulatedBookingArraysThroughHtmlToJavascript(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/templates/web/partials/owner/_tab-dashboard.html.twig');
        preg_match('/onclick="openCourtScheduleModal[^\n]*?"/', $source, $match);
        self::assertNotEmpty($match);
        $twig = new Environment(new ArrayLoader(['handler' => '<button ' . $match[0] . '></button>']), ['autoescape' => 'html']);
        $twig->addFunction(new TwigFunction('addslashes', addslashes(...)));
        $upcoming = [['id' => 'BOOK-1', 'name' => 'O\'Connor "Player"', 'time' => '7:00 AM – 8:00 AM']];
        $completed = [['id' => 'BOOK-2', 'name' => '<img src=x onerror="auditMarker=true">']];
        $page = new Crawler($twig->render('handler', ['court' => [
            'id' => 'court-1', 'name' => 'Court 1', 'upcoming_bookings' => $upcoming, 'completed_bookings' => $completed,
        ]]));
        $handler = $page->filter('button')->attr('onclick');
        $data = base64_encode(json_encode([$handler, ['court-1', 'Court 1', $upcoming, $completed]], JSON_THROW_ON_ERROR));
        $script = <<<'JS'
const vm = require('node:vm'), assert = require('node:assert/strict');
const [handler, expected] = JSON.parse(Buffer.from(process.argv[2], 'base64').toString());
let received;
const c = { auditMarker: false, openCourtScheduleModal: (...args) => { received = args; } };
try {
  vm.runInNewContext(handler, c);
  assert.deepEqual(JSON.parse(JSON.stringify(received)), expected);
  assert.equal(c.auditMarker, false);
} catch (err) { console.error(err.message); process.exit(1); }
JS;
        $scriptFile = tempnam(sys_get_temp_dir(), 'pkl_schedule');
        file_put_contents($scriptFile, $script);
        try {
            exec('node ' . escapeshellarg($scriptFile) . ' ' . escapeshellarg($data) . ' 2>&1', $output, $code);
        } finally {
            unlink($scriptFile);
        }
        self::assertSame(0, $code, 'Schedule handler must pass both booking arrays unchanged: ' . implode("\n", $output));
        self::assertCount(0, $page->filter('img'), 'Booking data cannot create markup around the button.');
    }
}
