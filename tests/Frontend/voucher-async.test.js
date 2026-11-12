const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const vm = require('node:vm');
const { test } = require('node:test');

function checkout() {
  const source = readFileSync('public/assets/js/app.js', 'utf8');
  const ids = ['voucherCodeInput', 'voucherMsg', 'voucherDiscountRow', 'voucherDiscountLabel', 'payDiscountVal', 'appliedVoucherDiscount', 'payTotalVal', 'btnPayTotalText', 'finalPrice', 'finalFacilityId', 'finalCourtId', 'finalCourtName', 'finalDate', 'finalTime', 'finalDuration', 'finalMatchId', 'btnConfirmFinalPay', 'paymentReviewView'];
  const elements = Object.fromEntries(ids.map(id => [id, { value: '', style: {}, disabled: false }]));
  const requests = [];
  const c = { silentRefreshDiscover() {}, document: { getElementById: id => elements[id] || null, querySelectorAll: () => [], querySelector: () => null }, URLSearchParams, window: { scrollTo() {} }, currentSelectedFacility: null, showToast() {}, getCsrfToken: () => '', fetch: (url, options) => new Promise((resolve, reject) => requests.push({ url, options, resolve: body => resolve({ json: async () => body }), reject })) };
  vm.createContext(c);
  vm.runInContext(source.slice(source.indexOf('let currentBookingBaseTotal'), source.indexOf('function openBookingReceiptModal(')), c);
  const open = (court = 'A', rate = 300) => c.openPaymentReview('1', court, 'Court 1', rate, 'Hard', 'Indoor', 'Facility', 'Tomorrow', '8 AM', 1);
  const apply = code => { elements.voucherCodeInput.value = code; c.applyVoucherCode(); };
  const settle = () => new Promise(resolve => setImmediate(resolve));
  const state = () => vm.runInContext('({code: currentBookingVoucherCode, discount: currentBookingVoucherDiscount})', c);
  open();
  return { c, elements, requests, open, apply, settle, state };
}
const quote = total => ({ success: true, promo_valid: true, quote: { discount: 330 - total, total } });

for (const match of [false, true]) for (const code of ['', 'SECOND']) test(`Apply ${code || 'empty'} cannot unlock an unresolved ${match ? 'Open Play' : 'court'} reservation`, async () => {
  const h = checkout(); if (match) h.elements.finalMatchId.value = '9';
  h.c.executeFinalBooking();
  h.apply(code);
  assert.equal(h.elements.btnConfirmFinalPay.disabled, true);
  assert.equal(h.requests.length, 1);
  h.c.executeFinalBooking(); assert.equal(h.requests.length, 1);
  h.c.backToCourtsView(); h.open();
  assert.equal(h.elements.btnConfirmFinalPay.disabled, true);
  h.c.executeFinalBooking(); assert.equal(h.requests.length, 1);
  h.requests[0].resolve({ success: false, message: 'Rejected' }); await h.settle();
  assert.equal(h.elements.btnConfirmFinalPay.disabled, false);
  h.c.executeFinalBooking(); assert.equal(h.requests.length, 2);
});
for (const match of [false, true]) test(`reservation network failure releases ${match ? 'Open Play' : 'court'} submission lock`, async () => {
  const h = checkout(); if (match) h.elements.finalMatchId.value = '9';
  h.c.executeFinalBooking(); h.requests[0].reject(new Error('offline')); await h.settle();
  assert.equal(h.elements.btnConfirmFinalPay.disabled, false);
  h.c.executeFinalBooking(); assert.equal(h.requests.length, 2);
});

