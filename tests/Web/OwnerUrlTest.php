<?php
declare(strict_types=1);

namespace Picklers\Tests\Web;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/** URL regressions need no database: render the real application template at both install bases. */
final class OwnerUrlTest extends TestCase
{
    public function testApplicationLinksAndSubmissionUseTheInstallBase(): void
    {
        foreach (['', '/PICKLERS%20WEBDEV%20PROJECT'] as $base) {
            $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'));
            $twig->addFunction(new TwigFunction('asset', static fn(string $path): string => $base . '/' . $path));
            $twig->addFunction(new TwigFunction('url_to', static fn(string $path): string => $base . '/' . ltrim($path, '/')));
            $html = $twig->render('web/owner-application.html.twig', [
                'notice' => '', 'csrfToken' => 'token', 'currentUser' => [], 'hasPendingApp' => false, 'latestApp' => null,
            ]);
            $page = new Crawler($html, 'https://picklers.test' . $base . '/app/owner-application');
            self::assertSame('https://picklers.test' . $base . '/owner-application.php', $page->filter('#ownerApplicationForm')->form()->getUri());
            foreach ($page->filter('a[href]') as $link) {
                self::assertSame($base . '/app.php', $link->getAttribute('href'));
            }
            self::assertStringContainsString('fetch(form.action,', $html, 'AJAX must submit to the same URL as the form');
        }
    }

    public function testOwnerFetchesDoNotResolveRelativeToTheNestedPortal(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/owner.js');
        self::assertDoesNotMatchRegularExpression('/fetch\(\s*[\'"`](?:api|owner)(?:\.php)?[?\'"`]/', $source);
        self::assertStringContainsString("window.PICKLERS_BASE_URL || ''", $source);
    }

    public function testOwnerNavigationRetainsSelectedFacility(): void
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'));
        $twig->addFunction(new TwigFunction('asset', static fn(string $path): string => '/base/' . $path));
        $twig->addFunction(new TwigFunction('stripos_found', static fn(string $haystack, string $needle): bool => stripos($haystack, $needle) !== false));
        $twig->addFunction(new TwigFunction('url_to', static fn(string $path): string => '/base/' . ltrim($path, '/')));
        foreach (['_sidebar', '_bottom-nav', '_mobile-header'] as $partial) {
            $page = new Crawler($twig->render('web/partials/owner/' . $partial . '.html.twig', ['tab' => 'settings', 'currentFacility' => ['id' => 42], 'currentUser' => []]));
            foreach ($page->filter('a[href]') as $link) {
                if (str_contains($link->getAttribute('href'), '/owner')) {
                    self::assertStringContainsString('facility_id=42', $link->getAttribute('href'));
                }
            }
        }
    }
}
