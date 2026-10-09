<?php
declare(strict_types=1);

namespace Picklers\Web\Twig;

use Picklers\Web\Security\ImpersonationGate;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Small helpers the player/owner templates use: install-relative links and the
 * PHP string functions the original views relied on.
 */
final class ViewExtension extends AbstractExtension
{
    public function __construct(
        private readonly RequestStack $requests,
        private readonly ImpersonationGate $impersonation,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('url_to', $this->urlTo(...)),
            new TwigFunction('addslashes', static fn($s): string => addslashes((string)$s)),
            new TwigFunction('stripos_found', static fn($haystack, $needle): bool => stripos((string)$haystack, (string)$needle) !== false),
            new TwigFunction('hours_label', self::hoursLabel(...)),
            // The administrator currently viewing as this user, or null.
            new TwigFunction('impersonation_state', $this->impersonation->state(...)),
        ];
    }

    /** Facility opening hours for a card: "6:00 AM - 10:00 PM" → "6am - 10pm", anything with 24 → "24/hrs". */
    public static function hoursLabel(?string $hours): string
    {
        $raw = trim((string)($hours ?? '6am - 10pm'));
        if ($raw === '' || $raw === '0') {
            $raw = '6am - 10pm';
        }
        if (stripos($raw, '24') !== false) {
            return '24/hrs';
        }
        $label = str_replace(['?', '–', '—'], '-', $raw);
        $label = (string)preg_replace('/0?([1-9]|1[0-2]):00\s*(AM|PM)/i', '$1$2', $label);
        $label = (string)preg_replace('/\s*-\s*/', ' - ', $label);

        return strtolower($label);
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('int', static fn($v): int => (int)$v),
            new TwigFilter('float', static fn($v): float => (float)$v),
            new TwigFilter('ucfirst', static fn($v): string => ucfirst((string)$v)),
        ];
    }

    /** A path under the install base (e.g. "app.php?tab=play" → "/PICKLERS%20WEBDEV%20PROJECT/app.php?tab=play"). */
    public function urlTo(string $path = ''): string
    {
        $base = $this->requests->getMainRequest()?->getBaseUrl() ?? '';

        return $base . '/' . ltrim($path, '/');
    }
}
