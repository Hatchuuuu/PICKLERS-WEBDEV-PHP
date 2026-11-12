const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const vm = require('node:vm');
const { test } = require('node:test');
const source = readFileSync(require('node:path').join(__dirname, '../../public/assets/js/app.js'), 'utf8');
function harness() {
  const requests = [], timers = new Map(), timeouts = new Map(), events = new Map();
  const button = { textContent: 'Join', disabled: false, style: {}, classList: { add() {}, remove() {} }, hasAttribute() { return this.joined || false; }, setAttribute() { this.joined = true; } };
  const circle = {}, filled = {}, remain = {};
  const card = { querySelector: s => ({ '.openplay-spots-circle': circle, '.openplay-spots-filled': filled, '.openplay-spots-remain': remain, '.openplay-card-footer button': button })[s] };
  const listeners = target => ({ addEventListener(name, fn) { events.set(target + name, fn); }, removeEventListener(name, fn) { if (events.get(target + name) === fn) events.delete(target + name); } });
  const document = { hidden: false, querySelector: () => card, ...listeners('document') };
  const context = { document, window: listeners('window'), AbortController, setTimeout(fn, ms) { assert.equal(ms, 15000); const id = timeouts.size + 1; timeouts.set(id, fn); return id; }, clearTimeout(id) { timeouts.delete(id); }, setInterval(fn, ms) { assert.equal(ms, 4000); const id = timers.size + 1; timers.set(id, fn); return id; }, clearInterval(id) { timers.delete(id); }, fetch(url, options) { return new Promise((resolve, reject) => requests.push({ signal: options?.signal, resolve: current => resolve({ ok: true, json: async () => ({ success: true, matches: [{ id: '1', current_players: current, max_players: 2 }] }) }), reject })); } };
  vm.createContext(context);
  vm.runInContext(source.slice(source.indexOf('let openPlaySyncInterval =')), context);
  context.initRealTimeOpenPlaySync();
  const tick = () => [...timers.values()].forEach(fn => fn());
  const flush = () => new Promise(resolve => setImmediate(resolve));
  return { context, requests, button, circle, tick, flush, expire: () => [...timeouts.values()].forEach(fn => fn()), fire: key => events.get(key)?.() };
}
test('match poll keeps one pending request and retries after failure', async () => {
  const h = harness(); h.tick(); h.tick();
  assert.equal(h.requests.length, 1);
  h.requests[0].reject(new Error('network')); await h.flush(); h.tick();
  assert.equal(h.requests.length, 2);
  h.requests[1].resolve(2); await h.flush();
  assert.equal(h.button.textContent, 'Full'); assert.equal(h.button.disabled, true);
  h.tick(); h.requests[2].resolve(1); await h.flush();
  assert.equal(h.button.textContent, 'Join'); assert.equal(h.button.disabled, false);
});
test('hidden response is discarded and visibility return refreshes without overlap', async () => {
  const h = harness(); h.tick();
  h.context.document.hidden = true; h.fire('documentvisibilitychange'); h.tick();
  h.context.document.hidden = false; h.fire('documentvisibilitychange');
  assert.equal(h.requests.length, 1);
  h.requests[0].resolve(1); await h.flush();
  assert.equal(h.circle.textContent, undefined); assert.equal(h.requests.length, 2);
  h.requests[1].resolve(2); await h.flush(); assert.equal(h.button.textContent, 'Full');
});
test('page lifecycle discards old results and preserves Joined', async () => {
  const h = harness(); h.tick(); h.fire('windowpagehide'); h.tick();
  h.requests[0].resolve(1); await h.flush(); assert.equal(h.circle.textContent, undefined);
  h.fire('windowpageshow'); assert.equal(h.requests.length, 2);
  h.button.textContent = 'Joined'; h.requests[1].resolve(2); await h.flush();
  assert.equal(h.button.textContent, 'Joined'); assert.equal(h.button.disabled, true);
});
test('reinitialization ignores old responses and removes the old timer', async () => {
  const h = harness(); h.tick(); h.context.initRealTimeOpenPlaySync(); h.tick();
  assert.equal(h.requests.length, 1);
  h.requests[0].resolve(1); await h.flush(); assert.equal(h.circle.textContent, undefined);
  h.requests[1].resolve(2); await h.flush();
  assert.equal(h.button.textContent, 'Full');
});
test('hung request times out, aborts, and cannot overwrite a fresh result', async () => {
  const h = harness(); h.tick(); h.expire(); await h.flush(); h.tick();
  assert.equal(h.requests.length, 2);
  assert.equal(h.requests[0].signal.aborted, true);
  h.requests[0].resolve(1); await h.flush();
  assert.equal(h.circle.textContent, undefined);
  h.tick(); assert.equal(h.requests.length, 2);
  h.requests[1].resolve(2); await h.flush();
  assert.equal(h.button.textContent, 'Full'); assert.equal(h.button.disabled, true);
  h.tick(); assert.equal(h.requests.length, 3);
});
