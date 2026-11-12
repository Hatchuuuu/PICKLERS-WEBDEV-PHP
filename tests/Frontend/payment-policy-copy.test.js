const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const vm = require('node:vm');
const { test } = require('node:test');
const source = readFileSync('public/assets/js/app.js', 'utf8');

test('eligible cancellation preview does not infer a 24-hour lead time from pending eligibility', () => {
  const elements = {};
  const context = { document: { getElementById: id => elements[id] ||= { style: {} } }, openModal() {} };
  vm.createContext(context);
  vm.runInContext(source.slice(source.indexOf('function promptCancelBooking('), source.indexOf('function executeBookingCancellation(')), context);
  context.promptCancelBooking('pending', 'Today', '1', 'Pickle Credits');
  assert.match(elements.cancelRefundDetailsText.textContent, /eligible under the cancellation policy/);
  assert.doesNotMatch(elements.cancelRefundDetailsText.textContent, /at least 24 hours/);
  context.promptCancelBooking('confirmed', 'Today', '', 'Pickle Credits');
  assert.match(elements.cancelRefundDetailsText.textContent, /no refund/);
  context.promptCancelBooking('external', 'Today', '', 'GCash');
  assert.match(elements.cancelRefundDetailsText.textContent, /contact the facility/);
  assert.match(elements.cancelRefundDetailsText.textContent, /selected payment method is GCash/);
  assert.doesNotMatch(elements.cancelRefundDetailsText.textContent, /was paid/);
});

test('wallet and policy explain current capabilities without promising unavailable payments', () => {
  const modals = readFileSync('templates/web/partials/app/_modals.html.twig', 'utf8');
  const wallet = readFileSync('templates/web/partials/app/_tab-wallet.html.twig', 'utf8');
  const checkout = readFileSync('templates/web/partials/app/_tab-play.html.twig', 'utf8');
  assert.doesNotMatch(wallet, /instant top-ups/);
  assert.match(modals, /Real-money top-ups are currently unavailable/);
  assert.match(modals, /Pending Pickle Credits bookings/);
  assert.doesNotMatch(modals, /at least 6 hours|end-to-end tokenization/);
  assert.doesNotMatch(modals, /support@picklers\.ph|8888-PICK/);
  assert.match(modals, /No monitored support email or hotline is configured/);
  assert.match(checkout, /This local demo does not collect external payments/);
  assert.doesNotMatch(checkout, /Instant QR|Visa, or Mastercard|100% Secure Checkout|Zero fees/);
});

test('payment method choices keep native keyboard radio state and submitted method in sync', () => {
  const elements = {};
  const context = { document: { getElementById: id => elements[id] ||= { classList: { add() {}, remove() {} } } } };
  vm.createContext(context);
  vm.runInContext(source.slice(source.indexOf('function selectPaymentChannel('), source.indexOf('function applyVoucherCode(')), context);
  const choices = [['GCash', 'payMethodGCash'], ['Maya', 'payMethodMaya'], ['Pickle Credits', 'payMethodCredits'], ['Cash on Site', 'payMethodCash']];
  for (const [selected, id] of choices) {
    context.selectPaymentChannel(selected);
    assert.equal(elements.finalPaymentMethod.value, selected);
    for (const [method, card] of choices) assert.equal(elements[card + 'Input'].checked, method === selected);
    assert.equal(elements[id + 'Input'].checked, true);
  }
});
