<?php
declare(strict_types=1);

namespace Picklers\Admin\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;

/**
 * Selects the admin firewall, its access rule and its error pages. Everything
 * else the kernel serves (migrated player/public sections) uses the `web` firewall.
 */
final class AdminAreaMatcher implements RequestMatcherInterface
{
    public function matches(Request $request): bool
    {
        return AdminArea::contains($request->getPathInfo(), (new ActionInput($request))->action() ?: null);
    }
}
