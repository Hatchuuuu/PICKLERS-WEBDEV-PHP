const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const vm = require('node:vm');
const { test } = require('node:test');
for (const file of ['app', 'owner']) {
  test(`${file} notification changes wait for persisted success`, async () => {
    const source = readFileSync(`public/assets/js/${file}.js`, 'utf8');
    const start = source.indexOf(file === 'app' ? '// Notifications Management' : '// Notification Handlers');
    const end = source.indexOf(file === 'app' ? '// Facility Follow Toggle' : '// Settings Helper Functions', start);
    let success = false, removed = false, unread = true;
    const calls = [];
    const card = { style: {}, classList: { remove: () => { unread = false; } }, querySelector: () => null };
    const row = { style: {}, offsetHeight: 40, classList: { contains: () => false, add() {}, remove() {} }, querySelector: () => card, remove: () => { removed = true; } };
    const context = {
      FormData, getCsrfToken: () => 'csrf', apiUrl: () => '/sub/api.php?facility_id=42',
      fetch: async (url, opts) => { calls.push({ url, action: opts.body.get('action'), id: opts.body.get('id') }); return { ok: true, json: async () => ({ success }) }; },
      showToast() {}, console, setTimeout: fn => { fn(); },
      document: { getElementById: () => null, querySelectorAll: selector => selector === '.notif-card-item.unread' ? [card] : [] },
    };
    vm.createContext(context); vm.runInContext(source.slice(start, end), context);
    await context.markAllNotificationsAsRead();
    assert.equal(unread, true, 'failed mark-read leaves the unread state');
    await context.dismissNotification(row, 'n42');
    assert.equal(removed, false, 'failed delete leaves the notification');
    assert.deepEqual(calls.map(c => c.action), ['mark_notifications_read', 'delete_notification']);
    assert.equal(calls[1].id, 'n42');
    success = true;
    await context.markAllNotificationsAsRead();
    assert.equal(unread, false);
    await context.dismissNotification(row, 'n42');
    assert.equal(removed, true);
  });
}
