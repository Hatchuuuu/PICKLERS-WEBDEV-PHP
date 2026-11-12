const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const vm = require('node:vm');
const { test } = require('node:test');
const source = readFileSync('public/assets/js/app.js', 'utf8');

test('Quick Book checks every selected hour on the server and fails closed', async () => {
  const results = { innerHTML: '' }, callbacks = [], requests = [];
  let networkFailure = false;
  const c = {
    document: { getElementById: id => id === 'qbAvailableCourtsList' ? results : id === 'qbSelectedDateFull' ? { value: '2026-10-12' } : null },
    cachedFacilityCourts: [{ id: 'c1', name: 'Court 1', status: 'available', price: 300 }, { id: 'c2', name: 'Court 2', status: 'available', price: 300 }],
    setTimeout: (fn, delay) => { if (delay === 950) callbacks.push(fn); },
    getMinAllowedStartIdx: () => 0, URLSearchParams, showToast() {},
    escapeHtml: value => value, jsAttr: JSON.stringify,
    fetch: async url => {
      const query = new URL(url, 'http://localhost/').searchParams;
      requests.push(query);
      if (networkFailure) throw new Error('Offline');
      return { ok: true, json: async () => ({ success: true, slots: [
        { label: '7:00 AM – 8:00 AM', available: true },
        { label: '8:00 AM – 9:00 AM', available: query.get('court_id') === 'c2' },
      ] }) };
    },
  };
  vm.createContext(c);
  vm.runInContext(source.slice(source.indexOf('const QB_TIMES ='), source.indexOf('let qbStartIdx')) + '\nlet qbStartIdx = 0, qbEndIdx = 2;\n' + source.slice(source.indexOf('function proceedToPaymentFromQuickBook('), source.indexOf('function bookCourtFromQuickBookResults(')), c);
  c.proceedToPaymentFromQuickBook();
  await callbacks.shift()();
  assert.equal(requests.length, 2);
  assert.equal(requests[0].get('date'), '2026-10-12');
  assert.equal(requests[0].get('action'), 'slot_availability');
  assert.ok(!results.innerHTML.includes('Court 1'));
  assert.ok(results.innerHTML.includes('Court 2'));
  networkFailure = true;
  c.proceedToPaymentFromQuickBook();
  await callbacks.shift()();
  assert.ok(results.innerHTML.includes('No Available Courts'));
  assert.ok(!results.innerHTML.includes('bookCourtFromQuickBookResults'));
});
