const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const vm = require('node:vm');
const source = readFileSync('public/assets/js/owner.js', 'utf8');
const revoke = source.slice(source.indexOf('function executeRevokeStaff('), source.indexOf('function removeStaffRow('));

async function revokeCards(success, legacy = false) {
  const cards = [
    { facilityId: '1', userId: legacy ? '' : 'player-a' },
    { facilityId: '1', userId: legacy ? '' : 'player-a' },
    { facilityId: '2', userId: 'player-a' },
    { facilityId: '1', userId: 'player-b' },
  ].map(dataset => ({ dataset, style: {}, removed: false, remove() { this.removed = true; } }));
  const context = {
    revokeStaffInFlight: false, pendingRevokeStaffId: 'staff-a', pendingRevokeStaffName: 'Player',
    pendingRevokeStaffBtn: { closest: () => cards[0] },
    document: { getElementById: () => null, querySelector: () => null, querySelectorAll: () => cards },
    closeModal() {}, showToast() {}, ownerUrl: () => '/owner.php', getCsrfToken: () => 'dummy',
    setTimeout: callback => callback(), fetch: async () => ({ json: async () => ({ success }) }),
  };
  vm.createContext(context);
  vm.runInContext(revoke, context);
  context.executeRevokeStaff();
  await new Promise(resolve => setImmediate(resolve));
  return cards.map(card => card.removed);
}

test('revoke removes every same venue and stable user card only after success', async () => {
  assert.deepEqual(await revokeCards(true), [true, true, false, false]);
});
test('failed revoke retains all cards', async () => {
  assert.deepEqual(await revokeCards(false), [false, false, false, false]);
});
test('legacy rows without stable identity remove only clicked card', async () => {
  assert.deepEqual(await revokeCards(true, true), [true, false, false, false]);
});
