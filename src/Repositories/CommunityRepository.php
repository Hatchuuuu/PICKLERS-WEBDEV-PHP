<?php
declare(strict_types=1);

namespace Picklers\Repositories;

/**
 * Community feed, comments, likes and direct messages.
 */
final class CommunityRepository
{
    private readonly \Doctrine\DBAL\Connection $db;
    private readonly \Picklers\Persistence\SchemaInstaller $schema;
    private readonly \Picklers\Repositories\UserRepository $users;

    public function __construct(
        ?\Doctrine\DBAL\Connection $db = null,
        ?\Picklers\Persistence\SchemaInstaller $schema = null,
        ?\Picklers\Repositories\UserRepository $users = null,
    ) {
        $this->db = $db ?? \Picklers\Core\Database::get()->dbal();
        $this->schema = $schema ?? new \Picklers\Persistence\SchemaInstaller($this->db);
        $this->users = $users ?? new \Picklers\Repositories\UserRepository($this->db, $this->schema);
    }

    public function getFeedPosts($userId = null) {
        // Content hidden by moderation is excluded from every public read.
        $visible = $this->schema->hasColumn('feed_posts', 'hidden_at') ? ' WHERE hidden_at IS NULL' : '';
        $stmt = $this->db->executeQuery("SELECT * FROM feed_posts{$visible} ORDER BY created_at DESC");
        $posts = $stmt->fetchAllAssociative();
        if (empty($posts)) return [];

        $postIds = array_column($posts, 'id');
        $placeholders = implode(',', array_fill(0, count($postIds), '?'));

        // Batch fetch liked post IDs in 1 query
        $likedPostIds = [];
        if ($userId) {
            $likeStmt = $this->db->executeQuery("SELECT post_id FROM feed_likes WHERE user_id = ? AND post_id IN ($placeholders)", array_merge([$userId], $postIds));
            $likedPostIds = array_flip($likeStmt->fetchFirstColumn());
        }

        // Batch fetch comments grouped by post_id in 1 query
        $hiddenComments = $this->schema->hasColumn('feed_comments', 'hidden_at');
        $commentStmt = $this->db->executeQuery("SELECT * FROM feed_comments WHERE post_id IN ($placeholders)" . ($hiddenComments ? ' AND hidden_at IS NULL' : '') . " ORDER BY created_at ASC", $postIds);
        $commentsByPost = [];
        foreach ($commentStmt->fetchAllAssociative() as $comm) {
            $commentsByPost[$comm['post_id']][] = $comm;
        }

        foreach ($posts as &$p) {
            $p['i_liked'] = isset($likedPostIds[$p['id']]);
            $p['comments'] = $commentsByPost[$p['id']] ?? [];
            if ($hiddenComments) {
                // The stored counter includes hidden comments; report what players can see.
                $p['comment_count'] = count($p['comments']);
            }
        }
        return $posts;
    }

    public function createFeedPost($userId, $content, $imageUrl = null, $type = 'text') {
        $user = $this->users->getUserById($userId);
        if (!$user) return ['success' => false, 'message' => 'User not found'];

        $id = 'post_' . bin2hex(random_bytes(6));
        $createdAt = date('Y-m-d H:i:s');

        $post = [
            'id' => $id,
            'author_id' => $userId,
            'author_name' => $user['name'],
            'author_avatar' => $user['avatar_url'],
            'author_level' => $user['level'],
            'content' => $content,
            'image_url' => $imageUrl,
            'post_type' => $type,
            'like_count' => 0,
            'comment_count' => 0,
            'created_at' => $createdAt
        ];

        $this->db->executeStatement("INSERT INTO feed_posts (id, author_id, author_name, author_avatar, author_level, content, image_url, post_type, like_count, comment_count, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)", [$id, $userId, $user['name'], $user['avatar_url'], $user['level'], $content, $imageUrl, $type, 0, 0, $createdAt]);

        $post['i_liked'] = false;
        $post['comments'] = [];
        return ['success' => true, 'post' => $post];
    }

    private function feedPostExists(string $postId): bool {
        if ($postId === '') {
            return false;
        }
        // A post hidden by moderation cannot be liked or commented on.
        $visible = $this->schema->hasColumn('feed_posts', 'hidden_at') ? ' AND hidden_at IS NULL' : '';
        $s = $this->db->executeQuery("SELECT 1 FROM feed_posts WHERE id = ?{$visible} LIMIT 1", [$postId]);
        return (bool)$s->fetchOne();
    }

    public function toggleLikePost($postId, $userId) {
        // Likes/comments for a post id that doesn't exist were stored as
        // orphan rows and reported back as a success.
        if (!$this->feedPostExists((string)$postId)) {
            return ['success' => false, 'message' => 'This post is no longer available.'];
        }
        $stmt = $this->db->executeQuery("SELECT COUNT(*) as c FROM feed_likes WHERE post_id = ? AND user_id = ?", [$postId, $userId]);
        $hasLiked = $stmt->fetchAssociative()['c'] > 0;
        if ($hasLiked) {
            $this->db->executeStatement("DELETE FROM feed_likes WHERE post_id = ? AND user_id = ?", [$postId, $userId]);
            $this->db->executeStatement("UPDATE feed_posts SET like_count = GREATEST(0, like_count - 1) WHERE id = ?", [$postId]);
            $liked = false;
        } else {
            $this->db->executeStatement("INSERT INTO feed_likes (post_id, user_id) VALUES (?,?)", [$postId, $userId]);
            $this->db->executeStatement("UPDATE feed_posts SET like_count = like_count + 1 WHERE id = ?", [$postId]);
            $liked = true;
        }
        $cntStmt = $this->db->executeQuery("SELECT like_count FROM feed_posts WHERE id = ?", [$postId]);
        $cnt = $cntStmt->fetchAssociative()['like_count'] ?? 0;
        return ['success' => true, 'i_liked' => $liked, 'like_count' => (int)$cnt];
    }

