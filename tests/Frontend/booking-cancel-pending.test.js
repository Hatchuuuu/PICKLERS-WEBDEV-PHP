const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/assets/js/app.js', 'utf8');
for (const success of [false, true]) test('cancellation locks its review and rejects duplicate submission: ' + success, async () => {
  let calls = 0, resolveRequest, closed = false;
  const attrs = {}, controls = [{ disabled: false }, { disabled: false }];
  const modal = { setAttribute: (key, value) => attrs[key] = value, querySelectorAll: () => controls, getAttribute: key => attrs[key] };
  const nodes = { cancelTargetBookingId: { value: 'booking' }, btnExecuteCancelBooking: controls[1], cancelBookingModal: modal };
  const c = { document: { getElementById: id => nodes[id] }, FormData: class { append() {} },
    getCsrfToken: () => 'fixture', showToast() {}, closeModal() { assert.equal(attrs['aria-busy'], 'false'); closed = true; }, setTimeout() {},
    fetch() { calls++; return new Promise(resolve => resolveRequest = resolve); } };
  vm.createContext(c);
  vm.runInContext(source.slice(source.indexOf('function executeBookingCancellation('), source.indexOf('function filterWalletTxns(')), c);
  c.executeBookingCancellation(); c.executeBookingCancellation();
  assert.equal(calls, 1);
  assert.equal(attrs['aria-busy'], 'true');
  assert.ok(controls.every(x => x.disabled));
  resolveRequest({ json: async () => ({ success, message: 'server result' }) });
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(attrs['aria-busy'], 'false');
  assert.ok(controls.every(x => !x.disabled));
  assert.equal(closed, success);
});
