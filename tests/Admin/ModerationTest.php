<?php
declare(strict_types=1);

namespace Picklers\Tests\Admin;

use Picklers\Core\Database;

/** R06 — content moderation queue, reversible hiding and public exclusion. */
final class ModerationTest extends AdminWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->signIn('usr_fx_admin');
    }

    private function visiblePostIds(): array
    {
        return array_column(Database::get()->getFeedPosts('usr_fx_p1'), 'id');
    }

    public function testFlagHideRestoreResolveLifecycle(): void
    {
        self::assertSame(422, $this->action('admin_moderation_flag', ['content_type' => 'post', 'content_id' => 'fx_post2', 'category' => 'scam', 'reason' => 'x'])['status']);
        self::assertSame(404, $this->action('admin_moderation_flag', ['content_type' => 'post', 'content_id' => 'nope', 'category' => 'scam', 'reason' => 'Asking for wire transfers'])['status']);
        $r = $this->action('admin_moderation_flag', ['content_type' => 'post', 'content_id' => 'fx_post2', 'category' => 'scam', 'reason' => 'Asking for wire transfers']);
        self::assertSame(200, $r['status'], json_encode($r['json']));
        $case = $r['json']['case_id'];
        self::assertSame(409, $this->action('admin_moderation_flag', ['content_type' => 'post', 'content_id' => 'fx_post2', 'category' => 'spam', 'reason' => 'Duplicate flag attempt'])['status'], 'one open case per content');

        self::assertSame(200, $this->action('admin_moderation_hide', ['case_id' => $case, 'reason' => 'Clear scam pattern'])['status']);
        self::assertNotContains('fx_post2', $this->visiblePostIds(), 'hidden from the public feed');
        self::assertFalse(Database::get()->toggleLikePost('fx_post2', 'usr_fx_p1')['success'] ?? false, 'cannot like hidden content');
        self::assertSame(1, (int)$this->scalar("SELECT COUNT(*) FROM feed_posts WHERE id = 'fx_post2'"), 'never deleted');
        self::assertSame(409, $this->action('admin_moderation_dismiss', ['case_id' => $case, 'reason' => 'No violation found'])['status'], 'cannot dismiss while hidden');

        self::assertSame(200, $this->action('admin_moderation_restore', ['case_id' => $case, 'reason' => 'Seller verified, legit listing'])['status']);
        self::assertContains('fx_post2', $this->visiblePostIds());
        self::assertSame(200, $this->action('admin_moderation_dismiss', ['case_id' => $case, 'reason' => 'Verified seller, no violation'])['status']);
        self::assertSame(409, $this->action('admin_moderation_resolve', ['case_id' => $case, 'reason' => 'Close again'])['status'], 'closed cases are final');

        $events = self::pdo()->query("SELECT action FROM moderation_case_events WHERE case_id = " . self::pdo()->quote($case) . ' ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['opened', 'hidden', 'restored', 'dismissed'], $events);
        self::assertNotNull($this->lastAudit('moderation.dismiss'));
        $detail = $this->action('admin_get_moderation_case', ['case_id' => $case], null, '/admin/api', 'GET');
        self::assertSame('dismissed', $detail['json']['case']['status']);
    }

    public function testHiddenCommentsAreExcludedAndCountsAdjusted(): void
    {
        $r = $this->action('admin_moderation_flag', ['content_type' => 'comment', 'content_id' => 'fx_com2', 'category' => 'harassment', 'reason' => 'Accusatory reply']);
        self::assertSame(200, $this->action('admin_moderation_hide', ['case_id' => $r['json']['case_id'], 'reason' => 'Hide pending review'])['status']);
        $post = array_values(array_filter(Database::get()->getFeedPosts('usr_fx_p1'), fn($p) => $p['id'] === 'fx_post2'))[0];
        self::assertSame([], $post['comments']);
        self::assertSame(0, $post['comment_count']);
        self::assertSame(200, $this->action('admin_moderation_resolve', ['case_id' => $r['json']['case_id'], 'reason' => 'Comment stays hidden'])['status']);
        self::assertSame('content_hidden', $this->scalar('SELECT resolution FROM moderation_cases WHERE id = ?', [$r['json']['case_id']]));
    }

    public function testQueueAndContentViewsRender(): void
    {
        $this->action('admin_moderation_flag', ['content_type' => 'post', 'content_id' => 'fx_post1', 'category' => 'other', 'reason' => 'Check this listing']);
        $this->client->request('GET', '/admin/panel/moderation', ['status' => 'open']);
        self::assertSelectorTextContains('.pk-pager__count', 'of 1');
        $this->client->request('GET', '/admin/panel/moderation', ['view' => 'content', 'ctype' => 'comment']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Count me in!');
    }
}
