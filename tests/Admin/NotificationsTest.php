<?php
declare(strict_types=1);

namespace Picklers\Tests\Admin;

/** R12 — notices, broadcasts and the activity read state (fixture recipients only). */
final class NotificationsTest extends AdminWebTestCase
{
    public function testIndividualNoticeIsDeliveredOncePerKey(): void
    {
        $this->signIn('usr_fx_admin');
        $key = bin2hex(random_bytes(12));
        $p = ['user_id' => 'usr_fx_p1', 'title' => 'Court change', 'message' => 'Your booking moved to Court 2.', 'idempotency_key' => $key];
        self::assertSame(200, $this->action('admin_send_notification', $p)['status']);
        $again = $this->action('admin_send_notification', $p);
        self::assertTrue($again['json']['replayed']);
        self::assertSame(1, (int)$this->scalar("SELECT COUNT(*) FROM notifications WHERE user_id = 'usr_fx_p1' AND title = 'Court change'"));
        self::assertSame(422, $this->action('admin_send_notification', ['user_id' => 'usr_fx_p1', 'title' => 'x', 'message' => '', 'idempotency_key' => bin2hex(random_bytes(12))])['status']);
        self::assertSame(409, $this->action('admin_send_notification', ['user_id' => 'usr_fx_gone', 'title' => 'Hi', 'message' => 'Hello', 'idempotency_key' => bin2hex(random_bytes(12))])['status']);
    }

    public function testBroadcastNeedsPrivilegePreviewAndConfirmedCount(): void
    {
        $this->signIn('usr_fx_admin');
        self::assertSame(403, $this->action('admin_broadcast_notification', ['role_filter' => 'owner', 'title' => 'T', 'body' => 'B'])['status']);

        $this->signIn('usr_fx_root');
        // Owners in this database are fixture accounts plus seeded demo owners — all synthetic.
        $preview = $this->action('admin_broadcast_preview', ['role_filter' => 'owner', 'title' => 'Maintenance window', 'body' => 'The app is down Sunday 2-3 AM.'], null, '/admin/api', 'GET');
        self::assertSame(200, $preview['status']);
        $count = $preview['json']['preview']['count'];
        self::assertGreaterThan(0, $count);

        $noConfirm = $this->action('admin_broadcast_notification', ['role_filter' => 'owner', 'title' => 'Maintenance window', 'body' => 'The app is down Sunday 2-3 AM.', 'idempotency_key' => bin2hex(random_bytes(12))]);
        self::assertSame(422, $noConfirm['status']);
        self::assertSame($count, $noConfirm['json']['count']);
        $wrong = $this->action('admin_broadcast_notification', ['role_filter' => 'owner', 'title' => 'Maintenance window', 'body' => 'The app is down Sunday 2-3 AM.', 'confirm_count' => $count + 1, 'idempotency_key' => bin2hex(random_bytes(12))]);
        self::assertSame(409, $wrong['status'], 'audience changed since preview');

        $key = bin2hex(random_bytes(12));
        $send = $this->action('admin_broadcast_notification', ['role_filter' => 'owner', 'title' => 'Maintenance window', 'body' => 'The app is down Sunday 2-3 AM.', 'confirm_count' => $count, 'idempotency_key' => $key]);
        self::assertSame(200, $send['status'], json_encode($send['json']));
        self::assertSame($count, $send['json']['sent_to']);
        $replay = $this->action('admin_broadcast_notification', ['role_filter' => 'owner', 'title' => 'Maintenance window', 'body' => 'The app is down Sunday 2-3 AM.', 'confirm_count' => $count, 'idempotency_key' => $key]);
        self::assertTrue($replay['json']['replayed']);
        self::assertSame($count, (int)$this->scalar("SELECT COUNT(*) FROM notifications WHERE title = 'Maintenance window'"), 'no duplicates');
        self::assertNotNull($this->lastAudit('notification.broadcast'));
    }

    public function testActivityUnreadStateIsPersistedPerAdmin(): void
    {
        $this->signIn('usr_fx_admin');
        $first = $this->action('admin_get_activity', [], null, '/admin/api', 'GET');
        self::assertGreaterThan(0, $first['json']['unseen']);
        self::assertNotEmpty($first['json']['items'][0]['tab'], 'items carry a drill-down target');
        self::assertSame(200, $this->action('admin_mark_activity_seen')['status']);
        self::assertSame(0, $this->action('admin_get_activity', [], null, '/admin/api', 'GET')['json']['unseen']);
        $this->signIn('usr_fx_root');
        self::assertGreaterThan(0, $this->action('admin_get_activity', [], null, '/admin/api', 'GET')['json']['unseen'], 'read state is per administrator');
    }
}
