const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const vm = require('node:vm');
const source = readFileSync('public/assets/js/owner.js', 'utf8');

function setup() {
  let resolveSave, rejectSave;
  const form = new Map(), statuses = {}, requests = [], notices = [];
  const originalLogo = { name: 'original-logo.png' };
  const elements = { gcashNumberInput: { value: '09111111111', defaultValue: '' },
    mayaNumberInput: { value: '09222222222', defaultValue: '' }, facilityNameInput: { value: 'Venue' },
    facilityLocationInput: { value: 'Street' }, logoFileInput: { value: 'original-logo.png', files: [originalLogo] } };
  const context = { document: { getElementById: id => elements[id] },
    FormData: class { append(key, value) { form.set(key, value); } }, getCsrfToken: () => '',
    ownerUrl: () => '/save', showToast: (...args) => notices.push(args), setPayoutStatus: (type, state) => statuses[type] = state,
    fetch: () => new Promise((resolve, reject) => { resolveSave = resolve; rejectSave = reject; requests.push({ resolve, reject }); }) };
  vm.createContext(context);
  vm.runInContext(source.slice(source.indexOf('function saveFacilitySettings('), source.indexOf('// Daily Revenue Breakdown')), context);
  return { context, elements, statuses, form, requests, notices,
    finish: () => resolveSave({ json: async () => ({ success: true }) }), fail: () => rejectSave(new Error('offline')) };
}

test('settings completion marks submitted payout values saved while preserving newer edits', async () => {
  const { context, elements, statuses, form, finish } = setup();
  const save = context.saveFacilitySettings();
  elements.gcashNumberInput.value = '09333333333';
  finish(); await save;
  assert.equal(form.get('gcash_number'), '09111111111');
  assert.equal(elements.gcashNumberInput.value, '09333333333');
  assert.equal(elements.gcashNumberInput.defaultValue, '09111111111');
  assert.equal(statuses.gcash, 'modified');
  assert.equal(statuses.maya, 'saved');
  assert.equal(elements.mayaNumberInput.defaultValue, '09222222222');
});

test('settings completion preserves a newer logo selection but clears the uploaded selection', async () => {
  for (const newer of [false, true]) {
    const { context, elements, finish } = setup();
    const save = context.saveFacilitySettings();
    if (newer) { elements.logoFileInput.files = [{ name: 'newer-logo.png' }]; elements.logoFileInput.value = 'newer-logo.png'; }
    finish(); await save;
    assert.equal(elements.logoFileInput.value, newer ? 'newer-logo.png' : '');
  }
});

test('all settings save entry points share a pending guard and allow retry after success or failure', async () => {
  for (const failed of [false, true]) {
    const h = setup(), first = h.context.saveFacilitySettings();
    h.elements.gcashNumberInput.value = '09333333333';
    const duplicateSave = h.context.saveFacilitySettings(null, true);
    assert.equal(h.requests.length, 1);
    const duplicate = await duplicateSave;
    assert.equal(duplicate.success, false);
    assert.match(duplicate.message, /already in progress/);
    await h.context.saveFacilitySettings();
    assert.equal(h.requests.length, 1);
    assert.equal(h.notices.at(-1)[1], 'info');
    if (failed) h.fail(); else h.finish();
    await first;
    const retry = h.context.saveFacilitySettings();
    assert.equal(h.requests.length, 2);
    h.finish(); await retry;
    assert.equal(h.elements.gcashNumberInput.defaultValue, '09333333333');
  }
});
