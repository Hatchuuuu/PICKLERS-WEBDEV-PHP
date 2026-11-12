<?php
declare(strict_types=1);

namespace Picklers\Web\Api;

use Picklers\Repositories\CommunityRepository;
use Picklers\Services\AuthService;
use Picklers\Web\Http\LegacyInput;
use Picklers\Web\Http\LegacyResponses;
use Symfony\Component\HttpFoundation\JsonResponse;

/** Messaging API for support and facility conversations. */
final class CommunityActions
{
    use LegacyResponses;

    public function __construct(
        private readonly AuthService $auth,
        private readonly CommunityRepository $community,
    ) {
    }

    public function messages(LegacyInput $in, ?array $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['success' => false, 'messages' => []]);
        }
        $partnerId = $this->partnerId((string)$in->query('partner_id', 'usr_admin'));
        $this->community->markConversationRead((string)$user['id'], $partnerId);
        $partner = $this->auth->getUserById($partnerId);

        return $this->json([
            'success' => true,
            'messages' => $this->community->getMessages($user['id'], $partnerId),
            'partner' => $partner ? [
                'id' => $partner['id'],
                'name' => $partner['name'],
                'avatar_url' => $partner['avatar_url'],
                'level' => $partner['level'],
            ] : null,
        ]);
    }

    public function sendMessage(LegacyInput $in, ?array $user): JsonResponse
    {
        if (!$user) {
            return $this->jsonError('Unauthorized', 401);
        }
        $partnerId = $this->partnerId((string)$in->input('partner_id', 'usr_admin'));
        $content = trim((string)$in->input('content', ''));
        if ($content === '') {
            return $this->jsonError('Message is empty', 400);
        }
        if (mb_strlen($content) > 2000) {
            return $this->jsonError('Messages are limited to 2,000 characters.', 400);
        }
        $recipient = $this->auth->getUserById($partnerId);
        if (!$recipient || ($recipient['role'] ?? '') === 'deleted' || (string)$recipient['id'] === (string)$user['id']) {
            return $this->jsonError('This conversation is not available.', 404);
        }

        return $this->json($this->community->sendMessage($user['id'], $partnerId, $content));
    }

    /**
     * The Support chat addresses the reserved id 'usr_admin', which no account
     * holds in a provisioned database; route it to a real, active administrator.
     */
    private function partnerId(string $partnerId): string
    {
        $partnerId = trim($partnerId);
        if ($partnerId !== 'usr_admin' || $this->auth->getUserById('usr_admin')) {
            return $partnerId;
        }
        foreach ($this->auth->getAllUsers() as $u) {
            if (!empty($u['is_admin']) && ($u['role'] ?? '') !== 'deleted') {
                return (string)$u['id'];
            }
        }

        return $partnerId;
    }
}
