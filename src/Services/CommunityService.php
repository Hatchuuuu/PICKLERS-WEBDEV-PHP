<?php
declare(strict_types=1);

namespace Picklers\Services;

use Picklers\Repositories\CommunityRepository;

class CommunityService {
    private CommunityRepository $communityRepo;

    public function __construct(?CommunityRepository $communityRepo = null) {
        $this->communityRepo = $communityRepo ?? new CommunityRepository();
    }

    public function getFeedPosts(?string $userId = null): array {
        return $this->communityRepo->getFeedPosts($userId);
    }

    public function createFeedPost(string $userId, string $content, ?string $imageUrl = null, string $type = 'text'): array {
        return $this->communityRepo->createFeedPost($userId, $content, $imageUrl, $type);
    }

    public function toggleLikePost(string $postId, string $userId): array {
        return $this->communityRepo->toggleLikePost($postId, $userId);
    }

    public function addComment(string $postId, string $userId, string $comment): array {
        return $this->communityRepo->addComment($postId, $userId, $comment);
    }

    public function getMessages(string $userId, string $partnerId): array {
        return $this->communityRepo->getMessages($userId, $partnerId);
    }

    public function sendMessage(string $senderId, string $receiverId, string $content): array {
        return $this->communityRepo->sendMessage($senderId, $receiverId, $content);
    }
}
