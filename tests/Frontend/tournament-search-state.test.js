const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { test } = require('node:test');
const vm = require('node:vm');
const flush = () => new Promise(resolve => setImmediate(resolve));

function setup(kind) {
  const pending = [], timers = new Map();
  const input = { value: '', dataset: {} };
  const classes = new Set();
  const box = { innerHTML: '', classList: { add: name => classes.add(name), remove: name => classes.delete(name) }, querySelectorAll: () => [] };
  const c = { window: {}, document: { getElementById: id => /Suggest/.test(id) ? box : (id === 'tournEntrantP2' ? { value: '' } : input) },
    setTimeout: fn => { const id = timers.size + 1; timers.set(id, fn); return id; }, clearTimeout: id => timers.delete(id),
    fetch: url => new Promise((resolve, reject) => pending.push({ url, reject, resolve: body => resolve({ json: async () => body }) })) };
  vm.createContext(c);
  if (kind === 'create') {
    c.tournSearchTimer = null; c.tournRosterDraft = []; c.apiUrl = action => '/api?action=' + action;
    c.highlightMatchText = name => name;
    const source = readFileSync('public/assets/js/owner.js', 'utf8');
    vm.runInContext(source.slice(source.indexOf('function escapeTournHtml('), source.indexOf('function addTournEntrantDraft(')) +
      source.slice(source.indexOf('function onTournEntrantSearch('), source.indexOf('function closeTournSuggestions(')), c);
  } else {
    c.cfg = { apiUrl: '/api' }; c.state = { tournament: {} };
    const source = readFileSync('public/assets/js/tournament.js', 'utf8');
    vm.runInContext(source.slice(source.indexOf('  function esc('), source.indexOf('  function byId(')) +
      source.slice(source.indexOf('  var searchTimer = null;'), source.indexOf('  // Champion celebration')), c);
  }
  const search = query => {
    input.value = query;
    if (kind === 'create') c.onTournEntrantSearch(1);
    else c.queueSearch(input, box);
  };
  const runTimers = () => { const queued = [...timers.values()]; timers.clear(); queued.forEach(fn => fn()); };
  return { input, box, classes, pending, search, runTimers };
}

for (const kind of ['create', 'bracket']) {
  test(`${kind} tournament suggestions ignore old success and failure after a newer search`, async () => {
    for (const failed of [false, true]) {
      const { box, classes, pending, search, runTimers } = setup(kind);
      search('Alice'); runTimers(); search('Bob'); runTimers();
      pending[1].resolve({ success: true, users: [{ id: 'b', name: 'Bob', email: 'b@example.test' }] }); await flush();
      if (failed) pending[0].reject(new Error('offline'));
      else pending[0].resolve({ success: true, users: [{ id: 'a', name: 'Alice', email: 'a@example.test' }] });
      await flush();
      assert.match(box.innerHTML, /Bob/); assert.doesNotMatch(box.innerHTML, /Alice/);
      assert.equal(classes.has('is-open'), true);
    }
  });

  test(`${kind} tournament old results cannot reappear while a changed or cleared query is debouncing`, async () => {
    for (const query of ['Bob', '']) {
      const { box, classes, pending, search, runTimers } = setup(kind);
      search('Alice'); runTimers(); search(query);
      pending[0].resolve({ success: true, users: [{ id: 'a', name: 'Alice', email: 'a@example.test' }] }); await flush();
      assert.doesNotMatch(box.innerHTML, /Alice/);
      assert.equal(classes.has('is-open'), false);
    }
  });
}
