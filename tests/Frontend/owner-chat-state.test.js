const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const vm = require('node:vm');
const { test } = require('node:test');

function setup(initial = 'A') {
  const pending = [], intervals = new Map();
  const elements = { chatThreadContainer: { innerHTML: 'initial A', scrollHeight: 10 },
    chatMessageInput: { value: '' }, chatActiveUser: {}, chatActiveAvatar: {} };
  const c = { window: { __initialChatPartnerId: initial }, document: {
    getElementById: id => elements[id], querySelectorAll: () => [],
    createElement: () => ({ set textContent(text) { this.innerHTML = text; } }) },
    apiUrl: action => '/api?action=' + action, getCsrfToken: () => 'csrf', showToast() {},
    setInterval: callback => { const id = intervals.size + 1; intervals.set(id, callback); return id; },
    clearInterval: id => intervals.delete(id),
    fetch: (url, options) => new Promise((resolve, reject) => pending.push({ url, options,
      resolve: result => resolve({ json: async () => result }), reject })) };
  vm.createContext(c);
  const source = readFileSync('public/assets/js/owner.js', 'utf8');
  vm.runInContext(source.slice(source.indexOf('let activeChatPartnerId'), source.indexOf('// Edit Court Modal Handlers')), c);
  return { c, pending, intervals, elements, thread: elements.chatThreadContainer, input: elements.chatMessageInput };
}
const flush = () => new Promise(resolve => setImmediate(resolve));
const message = (sender, content) => ({ success: true, messages: [{ sender_id: sender, content }] });

test('switching clears old thread and reversed replies keep B under B header', async () => {
  const { c, pending, thread, elements } = setup();
  c.selectConversation('A', 'Player A');
  c.selectConversation('B', 'Player B');
  assert.equal(thread.innerHTML, '');
  pending[1].resolve(message('B', 'B reply')); await flush();
  pending[0].resolve(message('A', 'A reply')); await flush();
  assert.equal(elements.chatActiveUser.innerText, 'Player B');
  assert.match(thread.innerHTML, /from-user.*B reply/);
  assert.doesNotMatch(thread.innerHTML, /A reply/);
});

test('empty or failed B loads cannot restore A messages', async () => {
  for (const result of [{ success: true, messages: [] }, { success: false }, null]) {
    const { c, pending, thread } = setup();
    c.loadChatThread('A'); c.selectConversation('B', 'Player B');
    if (result) pending[1].resolve(result); else pending[1].reject(new Error('offline'));
    await flush(); pending[0].resolve(message('A', 'A reply')); await flush();
    assert.equal(thread.innerHTML, '');
  }
});

test('polling latest generation wins even when switching A to B to A', async () => {
  const { c, pending, intervals, thread } = setup();
  c.loadChatThread('A'); pending[0].resolve(message('A', 'initial reply')); await flush();
  assert.match(thread.innerHTML, /initial reply/);
  c.selectConversation('A', 'A'); c.selectConversation('B', 'B'); c.selectConversation('A', 'A');
  assert.equal(intervals.size, 1); [...intervals.values()][0]();
  pending[4].resolve(message('A', 'newest')); await flush();
  pending[3].resolve(message('A', 'older poll')); pending[1].resolve(message('A', 'old A'));
  pending[2].resolve(message('B', 'old B')); await flush();
  assert.match(thread.innerHTML, /newest/); assert.doesNotMatch(thread.innerHTML, /old/);
});

test('A send completion preserves B draft and does not reload B', async () => {
  const { c, pending, input } = setup();
  input.value = '  A draft  '; c.sendChatMessage();
  assert.equal(JSON.parse(pending[0].options.body).partner_id, 'A');
  assert.equal(JSON.parse(pending[0].options.body).content, 'A draft');
  c.selectConversation('B', 'B'); input.value = 'B draft';
  pending[0].resolve({ success: true }); await flush();
  assert.equal(input.value, 'B draft'); assert.equal(pending.length, 2);
});

