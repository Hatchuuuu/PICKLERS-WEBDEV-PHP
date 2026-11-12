const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const vm = require('node:vm');
const source = readFileSync('public/assets/js/owner.js', 'utf8');
const select = source.slice(source.indexOf('function selectStaffPlayer('), source.indexOf('function selectWalkinPlayer('));
const submit = source.slice(source.indexOf('let addStaffInFlight'), source.indexOf('let pendingRevokeStaffId'));

test('staff selection submits stable identity and refuses edited identity', async () => {
  const fields = { staffNameInput: { value: '', dataset: {} }, staffEmailInput: { value: '', focus() {} }, staffRoleInput: { value: 'Front Desk' } };
  let sent;
  const context = {
    document: { getElementById: id => fields[id] }, window: {},
    showToast() {}, closeModal() {}, ownerUrl: () => '/owner.php', getCsrfToken: () => 'dummy',
    fetch: async (_url, request) => { sent = JSON.parse(request.body); return { json: async () => ({ success: false }) }; },
  };
  vm.createContext(context);
  vm.runInContext(select + submit, context);
  const event = { preventDefault() {}, target: { querySelector: () => null } };
  context.selectStaffPlayer('Paula', 'paula@example.test', 'player-a');
  context.submitAddStaffForm(event);
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(sent.user_id, 'player-a');
  sent = undefined;
  fields.staffEmailInput.value = 'other@example.test';
  context.submitAddStaffForm(event);
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(sent, undefined, 'changing selected identity must require a fresh selection');
});

test('staff search requests eligible players while walk-in keeps general search', async () => {
  const urls = [];
  const fields = { staffNameInput: { value: 'Pa', dataset: {} }, walkin_name: { value: 'Pa' } };
  const context = { document: { getElementById: id => fields[id] || {} }, window: {},
    apiUrl: () => '/api?action=search_players', clearStaffSelection() {}, renderPlayerAutocomplete() {},
    fetch: async url => { urls.push(url); return { json: async () => ({ success: true, users: [] }) }; } };
  vm.createContext(context);
  vm.runInContext(source.slice(source.indexOf('function searchPlayersLocalAndApi('), source.indexOf('// Hide autocomplete dropdowns')), context);
  context.onStaffNameInput('Pa');
  context.onWalkinNameInput('Pa');
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(new URL(urls[0], 'https://example.test').searchParams.get('context'), 'staff');
  assert.equal(new URL(urls[1], 'https://example.test').searchParams.has('context'), false);
});
