<?php
declare(strict_types=1);

namespace Picklers\Admin\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * Request parameters for the action endpoint with the legacy precedence
 * (JSON body, then form body, then query string). Only scalars are accepted.
 */
final class ActionInput
{
    /** @var array<string,mixed> */
    private array $json;

    public function __construct(private readonly Request $request)
    {
        $decoded = null;
        if (str_contains((string)$request->headers->get('Content-Type', ''), 'application/json')) {
            $decoded = json_decode((string)$request->getContent(), true);
        }
        $this->json = is_array($decoded) ? $decoded : [];
    }

    public function string(string $key, string $default = ''): string
    {
        foreach ([$this->json[$key] ?? null, $this->request->request->all()[$key] ?? null, $this->request->query->all()[$key] ?? null] as $value) {
            if ($value !== null && is_scalar($value)) {
                return trim((string)$value);
            }
        }

        return $default;
    }

    public function has(string $key): bool
    {
        return $this->string($key, "\0") !== "\0";
    }

    public function action(): string
    {
        return $this->string('action');
    }

    public function csrfToken(): string
    {
        return (string)($this->request->headers->get('X-CSRF-Token') ?: $this->string('csrf_token'));
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return array_merge($this->request->query->all(), $this->request->request->all(), $this->json);
    }
}
