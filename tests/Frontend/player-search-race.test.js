const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const vm = require('node:vm');
const { test } = require('node:test');

test('late autocomplete responses cannot replace the current staff or walk-in search', () => {
  const source = readFileSync('public/assets/js/owner.js', 'utf8');
  for (const [fn, id] of [['onStaffNameInput', 'staffNameInput'], ['onWalkinNameInput', 'walkin_name']]) {
    const pending = [], rendered = [], input = { value: '', dataset: {} };
    const c = { document: { getElementById: key => key === id ? input : {} }, window: {},
      clearStaffSelection() {}, searchPlayersLocalAndApi: (query, callback) => pending.push(callback),
      renderPlayerAutocomplete: (box, users) => rendered.push(users) };
    vm.createContext(c);
    vm.runInContext(source.slice(source.indexOf('function onStaffNameInput('), source.indexOf('// Hide autocomplete dropdowns')), c);
    c[fn]('');
    input.value = 'Paula'; c[fn]('Paula');
    pending[1]([{ name: 'Paula' }]); pending[0]([{ name: 'Alex' }]);
    assert.deepEqual(rendered, [[{ name: 'Paula' }]]);
  }
});
