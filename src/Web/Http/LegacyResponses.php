<?php
declare(strict_types=1);

namespace Picklers\Web\Http;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * The player/owner API's response shapes: {success, message, ...payload} and
 * {success:false, message, errors}. One bad UTF-8 byte must not blank a payload.
 */
trait LegacyResponses
{
    /** @param array<string,mixed> $data */
    protected function json(array $data, int $status = 200): JsonResponse
    {
        $response = new JsonResponse(null, $status);
        $response->setEncodingOptions($response->getEncodingOptions() | JSON_INVALID_UTF8_SUBSTITUTE);

        return $response->setData($data);
    }

    /** @param array<string,mixed> $payload */
    protected function jsonSuccess(array $payload = [], string $message = 'Success'): JsonResponse
    {
        return $this->json(array_merge(['success' => true, 'message' => $message], $payload));
    }

    protected function jsonError(string $message = 'An error occurred', int $status = 400, array $errors = []): JsonResponse
    {
        return $this->json(['success' => false, 'message' => $message, 'errors' => $errors], $status);
    }
}
