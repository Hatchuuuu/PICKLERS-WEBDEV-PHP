const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const vm = require('node:vm');
const source = readFileSync('public/assets/js/owner.js', 'utf8');
const decline = source.slice(source.indexOf('function confirmDecline('), source.indexOf('// Segmented View Switcher'));

for (const message of [
  'Reservation declined. ₱500.00 refunded to Pickle Credits.',
  'Reservation declined. No refund was issued in-app; follow up with the facility about GCash.',
  'Reservation declined. Nothing was charged for this Pay at Venue booking.',
]) {
  test('decline shows the persisted outcome: ' + message, async () => {
    const toasts = [];
    let sent;
    const context = {
      activeDeclineId: 'booking', declineBookingInFlight: false,
      setRequestReviewBusy() {},
      document: { querySelector: () => null, getElementById: () => ({ value: 'Court maintenance' }) }, apiUrl: () => '/api', getCsrfToken: () => 'dummy',
      fetch: async (url, options) => { sent = JSON.parse(options.body); return { json: async () => ({ success: true, message }) }; },
      removeRequestCard() {}, closeModal() {}, showToast: value => toasts.push(value), setTimeout() {},
    };
    vm.createContext(context);
    vm.runInContext(decline, context);
    context.confirmDecline();
    await new Promise(resolve => setImmediate(resolve));
    assert.deepEqual(toasts, [message]);
    assert.equal(sent.reason, 'Court maintenance');
  });
}

test('failed decline retains the request and shows the server error', async () => {
  let removed = false;
  const toasts = [];
  const context = {
    activeDeclineId: 'booking', declineBookingInFlight: false,
    setRequestReviewBusy() {},
    document: { querySelector: () => null, getElementById: () => null }, apiUrl: () => '/api', getCsrfToken: () => 'dummy',
    fetch: async () => ({ json: async () => ({ success: false, message: 'Already cancelled.' }) }),
    removeRequestCard() { removed = true; }, closeModal() {}, showToast: value => toasts.push(value), setTimeout() {},
  };
  vm.createContext(context);
  vm.runInContext(decline, context);
  context.confirmDecline();
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(removed, false);
  assert.deepEqual(toasts, ['Already cancelled.']);
});
