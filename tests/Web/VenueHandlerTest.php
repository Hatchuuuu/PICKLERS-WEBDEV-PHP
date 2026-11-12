<?php
declare(strict_types=1);

namespace Picklers\Tests\Web;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class VenueHandlerTest extends TestCase
{
    public function testServerRenderedFavoriteHandlerEncodesNamesAsJavascriptData(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/templates/web/partials/app/_tab-play.html.twig');
        preg_match('/onclick="event.stopPropagation\(\); toggleFavoriteFacility[^\n]*?"/', $source, $match);
        self::assertNotEmpty($match);
        $twig = new Environment(new ArrayLoader(['handler' => '<button ' . $match[0] . '></button>']), ['autoescape' => 'html']);
        $twig->addFilter(new TwigFilter('int', intval(...)));
        $twig->addFunction(new TwigFunction('addslashes', addslashes(...)));
        foreach (["O'Connor Courts", 'The "Best" Courts', "Back\\slash\nCourts", "x'); auditMarker = true; //"] as $name) {
            $dom = new \DOMDocument();
            $dom->loadHTML($twig->render('handler', ['f' => ['id' => 42, 'name' => $name]]));
            $handler = $dom->getElementsByTagName('button')[0]->getAttribute('onclick');
            $data = base64_encode(json_encode([$handler, $name]));
            $script = 'const vm=require("node:vm"),[handler,name]=JSON.parse(Buffer.from(process.argv[1],"base64").toString()); let received; const c={event:{stopPropagation(){}},auditMarker:false,toggleFavoriteFacility:(btn,id,value)=>{received=value;}}; try {vm.runInNewContext(handler,c); process.exit(received===name&&!c.auditMarker?0:1);} catch {process.exit(2);}';
            $scriptFile = tempnam(sys_get_temp_dir(), 'pkl_handler');
            file_put_contents($scriptFile, str_replace('process.argv[1]', 'process.argv[2]', $script));
            try {
                exec('node ' . escapeshellarg($scriptFile) . ' ' . escapeshellarg($data), $output, $code);
            } finally {
                unlink($scriptFile);
            }
            self::assertSame(0, $code, 'Browser-decoded handler must preserve the original name without executing it.');
        }
    }
}
