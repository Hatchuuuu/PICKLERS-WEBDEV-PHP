<?php
declare(strict_types=1);

namespace Picklers\Tests\Admin;

final class LikeConcurrencyTest extends AdminWebTestCase
{
    public function testConcurrentTogglesAreSerializedAndCountMatchesStoredLikes(): void
    {
        $this->db()->executeStatement("DELETE FROM feed_likes WHERE post_id = 'fx_post1'");
        $this->db()->insert('feed_likes', ['post_id' => 'fx_post1', 'user_id' => 'usr_fx_p1']);
        $this->db()->update('feed_posts', ['like_count' => 1], ['id' => 'fx_post1']);
        $this->db()->executeStatement('CREATE TRIGGER test_slow_unlike BEFORE DELETE ON feed_likes FOR EACH ROW DO SLEEP(0.2)');
        try {
            $results = $this->concurrently(2, 'like', ['post_id' => 'fx_post1', 'user_id' => 'usr_fx_p1']);
            self::assertSame(1, (int)$this->scalar("SELECT COUNT(*) FROM feed_likes WHERE post_id = 'fx_post1' AND user_id = 'usr_fx_p1'"), json_encode($results));
            self::assertSame(1, (int)$this->scalar("SELECT like_count FROM feed_posts WHERE id = 'fx_post1'"));
            foreach ($results as $result) self::assertTrue($result['result']['success'] ?? false, json_encode($result));
        } finally {
            $this->db()->executeStatement('DROP TRIGGER test_slow_unlike');
        }
    }
}
