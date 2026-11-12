const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const vm = require('node:vm');
const { test } = require('node:test');
const source = readFileSync('public/assets/js/app.js', 'utf8');
const favorites = source.slice(source.indexOf('const favoriteToggleInFlight'), source.indexOf('// Notifications Management'));
const follow = source.slice(source.indexOf('// Facility Follow Toggle'), source.indexOf('// Facility Operating Hours Validator'));

test('Follow persists through Favorites and waits for confirmation; failures keep the previous state', async () => {
  const attributes = { 'data-following': 'false' };
  const button = { disabled: false, style: {}, classList: { toggle() {} }, setAttribute(k, v) { attributes[k] = v; }, getAttribute(k) { return attributes[k]; } };
  let resolveResponse, posted;
  const context = { silentRefreshDiscover() {},
    document: { querySelectorAll: () => [], getElementById: () => button },
    currentSelectedFacility: { id: 42, is_favorited: false }, getCsrfToken: () => 'csrf', showToast() {},
    fetch: (url, options) => { posted = { url, payload: JSON.parse(options.body) }; return new Promise(resolve => { resolveResponse = resolve; }); },
  };
  vm.createContext(context);
  vm.runInContext(favorites + follow, context);
  const pending = context.toggleFollowFacility(button, 42, "O'Connor Courts");
  assert.ok(posted, 'Follow must call the existing API');
  assert.equal(posted.payload.action, 'toggle_favorite_facility');
  assert.equal(posted.payload.facility_id, 42);
  assert.equal(attributes['data-following'], 'false', 'no optimistic success');
  resolveResponse({ json: async () => ({ success: true, favorited: true }) });
  await pending;
  assert.equal(attributes['data-following'], 'true');
  assert.equal(context.currentSelectedFacility.is_favorited, true);
  const failure = context.toggleFollowFacility(button, 42, 'Courts');
  resolveResponse({ json: async () => ({ success: false, message: 'Denied' }) });
  await failure;
  assert.equal(attributes['data-following'], 'true');
  assert.equal(button.disabled, false);
});
