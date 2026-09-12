<?php
declare(strict_types=1);

namespace Picklers\Repositories;

use Picklers\Core\Database;

class CommunityRepository {
    private Database $db;

    public function __construct(?Database $db = null) {
        $this->db = $db ?? Database::get();
    }

    public function getFeedPosts(?string $userId = null): array {
        return $this->db->getFeedPosts($userId);
    }

    public function createFeedPost(string $userId, string $content, ?string $imageUrl = null, string $type = 'text'): array {
        return $this->db->createFeedPost($userId, $content, $imageUrl, $type);
    }

    public function toggleLikePost(string $postId, string $userId): array {
        return $this->db->toggleLikePost($postId, $userId);
    }

    public function addComment(string $postId, string $userId, string $comment): array {
        return $this->db->addComment($postId, $userId, $comment);
    }

    public function getMessages(string $userId, string $partnerId): array {
        return $this->db->getMessages($userId, $partnerId);
    }

    public function sendMessage(string $senderId, string $receiverId, string $content): array {
        return $this->db->sendMessage($senderId, $receiverId, $content);
    }
}
