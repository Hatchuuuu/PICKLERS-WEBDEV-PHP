const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
test('registered staff lookup renders keyboard-operable choices with escaped identifiers', () => {
  const source = fs.readFileSync('public/assets/js/owner.js', 'utf8');
  const c = { highlightMatchText: text => text };
  vm.createContext(c);
  vm.runInContext(source.slice(source.indexOf('function escapeHtmlAttr('), source.indexOf('function renderRequestCard(')), c);
  vm.runInContext(source.slice(source.indexOf('function renderPlayerAutocomplete('), source.indexOf('function searchPlayersLocalAndApi(')), c);
  const box = { style: {} };
  c.renderPlayerAutocomplete(box, [{ id: 'user', name: '<Paula>', email: 'p@fixture.test' }], 'Pa');
  assert.match(box.innerHTML, /<button type="button" class="autocomplete-item"/);
  assert.ok(box.innerHTML.includes('&lt;Paula&gt;'));
  assert.doesNotMatch(box.innerHTML, /<Paula>/);
  const template = fs.readFileSync('templates/web/partials/owner/_modals.html.twig', 'utf8');
  assert.match(template, /<label for="staffNameInput"/);
  assert.match(template, /<label for="staffEmailInput"/);
});

test('selecting a suggestion moves focus out of the hidden result into the form', () => {
  const source = fs.readFileSync('public/assets/js/owner.js', 'utf8');
  let focused = false;
  const fields = { staffNameInput: { dataset: {} }, staffEmailInput: { focus() { focused = true; } }, staffNameAutocomplete: { style: {} } };
  const c = { window: {}, document: { getElementById: id => fields[id] }, showToast() {} };
  vm.createContext(c);
  vm.runInContext(source.slice(source.indexOf('function selectStaffPlayer('), source.indexOf('function selectWalkinPlayer(')), c);
  c.selectStaffPlayer('Paula', 'p@fixture.test', 'user');
  assert.equal(focused, true);
  assert.equal(fields.staffNameAutocomplete.style.display, 'none');
});