test('late A quote cannot overwrite B checkout', async () => {
  const h = checkout(); h.apply('FIRST'); h.open('B', 600);
  h.requests[0].resolve(quote(300)); await h.settle();
  assert.equal(h.elements.finalPrice.value, 660);
  assert.equal(h.state().code, '');
});
test('newer voucher wins even when older response arrives last', async () => {
  const h = checkout(); h.apply('FIRST'); h.apply('SECOND');
  h.requests[1].resolve(quote(200)); await h.settle();
  h.requests[0].resolve(quote(300)); await h.settle();
  assert.equal(h.elements.finalPrice.value, 200); assert.equal(h.state().code, 'SECOND');
});
for (const failure of ['invalid', 'network', 'empty']) test(`${failure} replacement clears the old code and discount`, async () => {
  const h = checkout(); h.apply('FIRST'); h.requests[0].resolve(quote(200)); await h.settle();
  h.apply(failure === 'empty' ? '' : 'SECOND');
  if (failure === 'network') h.requests[1].reject(new Error('offline'));
  if (failure === 'invalid') h.requests[1].resolve({ success: true, promo_valid: false });
  await h.settle();
  assert.equal(h.state().code, ''); assert.equal(h.state().discount, 0);
  assert.equal(h.elements.finalPrice.value, 330); assert.equal(h.elements.voucherDiscountRow.style.display, 'none');
});
test('back and reopen same court invalidates earlier quote and restores controls', async () => {
  const h = checkout(); h.apply('FIRST'); h.c.backToCourtsView(); h.open();
  h.requests[0].resolve(quote(0)); await h.settle();
  assert.equal(h.elements.finalPrice.value, 330); assert.equal(h.elements.voucherCodeInput.disabled, false);
  assert.equal(h.elements.btnConfirmFinalPay.disabled, false);
});
test('hidden checkout ignores a late quote', async () => {
  const h = checkout(); h.apply('FIRST'); h.elements.paymentReviewView.style.display = 'none';
  h.requests[0].resolve(quote(0)); await h.settle();
  assert.equal(h.elements.finalPrice.value, 330); assert.equal(h.state().code, '');
});
test('changed booking time invalidates the quote even for the same court', async () => {
  const h = checkout(); h.apply('FIRST'); h.elements.finalTime.value = '9 AM';
  h.requests[0].resolve(quote(0)); await h.settle();
  assert.equal(h.elements.finalPrice.value, 330); assert.equal(h.state().code, '');
});
test('submit waits for pending quote then submits the confirmed code', async () => {
  const h = checkout(); h.apply('FREE'); h.c.executeFinalBooking();
  assert.equal(h.requests.length, 1); assert.equal(h.elements.btnConfirmFinalPay.disabled, true);
  h.requests[0].resolve(quote(0)); await h.settle();
  assert.equal(h.elements.finalPrice.value, 0); assert.equal(h.elements.btnConfirmFinalPay.disabled, false);
  h.c.executeFinalBooking(); assert.equal(JSON.parse(h.requests[1].options.body).promo_code, 'FREE');
  let receipt;
  h.c.openBookingReceiptModal = value => { receipt = value; };
  h.c.ensureNotificationPermission = () => {};
  h.requests[1].resolve({ success: true, booking: { id: 'PKL-123', price: 17 } }); await h.settle();
  assert.equal(receipt.price, 17);
});

for (const match of [false, true]) for (const outcome of ['success', 'failure', 'network']) test(`late ${match ? 'Open Play' : 'court'} ${outcome} preserves replacement checkout`, async () => {
  const h = checkout(); if (match) h.elements.finalMatchId.value = '9';
  const receipts = [], notices = [];
  h.c.openBookingReceiptModal = receipt => receipts.push(receipt);
  h.c.showToast = message => notices.push(message);
  h.c.ensureNotificationPermission = () => {};
  h.c.cachedFacilityCourts = [];
  h.c.executeFinalBooking(); h.c.backToCourtsView(); h.open('B', 600);
  if (outcome === 'network') h.requests[0].reject(new Error('offline'));
  else h.requests[0].resolve({ success: outcome === 'success', message: 'Rejected', booking: { id: 'PKL-A', price: 17 } });
  await h.settle();
  assert.equal(h.elements.paymentReviewView.style.display, 'block');
  assert.equal(h.elements.finalPrice.value, 660);
  assert.match(h.elements.btnConfirmFinalPay.innerHTML, /₱660/);
  assert.equal(h.elements.btnConfirmFinalPay.disabled, false);
  assert.equal(receipts.length, 0);
  if (outcome === 'success') assert.ok(notices.some(message => message.includes('PKL-A')));
  h.c.executeFinalBooking(); assert.equal(h.requests.length, 2);
});

test('back then reopening identical checkout invalidates the old reservation completion', async () => {
  const h = checkout(); h.c.openBookingReceiptModal = () => assert.fail('old receipt must not interrupt checkout'); h.c.ensureNotificationPermission = () => {};
  h.c.executeFinalBooking(); h.c.backToCourtsView(); h.open();
  h.requests[0].resolve({ success: true, booking: { id: 'PKL-A', price: 330 } }); await h.settle();
  assert.equal(h.elements.paymentReviewView.style.display, 'block');
});
