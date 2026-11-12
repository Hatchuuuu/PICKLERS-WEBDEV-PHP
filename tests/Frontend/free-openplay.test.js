const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const vm = require('node:vm');
const { test } = require('node:test');
const source = readFileSync('public/assets/js/app.js', 'utf8');
test('free Open Play stays zero from selection to payment summary', () => {
  let checkout;
  const elements = new Map();
  const context = { silentRefreshDiscover() {},
    sessionStorage: { setItem: (_, v) => { checkout = JSON.parse(v); } },
    window: { location: {}, scrollTo() {} },
    document: { querySelectorAll: () => [], querySelector: () => null, getElementById(id) { if (!elements.has(id)) elements.set(id, { style: {}, value: '', textContent: '' }); return elements.get(id); } },
    selectPaymentChannel() {},
  };
  vm.createContext(context);
  vm.runInContext(source.slice(source.indexOf('let currentBookingBaseTotal'), source.indexOf('function openPaymentReview(')), context);
  vm.runInContext(source.slice(source.indexOf('function joinOpenPlay('), source.indexOf('// ========================================================================', source.indexOf('function joinOpenPlay('))), context);
  vm.runInContext(source.slice(source.indexOf('function openPaymentReview('), source.indexOf('function backToCourtsView(')), context);
  context.joinOpenPlay('free', 'Arena', 'Court 1', '2030-01-01', '7:00 AM - 8:00 AM', 0);
  assert.equal(checkout.price, 0);
  context.openPaymentReview(1, '', 'Court 1', checkout.price, 'All Levels', 'Indoor', 'Arena', checkout.date, checkout.time, 2, 'free');
  assert.equal(elements.get('finalPrice').value, 0);
  assert.equal(elements.get('payTotalVal').textContent, '₱0');
  assert.equal(elements.get('payFeeVal').textContent, '₱0');
});
