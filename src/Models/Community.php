<?php
declare(strict_types=1);

namespace Picklers\Models;

use Picklers\Services\CommunityService;

class Community {
    private CommunityService $communityService;

    public function __construct(?CommunityService $communityService = null) {
        $this->communityService = $communityService ?? new CommunityService();
    }

    public function getFeed(?string $userId = null): array {
        return $this->communityService->getFeedPosts($userId);
    }

    public function createPost(string $userId, string $content, ?string $imageUrl = null, string $type = 'text'): array {
        return $this->communityService->createFeedPost($userId, $content, $imageUrl, $type);
    }

    public function toggleLike(string $postId, string $userId): array {
        return $this->communityService->toggleLikePost($postId, $userId);
    }

    public function addComment(string $postId, string $userId, string $comment): array {
        return $this->communityService->addComment($postId, $userId, $comment);
    }

    public function getMessages(string $userId, string $partnerId): array {
        return $this->communityService->getMessages($userId, $partnerId);
    }

    public function sendMessage(string $senderId, string $receiverId, string $content): array {
        return $this->communityService->sendMessage($senderId, $receiverId, $content);
    }
}
