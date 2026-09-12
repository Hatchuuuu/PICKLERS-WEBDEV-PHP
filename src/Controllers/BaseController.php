<?php
declare(strict_types=1);

namespace Picklers\Controllers;

use Picklers\Core\Request;
use Picklers\Core\Response;
use Picklers\Middleware\AuthMiddleware;
use Picklers\Middleware\CsrfMiddleware;

abstract class BaseController {

    protected function sanitizeUser(?array $user): ?array {
        if (!$user) {
            return null;
        }
        unset($user['password_hash'], $user['is_dev']);
        return $user;
    }

    protected function currentUser(): ?array {
        return $this->sanitizeUser(AuthMiddleware::user());
    }

    protected function requireUser(): array {
        return $this->sanitizeUser(AuthMiddleware::requireAuth()) ?? [];
    }

    protected function requireAdmin(): array {
        return $this->sanitizeUser(AuthMiddleware::requireAdmin()) ?? [];
    }

    protected function requireOwner(): array {
        return $this->sanitizeUser(AuthMiddleware::requireOwner()) ?? [];
    }

    protected function validateCsrf(Request $request): bool {
        return CsrfMiddleware::validate($request);
    }

    protected function csrfToken(): string {
        return CsrfMiddleware::getToken();
    }

    protected function json(array $data, int $statusCode = 200) {
        Response::json($data, $statusCode);
    }

    protected function jsonSuccess(array $payload = [], string $message = 'Success') {
        Response::json(array_merge(['success' => true, 'message' => $message], $payload));
    }

    protected function jsonError(string $message = 'An error occurred', int $statusCode = 400, array $errors = []) {
        Response::json(['success' => false, 'message' => $message, 'errors' => $errors], $statusCode);
    }

    protected function view(string $template, array $data = []) {
        Response::view($template, $data);
    }

    protected function redirect(string $url): Response {
        return Response::redirect($url);
    }
}