    public function addComment($postId, $userId, $comment) {
        $user = $this->users->getUserById($userId);
        if (!$user) return ['success' => false, 'message' => 'User not found'];
        if (!$this->feedPostExists((string)$postId)) {
            return ['success' => false, 'message' => 'This post is no longer available.'];
        }

        $id = 'comm_' . bin2hex(random_bytes(5));
        $createdAt = date('Y-m-d H:i:s');
        $entry = [
            'id' => $id,
            'post_id' => $postId,
            'user_id' => $userId,
            'author_name' => $user['name'],
            'author_avatar' => $user['avatar_url'],
            'comment' => $comment,
            'created_at' => $createdAt
        ];

        $this->db->executeStatement("INSERT INTO feed_comments (id, post_id, user_id, author_name, author_avatar, comment, created_at) VALUES (?,?,?,?,?,?,?)", [$id, $postId, $userId, $user['name'], $user['avatar_url'], $comment, $createdAt]);
        $this->db->executeStatement("UPDATE feed_posts SET comment_count = comment_count + 1 WHERE id = ?", [$postId]);

        return ['success' => true, 'comment' => $entry];
    }

    public function getMessages($userId, $partnerId) {
        $stmt = $this->db->executeQuery("SELECT * FROM direct_messages WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?) ORDER BY created_at ASC", [$userId, $partnerId, $partnerId, $userId]);
        return $stmt->fetchAllAssociative();
    }

    public function sendMessage($senderId, $receiverId, $content) {
        $id = 'msg_' . bin2hex(random_bytes(6));
        $createdAt = date('Y-m-d H:i:s');
        $msg = [
            'id' => $id,
            'sender_id' => $senderId,
            'receiver_id' => $receiverId,
            'content' => $content,
            'is_read' => 0,
            'created_at' => $createdAt
        ];

        $this->db->executeStatement("INSERT INTO direct_messages (id, sender_id, receiver_id, content, is_read, created_at) VALUES (?,?,?,?,?,?)", [$id, $senderId, $receiverId, $content, 0, $createdAt]);

        return ['success' => true, 'message' => $msg];
    }

    /**
     * Mark everything $partnerId sent to $userId as read. Nothing ever set
     * direct_messages.is_read, so the owner inbox's unread badges could never
     * clear no matter how many times a conversation was opened.
     */
    public function markConversationRead(string $userId, string $partnerId): void {
        if ($userId === '' || $partnerId === '') {
            return;
        }
        $this->db->executeStatement(
            "UPDATE direct_messages SET is_read = 1 WHERE receiver_id = ? AND sender_id = ? AND is_read = 0",
            [$userId, $partnerId]
        );
        return;
    }

    public function getConversationPartners(string $ownerId): array {
        $stmt = $this->db->executeQuery(
            "SELECT partner_id, MAX(created_at) as last_at,
                    SUM(CASE WHEN receiver_id=? AND is_read=0 THEN 1 ELSE 0 END) as unread
             FROM (
                 SELECT CASE WHEN sender_id=? THEN receiver_id ELSE sender_id END as partner_id,
                        created_at, receiver_id, is_read
                 FROM direct_messages
                 WHERE sender_id=? OR receiver_id=?
             ) t
             GROUP BY partner_id
             ORDER BY last_at DESC
             LIMIT 20",
            [$ownerId, $ownerId, $ownerId, $ownerId]
        );
        $partners = $stmt->fetchAllAssociative();

        $result = [];
        foreach ($partners as $p) {
            $uid  = (string)($p['partner_id'] ?? '');
            $user = $this->users->getUserById($uid);
            if (!$user) continue;

            $msgs = $this->getMessages($ownerId, $uid);
            $lastMsg = end($msgs);

            $result[] = [
                'id'           => 'conv_' . substr($uid, -8),
                'user_id'      => $uid,
                'user_name'    => (string)($user['name']       ?? 'Player'),
                'user_avatar'  => (string)($user['avatar_url'] ?? ''),
                'last_message' => $lastMsg ? (string)$lastMsg['content'] : '',
                'time'         => $lastMsg ? $this->relativeTime((string)($lastMsg['created_at'] ?? '')) : '',
                'unread'       => (int)($p['unread'] ?? 0),
                'messages'     => array_map(fn($m) => [
                    'sender' => (string)($m['sender_id'] ?? '') === $ownerId ? 'owner' : 'user',
                    'text'   => (string)($m['content'] ?? ''),
                    'time'   => date('g:i A', strtotime((string)($m['created_at'] ?? 'now'))),
                ], $msgs),
            ];
        }
        return $result;
    }

    private function relativeTime(string $datetime): string {
        $ts = strtotime($datetime);
        if ($ts === false) return $datetime;
        $diff = time() - $ts;
        if ($diff < 60)    return 'just now';
        if ($diff < 3600)  return round($diff / 60)  . 'm ago';
        if ($diff < 86400) return round($diff / 3600) . 'h ago';
        return date('M j', $ts);
    }
}
