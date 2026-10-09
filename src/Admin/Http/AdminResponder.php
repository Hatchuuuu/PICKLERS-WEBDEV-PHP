<?php
declare(strict_types=1);

namespace Picklers\Admin\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chooses between the JSON contract used by admin actions and HTML navigation,
 * and builds URLs to legacy (non-Symfony) pages relative to the install base.
 */
final class AdminResponder
{
    public function wantsJson(Request $request): bool
    {
        $path = $request->getPathInfo();

        return $request->getMethod() !== 'GET'
            || str_starts_with($path, '/admin/api')
            || preg_match('#^/api(\.php)?$#', $path) === 1
            || $request->headers->get('X-Requested-With') === 'XMLHttpRequest'
            || str_contains((string)$request->headers->get('Accept', ''), 'application/json');
    }

    /** URL of a legacy page (auth, app, logout…) under the same install base. */
    public function legacyUrl(Request $request, string $path): string
    {
        $base = $request->getBaseUrl();
        if (str_ends_with($base, '.php')) {
            $base = \dirname($base);
        }

        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }

    public function json(array $payload, int $status = 200): JsonResponse
    {
        $response = new JsonResponse($payload, $status);
        $response->setEncodingOptions(JsonResponse::DEFAULT_ENCODING_OPTIONS | JSON_INVALID_UTF8_SUBSTITUTE);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    public function error(string $message, int $status, array $extra = []): JsonResponse
    {
        return $this->json(['success' => false, 'message' => $message] + $extra, $status);
    }

    public function unauthenticated(Request $request, string $message = 'Sign in with an administrator account to continue.'): Response
    {
        if ($this->wantsJson($request)) {
            return $this->error($message, Response::HTTP_UNAUTHORIZED);
        }

        return new RedirectResponse($this->legacyUrl($request, 'auth?next=admin'));
    }
}
