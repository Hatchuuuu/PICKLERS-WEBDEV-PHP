<?php
declare(strict_types=1);

namespace Picklers\Web\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * The player/owner API's parameter rules on a Symfony request: input() reads the
 * JSON body, then the form body, then the query string (first one set wins) and
 * returns raw values, arrays included; query() reads only the query string.
 * app.js, owner.js and tournament.js depend on exactly this precedence.
 */
final class LegacyInput
{
    /** @var array<string,mixed> */
    private array $json = [];

    public function __construct(public readonly Request $request)
    {
        if (stripos((string)$request->headers->get('Content-Type', ''), 'application/json') !== false) {
            $decoded = json_decode((string)$request->getContent(), true);
            $this->json = is_array($decoded) ? $decoded : [];
        }
    }

    public function input(string $key, mixed $default = null): mixed
    {
        foreach ([$this->json, $this->request->request->all(), $this->request->query->all()] as $source) {
            if (isset($source[$key])) {
                return $source[$key];
            }
        }

        return $default;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->request->query->all()[$key] ?? $default;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->request->headers->get($name, $default);
    }

    public function method(): string
    {
        return $this->request->getMethod();
    }

    /** XHR or explicit ajax=1: answer in JSON rather than redirecting. */
    public function wantsJson(): bool
    {
        return $this->header('X-Requested-With') === 'XMLHttpRequest' || $this->input('ajax') === '1';
    }
}
