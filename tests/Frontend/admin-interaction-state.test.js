const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const vm = require('node:vm');
const { test } = require('node:test');
const source = readFileSync('public/assets/js/admin.js', 'utf8');
const flush = () => new Promise(resolve => setImmediate(resolve));

function setup() {
  const nodes = {}, requests = [], activity = [], history = [], notices = [], handlers = {};
  const document = { activeElement: null, body: { style: {} },
    getElementById: id => nodes[id], querySelectorAll: () => [],
    querySelector: () => nodes.opener, addEventListener() {},
    createElement: () => node(), createTextNode: text => ({ textContent: text }) };
  function node(id) {
    const classes = new Set(), attrs = {}, listeners = {};
    const n = { id, style: {}, dataset: {}, hidden: true, innerHTML: '', children: [],
      classList: { add: name => classes.add(name), remove: name => classes.delete(name), contains: name => classes.has(name) },
      setAttribute: (key, value) => attrs[key] = value, removeAttribute: key => delete attrs[key],
      getAttribute: key => attrs[key], addEventListener: (name, fn) => listeners[name] = fn,
      click() { listeners.click({ stopPropagation() {} }); }, appendChild(el) { this.children.push(el); },
      querySelector: selector => selector === '#detailTitle' ? nodes.detailTitle : nodes.navFirst,
      querySelectorAll() { return this.children; }, contains(el) { return this.children.includes(el); },
      getBoundingClientRect: () => ({ right: 100, bottom: 50, top: 10, width: 40, height: 40 }),
      offsetWidth: 100, offsetHeight: 100, focus() { document.activeElement = this; } };
    if (id) nodes[id] = n;
    return n;
  }
  ['adminPanel', 'detailBody', 'detailDialog', 'detailTitle', 'activityBtn', 'activityPanel', 'activityList',
    'activityState', 'activityDot', 'adminSidebar', 'sidebarOverlay', 'opener', 'navFirst', 'navLast'].forEach(node);
  nodes.adminSidebar.children = [nodes.navFirst, nodes.navLast];
  const window = { innerWidth: 390, innerHeight: 800, location: { href: 'http://localhost/admin' },
    history: { pushState: (_state, _title, href) => history.push(href), replaceState() {} },
    addEventListener: (name, fn) => handlers[name] = fn, scrollTo() {},
    UX: { openDialog: id => nodes[id].classList.add('ux-open'), closeDialog: id => nodes[id].classList.remove('ux-open'),
      lockScroll() { document.body.style.position = 'fixed'; }, unlockScroll() { document.body.style.position = ''; } } };
  const context = { document, window, URL, cfg: { detailUrl: '/detail/__TYPE__/__ID__' }, panel: nodes.adminPanel,
    currentPanelUrl: window.location.href, titles: {}, openMenu: null, announce() {},
    panelFetchUrl: href => ({ tab: href, fetchUrl: href }), setActiveNav() {}, syncSearch() {}, updateBadges() {},
    handleAuthLoss: () => false, toast: message => notices.push(message), api: async () => ({ ok: true }),
    fetch: url => new Promise((resolve, reject) => requests.push({ url, resolve, reject })),
    apiGet: () => new Promise((resolve, reject) => activity.push({ resolve, reject })) };
  vm.createContext(context);
  const slice = (start, end) => source.slice(source.indexOf(start), source.indexOf(end));
  vm.runInContext(slice('  /**\n   * Load a panel in place.', '  function refreshPanel(') +
    slice('  function openDialogById(', "  document.addEventListener('click', function (e) {\n    var trigger = e.target.closest('[data-detail]');") +
    slice('  function positionFloating(', "  document.addEventListener('click', function (e) {\n    var btn = e.target.closest('[data-menu-button]');") +
    slice("  document.addEventListener('keydown', function (e) {\n    if (openMenu)", '  /* ---------------------------------------------------------- activity */') +
    slice('  var activityBtn =', '  /* ---------------------------------------------------------- misc */'), context);
  // Capture the actual keyboard listener separately from otherwise irrelevant delegated clicks.
  document.addEventListener = (name, fn) => { handlers[name] = fn; };
  vm.runInContext(slice("  document.addEventListener('keydown', function (e) {\n    if (openMenu)", "  window.addEventListener('resize'"), context);
  const respond = (request, html, ok = true) => request.resolve({ ok, status: ok ? 200 : 500,
    headers: { get: () => '' }, text: async () => html });
  return { ...context, nodes, requests, activity, history, notices, handlers, respond };
}

