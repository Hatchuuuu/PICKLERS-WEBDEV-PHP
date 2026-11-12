const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { test } = require('node:test');
const vm = require('node:vm');

const source = readFileSync('public/assets/js/owner.js', 'utf8');
const flush = () => new Promise(resolve => setImmediate(resolve));

function setup() {
  const pending = [];
  const elements = Object.fromEntries(['openPlayRosterTitle', 'openPlayRosterSubtitle', 'openPlayRosterList', 'openPlayRosterCountBadge']
    .map(id => [id, { innerHTML: '', textContent: '' }]));
  const c = { window: {}, console: { error() {} }, document: { getElementById: id => elements[id] },
    openModal() {}, apiUrl: action => '/api?action=' + action,
    fetch: (url, options) => new Promise((resolve, reject) => pending.push({ url, options, reject,
      resolve: (body, ok = true) => resolve({ ok, json: async () => body }) })) };
  vm.createContext(c);
  vm.runInContext(source.slice(source.indexOf('function safeHtmlStr('), source.indexOf('// Court Schedule Roster Modal Handler')), c);
  return { c, pending, elements, list: elements.openPlayRosterList, count: elements.openPlayRosterCountBadge };
}

const roster = name => ({ success: true, roster: [{ name, email: 'player@example.test', payment_status: 'Pickle Credits', fee: 250 }] });

test('latest court roster keeps its players and count when older success or failure arrives', async () => {
  for (const failed of [false, true]) {
    const { c, pending, elements, list, count } = setup();
    c.openOpenPlayRosterModal('court-A', 'Court A', 'Morning');
    c.openOpenPlayRosterModal('court-B', 'Court B', 'Evening');
    pending[1].resolve(roster('B player')); await flush();
    if (failed) pending[0].reject(new Error('offline'));
    else pending[0].resolve(roster('A player'));
    await flush();
    assert.equal(elements.openPlayRosterTitle.textContent, 'Court B Roster');
    assert.match(list.innerHTML, /B player/);
    assert.doesNotMatch(list.innerHTML, /A player|Failed to load/);
    assert.equal(count.textContent, '1 Player Joined');
  }
});

test('opening another roster clears its predecessor count while loading', async () => {
  const { c, pending, count } = setup();
  c.openOpenPlayRosterModal('court-A', 'Court A');
  pending[0].resolve(roster('A player')); await flush();
  c.openOpenPlayRosterModal('court-B', 'Court B');
  assert.doesNotMatch(count.textContent, /1 Player Joined/);
  assert.match(count.textContent, /Loading/);
});

test('API rejection and malformed roster show a retryable error rather than no players', async () => {
  for (const body of [{ success: false, message: '<Permission denied>' }, { success: true }, { success: true, roster: {} }]) {
    const { c, pending, list, count } = setup();
    c.openOpenPlayRosterModal('court-A', 'Court A');
    pending[0].resolve(body, body.success); await flush();
    assert.doesNotMatch(list.innerHTML, /No Players Joined Yet|<Permission denied>/);
    assert.match(list.innerHTML, /roster|Permission denied/);
    assert.match(list.innerHTML, /retry|try again/);
    assert.doesNotMatch(count.textContent, /Joined/);
    c.openOpenPlayRosterModal('court-A', 'Court A');
    pending[1].resolve({ success: true, roster: [] }); await flush();
    assert.match(list.innerHTML, /No Players Joined Yet/);
    assert.equal(count.textContent, '0 Players Joined');
  }
});
