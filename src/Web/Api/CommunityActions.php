<?php
declare(strict_types=1);

namespace Picklers\Web\Api;

use Picklers\Core\Database;
use Picklers\Repositories\CommunityRepository;
use Picklers\Services\AuthService;
use Picklers\Web\Http\LegacyInput;
use Picklers\Web\Http\LegacyResponses;
use Symfony\Component\HttpFoundation\JsonResponse;

/** Community API: feed posts, likes, comments and direct messages. */
final class CommunityActions
{
    use LegacyResponses;

    /** Ceiling for an inline post image (a 256px re-encode lands well under this). */
    public const MAX_INLINE_IMAGE_BYTES = 200000;

    public function __construct(
        private readonly Database $db,
        private readonly AuthService $auth,
        private readonly CommunityRepository $community,
    ) {
    }

    public function feedPosts(LegacyInput $in, ?array $user): JsonResponse
    {
        return $this->json(['success' => true, 'posts' => $this->community->getFeedPosts($user ? $user['id'] : null)]);
    }

    public function createPost(LegacyInput $in, ?array $user): JsonResponse
    {
        if (!$user) {
            return $this->jsonError('Please log in to post', 401);
        }
        $content = trim((string)$in->input('content', ''));
        $imageUrl = trim((string)$in->input('image_url', '')) ?: null;
        $type = (string)$in->input('post_type', 'text');
        if ($content === '') {
            return $this->jsonError('Post cannot be empty', 400);
        }
        if (mb_strlen($content) > 2000) {
            return $this->jsonError('Posts are limited to 2,000 characters.', 400);
        }
        if (!preg_match('/^[a-z_]{1,30}$/', $type)) {
            $type = 'text';
        }
        // Only a real web image or a small inline image — never a javascript: or
        // data:text URL rendered into other people's feeds.
        if ($imageUrl !== null && (strlen($imageUrl) > self::MAX_INLINE_IMAGE_BYTES
            || !preg_match('#^(https?://|data:image/(png|jpe?g|webp|gif);base64,)#i', $imageUrl))) {
            return $this->jsonError('Unsupported image. Please attach a PNG, JPG, WEBP or GIF.', 400);
        }

        return $this->json($this->community->createFeedPost($user['id'], $content, $imageUrl, $type));
    }

    public function likePost(LegacyInput $in, ?array $user): JsonResponse
    {
        if (!$user) {
            return $this->jsonError('Please log in to like', 401);
        }

        return $this->json($this->community->toggleLikePost((string)$in->input('post_id', ''), $user['id']));
    }

    public function addComment(LegacyInput $in, ?array $user): JsonResponse
    {
        if (!$user) {
            return $this->jsonError('Please log in to comment', 401);
        }
        $comment = trim((string)$in->input('comment', ''));
        if ($comment === '') {
            return $this->jsonError('Comment cannot be empty', 400);
        }
        if (mb_strlen($comment) > 1000) {
            return $this->jsonError('Comments are limited to 1,000 characters.', 400);
        }

        return $this->json($this->community->addComment((string)$in->input('post_id', ''), $user['id'], $comment));
    }

    public function messages(LegacyInput $in, ?array $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['success' => false, 'messages' => []]);
        }
        $partnerId = $this->partnerId((string)$in->query('partner_id', 'usr_admin'));
        $this->db->markConversationRead((string)$user['id'], $partnerId);
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
