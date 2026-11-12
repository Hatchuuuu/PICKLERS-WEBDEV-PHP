const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const vm = require('node:vm');
const { test } = require('node:test');

test('an authoritative zero voucher total remains zero in checkout', async () => {
  const source = readFileSync('public/assets/js/app.js', 'utf8');
  const elements = Object.fromEntries(['voucherCodeInput', 'voucherMsg', 'payTotalVal', 'btnPayTotalText', 'finalPrice'].map(id => [id, { value: id === 'voucherCodeInput' ? 'FREE' : '', style: {} }]));
  const c = { document: { getElementById: id => elements[id] || null }, URLSearchParams, currentBookingBaseTotal: 330,
    fetch: async () => ({ json: async () => ({ success: true, promo_valid: true, quote: { discount: 330, total: 0 } }) }) };
  vm.createContext(c);
  vm.runInContext(source.slice(source.indexOf('let currentBookingBaseTotal'), source.indexOf('function executeFinalBooking(')), c);
  c.applyVoucherCode();
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(elements.finalPrice.value, 0);
  assert.equal(elements.payTotalVal.textContent, '₱0');
  assert.equal(elements.btnPayTotalText.textContent, '₱0');
});
