<?php
declare(strict_types=1);

namespace Picklers\Web\Controller;

use Picklers\Web\Http\LegacyInput;
use Picklers\Web\Security\ImpersonationGate;
use Picklers\Web\Security\RateLimiter;
use Picklers\Web\Http\ApiError;
use Picklers\Web\Security\SessionUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Shared helpers for the player/owner controllers. Responses keep the API's
 * JSON contract ({success, ...} / {success:false, message, errors}) so app.js
 * works unchanged.
 */
abstract class AbstractWebController extends AbstractController
{
    /** CSRF token id shared by every player/owner page and the API. */
    public const CSRF_TOKEN_ID = 'picklers';

    public static function getSubscribedServices(): array
    {
        return parent::getSubscribedServices() + [ImpersonationGate::class => ImpersonationGate::class];
    }

    protected function impersonation(): ImpersonationGate
    {
        return $this->container->get(ImpersonationGate::class);
    }

    /** The CSRF token pages embed (meta tag / hidden field) for the API and forms. */
    protected function csrfToken(): string
    {
        return $this->container->get('security.csrf.token_manager')->getToken(self::CSRF_TOKEN_ID)->getValue();
    }

    /** The submitted CSRF token (X-CSRF-Token header, else a csrf_token field) is valid. */
    protected function validCsrf(Request $request): bool
    {
        $token = $request->headers->get('X-CSRF-Token') ?: (new LegacyInput($request))->input('csrf_token', '');

        return is_string($token) && $token !== '' && $this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $token);
    }

    protected function sessionUser(): ?SessionUser
    {
        $user = $this->getUser();

        return $user instanceof SessionUser ? $user : null;
    }

    /** Redirect to a path under the install base (e.g. "app.php?tab=settings"). */
    protected function redirectToPath(Request $request, string $path): RedirectResponse
    {
        return new RedirectResponse($request->getBaseUrl() . '/' . ltrim($path, '/'));
    }

    /** @param array<string,mixed> $data */
    protected function legacyJson(array $data, int $status = 200): JsonResponse
    {
        $response = new JsonResponse(null, $status);
        // Same tolerance as the legacy Response::json: one bad byte must not blank the payload.
        $response->setEncodingOptions($response->getEncodingOptions() | JSON_INVALID_UTF8_SUBSTITUTE);

        return $response->setData($data);
    }

    protected function legacyError(string $message, int $status): JsonResponse
    {
        return $this->legacyJson(['success' => false, 'message' => $message, 'errors' => []], $status);
    }

    /** Legacy jsonSuccess(): {success:true, message, ...payload}. */
    protected function legacySuccess(string $message, array $payload = []): JsonResponse
    {
        return $this->legacyJson(array_merge(['success' => true, 'message' => $message], $payload));
    }

    /**
     * The checks every state-changing endpoint runs, in the legacy API's order:
     * POST only, impersonation audit, CSRF, impersonation restriction, rate limit
     * (20/min per account+IP), then a signed-in account. Throws ApiError on refusal.
     */
    protected function guardMutation(
        Request $request,
        string $action,
        ?string $signInMessage,
        bool $restrictedWhileImpersonating = false,
        bool $rateLimited = false,
    ): ?SessionUser {
        // Also reached through the /api alias, where a route's method rule does not apply.
        if (!$request->isMethod('POST')) {
            throw new ApiError('Method not allowed.', 405);
        }
        $this->impersonation()->audit('api', $action);
        if (!$this->validCsrf($request)) {
            throw new ApiError('CSRF security verification failed.', 403);
        }
        if ($restrictedWhileImpersonating && $this->impersonation()->isRestricted($action)) {
            throw new ApiError('This action is not available while an administrator is viewing as this user.', 403);
        }
        $user = $this->sessionUser();
        if ($rateLimited) {
            $identity = ($user?->id() ?? 'guest') . '|' . ($request->getClientIp() ?? 'unknown');
            if (RateLimiter::tooMany("api.$action", 20, 60, $identity)) {
                throw new ApiError('Too many requests. Please slow down.', 429);
            }
        }
        if ($user === null && $signInMessage !== null) {
            throw new ApiError($signInMessage, 401);
        }

        return $user;
    }
}