test('admin panels retain the latest navigation when older success or failure arrives', async () => {
  for (const failed of [false, true]) {
    const h = setup(), a = h.loadPanel('/a', { push: true }), b = h.loadPanel('/b', { push: true });
    h.respond(h.requests[1], 'Panel B'); await b;
    if (failed) h.requests[0].reject(new Error('offline')); else h.respond(h.requests[0], 'Panel A');
    await a;
    assert.equal(h.nodes.adminPanel.innerHTML, 'Panel B');
    assert.deepEqual(h.history, ['http://localhost/b']); assert.equal(h.notices.length, 0);
    assert.equal(h.window.location.href, 'http://localhost/admin');
  }
});

test('older panel completion cannot clear the current request loading state', async () => {
  const h = setup(), a = h.loadPanel('/a'), b = h.loadPanel('/b');
  h.respond(h.requests[0], 'Panel A'); await a;
  assert.equal(h.nodes.adminPanel.getAttribute('aria-busy'), 'true');
  h.respond(h.requests[1], 'Panel B'); await b;
  assert.equal(h.nodes.adminPanel.getAttribute('aria-busy'), undefined);
});

test('the latest failed panel retains normal navigation fallback and clears loading', async () => {
  const h = setup(), pending = h.loadPanel('/offline', { push: true });
  h.requests[0].reject(new Error('offline')); await pending;
  assert.equal(h.window.location.href, '/offline'); assert.equal(h.notices.length, 1);
  assert.equal(h.nodes.adminPanel.getAttribute('aria-busy'), undefined);
});

test('record details reject old responses and never focus or render after dismissal', async () => {
  const h = setup(), a = h.openDetail('users', 1), b = h.openDetail('users', 2);
  h.respond(h.requests[1], 'User B'); await b;
  h.respond(h.requests[0], 'User A'); await a;
  assert.equal(h.nodes.detailBody.innerHTML, 'User B');
  const pending = h.loadDetail(); h.closeDialogById('detailDialog'); h.nodes.opener.focus();
  h.respond(h.requests[2], 'Hidden detail'); await pending;
  assert.equal(h.nodes.detailBody.innerHTML, 'User B'); assert.equal(h.document.activeElement, h.nodes.opener);
});

test('an old detail failure cannot replace a reopened record', async () => {
  const h = setup(), a = h.openDetail('users', 1);
  h.closeDialogById('detailDialog');
  const b = h.openDetail('users', 2); h.respond(h.requests[1], 'User B'); await b;
  h.requests[0].reject(new Error('offline')); await a;
  assert.equal(h.nodes.detailBody.innerHTML, 'User B');
});

test('activity response cannot reopen a dismissed popover or overwrite a newer opening', async () => {
  const h = setup(); h.nodes.activityBtn.click(); h.closeActivity();
  h.activity[0].resolve({ ok: true, data: { items: [], unseen: 0 } }); await flush();
  assert.equal(h.nodes.activityPanel.hidden, true);
  h.nodes.activityBtn.click(); h.closeActivity(); h.nodes.activityBtn.click();
  h.activity[2].resolve({ ok: true, data: { items: [], unseen: 0 } }); await flush();
  h.activity[1].reject(new Error('old offline')); await flush();
  assert.equal(h.nodes.activityState.textContent, 'Up to date');
});

test('mobile admin drawer traps keyboard focus, locks scroll, and closes on desktop resize', () => {
  const h = setup(); h.nodes.opener.focus(); h.openNav();
  assert.equal(h.document.body.style.position, 'fixed');
  h.nodes.navLast.focus(); let prevented = false;
  h.handlers.keydown({ key: 'Tab', preventDefault() { prevented = true; } });
  assert.equal(prevented, true); assert.equal(h.document.activeElement, h.nodes.navFirst);
  h.handlers.keydown({ key: 'Tab', shiftKey: true, preventDefault() {} });
  assert.equal(h.document.activeElement, h.nodes.navLast);
  h.handlers.keydown({ key: 'Escape', preventDefault() {} });
  assert.equal(h.nodes.sidebarOverlay.hidden, true); assert.equal(h.document.body.style.position, '');
  assert.equal(h.document.activeElement, h.nodes.opener);
  h.openNav(); h.nodes.navLast.focus(); h.window.innerWidth = 1200; h.handlers.resize();
  assert.equal(h.nodes.sidebarOverlay.hidden, true); assert.equal(h.nodes.adminSidebar.classList.contains('is-open'), false);
  assert.equal(h.document.body.style.position, ''); assert.equal(h.document.activeElement, h.nodes.navLast);
});