test('same text entered for B survives A send completion', async () => {
  const { c, pending, input } = setup();
  input.value = 'same draft'; c.sendChatMessage();
  c.selectConversation('B', 'B'); input.value = 'same draft';
  pending[0].resolve({ success: true }); await flush();
  assert.equal(input.value, 'same draft'); assert.equal(pending.length, 2);
});

test('send failure keeps draft and releases existing single-send guard', async () => {
  for (const failed of [false, true]) {
    const { c, pending, input } = setup();
    input.value = 'retry draft'; c.sendChatMessage(); c.sendChatMessage();
    assert.equal(pending.length, 1);
    if (failed) pending[0].reject(new Error('offline'));
    else pending[0].resolve({ success: false });
    await flush(); assert.equal(input.value, 'retry draft');
    c.sendChatMessage(); assert.equal(pending.length, 2);
  }
});

test('send clears only unchanged submitted draft and reloads the same recipient', async () => {
  for (const edited of [false, true]) {
    const { c, pending, input } = setup();
    input.value = '  A draft  '; c.sendChatMessage();
    if (edited) input.value = 'next A draft';
    pending[0].resolve({ success: true }); await flush();
    assert.equal(input.value, edited ? 'next A draft' : '');
    assert.match(pending[1].url, /partner_id=A$/);
  }
});

test('unsent drafts belong to their recipient and restore when revisiting', () => {
  const { c, input } = setup();
  input.value = 'Private A draft'; c.selectConversation('B', 'B');
  assert.equal(input.value, '');
  input.value = 'Private B draft'; c.selectConversation('A', 'A');
  assert.equal(input.value, 'Private A draft');
  c.selectConversation('B', 'B'); assert.equal(input.value, 'Private B draft');
  c.selectConversation('B', 'B'); assert.equal(input.value, 'Private B draft');
});

test('completed sends clear the saved recipient draft without losing a newer draft', async () => {
  for (const edited of [false, true]) {
    const { c, input, pending } = setup();
    input.value = 'sent A draft'; c.sendChatMessage();
    c.selectConversation('B', 'B');
    if (edited) { c.selectConversation('A', 'A'); input.value = 'new A draft'; c.selectConversation('B', 'B'); }
    pending[0].resolve({ success: true }); await flush();
    c.selectConversation('A', 'A');
    assert.equal(input.value, edited ? 'new A draft' : '');
  }
});

test('a spaced submitted draft clears only its original recipient after switching away', async () => {
  const { c, input, pending } = setup();
  input.value = '  A draft  '; c.sendChatMessage();
  assert.equal(JSON.parse(pending[0].options.body).content, 'A draft');
  c.selectConversation('B', 'B'); input.value = 'B draft';
  pending[0].resolve({ success: true }); await flush();
  assert.equal(input.value, 'B draft');
  c.selectConversation('A', 'A'); assert.equal(input.value, '');
  c.selectConversation('B', 'B'); assert.equal(input.value, 'B draft');
});

test('polling keeps a reader in older messages but follows updates near the bottom', async () => {
  const { c, thread, pending } = setup();
  thread.scrollHeight = 1000; thread.clientHeight = 300; thread.scrollTop = 100;
  c.loadChatThread('A'); pending[0].resolve(message('A', 'new message')); await flush();
  assert.equal(thread.scrollTop, 100);
  thread.scrollTop = 690;
  c.loadChatThread('A'); pending[1].resolve(message('A', 'another message')); await flush();
  assert.equal(thread.scrollTop, 1000);
});

test('own successful send follows the current thread even when reading older messages', async () => {
  const { c, thread, input, pending } = setup();
  thread.scrollHeight = 1000; thread.clientHeight = 300; thread.scrollTop = 100;
  input.value = 'reply'; c.sendChatMessage(); pending[0].resolve({ success: true }); await flush();
  pending[1].resolve(message('owner', 'reply')); await flush();
  assert.equal(thread.scrollTop, 1000);
});
