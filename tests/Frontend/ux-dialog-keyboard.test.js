const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const vm = require('node:vm');
const { test } = require('node:test');

function harness(reducedMotion = true) {
  const handlers = {}, nodes = {}, timers = [];
  const document = { body: { style: {} }, activeElement: null, contains: () => true,
    getElementById: id => nodes[id], addEventListener: (name, fn) => handlers[name] = fn };
  function node(id) {
    const attributes = {}, classes = new Set();
    const listeners = {};
    return nodes[id] = { id, style: {}, attributes, children: [],
      addEventListener: (name, fn) => listeners[name] = fn,
      removeEventListener: name => delete listeners[name],
      endAnimation() { if (listeners.animationend) listeners.animationend({ target: this }); },
      classList: { add: (...names) => names.forEach(n => classes.add(n)), remove: (...names) => names.forEach(n => classes.delete(n)) },
      getAttribute: name => attributes[name], setAttribute: (name, value) => attributes[name] = value,
      hasAttribute: name => name in attributes, getBoundingClientRect: () => ({ width: 50, height: 40 }),
      querySelector: () => null, querySelectorAll() { return this.children; },
      contains(el) { return this.children.includes(el); }, focus() { document.activeElement = this; } };
  }
  const window = { matchMedia: () => ({ matches: reducedMotion }), scrollY: 24, scrollTo() {},
    setTimeout: fn => { timers.push(fn); return fn; },
    clearTimeout: fn => { const i = timers.indexOf(fn); if (i >= 0) timers.splice(i, 1); } };
  const context = { window, document, getComputedStyle: () => ({ visibility: 'visible' }) };
  vm.createContext(context);
  vm.runInContext(readFileSync('public/assets/js/ux-core.js', 'utf8'), context);
  return { document, window, node, handlers, flush: () => { while (timers.length) timers.shift()(); } };
}

test('shared dialog runtime traps forward/backward Tab, closes Escape and restores focus', () => {
  const h = harness(), trigger = h.node('trigger'), modal = h.node('modal');
  const first = h.node('first'), last = h.node('last'); modal.children = [first, last]; trigger.focus();
  h.window.UX.openDialog('modal'); h.flush();
  assert.equal(h.document.activeElement, first);
  assert.equal(modal.attributes.role, 'dialog');
  assert.equal(modal.attributes['aria-modal'], 'true');
  let prevented = false;
  last.focus(); h.handlers.keydown({ key: 'Tab', preventDefault() { prevented = true; } });
  assert.equal(prevented, true); assert.equal(h.document.activeElement, first);
  first.focus(); h.handlers.keydown({ key: 'Tab', shiftKey: true, preventDefault() {} });
  assert.equal(h.document.activeElement, last);
  h.handlers.keydown({ key: 'Escape', preventDefault() {} });
  assert.equal(modal.style.display, 'none'); assert.equal(h.document.activeElement, trigger);
  assert.equal(h.document.body.style.position, '');
});

test('stacked dialogs retain scroll lock and only dismiss top dialog', () => {
  const h = harness(), first = h.node('first'), second = h.node('second'); h.node('trigger').focus();
  h.window.UX.openDialog(first); h.flush(); h.window.UX.openDialog(second); h.flush();
  h.handlers.keydown({ key: 'Escape', preventDefault() {} });
  assert.equal(second.style.display, 'none'); assert.equal(first.style.display, 'flex');
  assert.equal(h.document.body.style.position, 'fixed');
  h.window.UX.closeDialog(first); assert.equal(h.document.body.style.position, '');
});

test('busy confirmation cannot be dismissed while its mutation is pending', () => {
  const h = harness(), modal = h.node('modal'); h.node('trigger').focus();
  h.window.UX.openDialog(modal); h.flush();
  modal.setAttribute('aria-busy', 'true');
  h.handlers.keydown({ key: 'Escape', preventDefault() {} });
  h.window.UX.closeDialog(modal);
  assert.equal(modal.style.display, 'flex');
  modal.setAttribute('aria-busy', 'false');
  h.window.UX.closeDialog(modal);
  assert.equal(modal.style.display, 'none');
});

test('reopening during exit keeps the dialog visible, focused and scroll locked', () => {
  const h = harness(false), modal = h.node('modal'), field = h.node('field'), trigger = h.node('trigger');
  modal.children = [field]; trigger.focus();
  h.window.UX.openDialog(modal); h.flush();
  h.window.UX.closeDialog(modal);
  h.window.UX.openDialog(modal); h.flush();
  assert.equal(modal.style.display, 'flex');
  assert.equal(h.document.body.style.position, 'fixed');
  assert.equal(h.document.activeElement, field);
  h.window.UX.closeDialog(modal); modal.endAnimation(); h.flush();
  assert.equal(modal.style.display, 'none');
  assert.equal(h.document.body.style.position, '');
  assert.equal(h.document.activeElement, trigger);
});

test('a closing dialog keeps focus contained and ignores repeated dismissals', () => {
  const h = harness(false), modal = h.node('modal'), field = h.node('field');
  modal.children = [field]; h.node('trigger').focus();
  h.window.UX.openDialog(modal); h.flush(); h.window.UX.closeDialog(modal);
  let prevented = false;
  h.handlers.keydown({ key: 'Tab', preventDefault() { prevented = true; } });
  assert.equal(prevented, true);
  h.window.UX.closeDialog(modal); h.flush();
  assert.equal(h.document.body.style.position, '');
});

test('an older closing dialog cannot steal focus from a newer dialog', () => {
  const h = harness(false), older = h.node('older'), newer = h.node('newer'), field = h.node('field');
  const trigger = h.node('trigger');
  newer.children = [field]; trigger.focus();
  h.window.UX.openDialog(older); h.flush(); h.window.UX.closeDialog(older);
  h.window.UX.openDialog(newer); h.flush();
  assert.equal(h.document.activeElement, field);
  assert.equal(h.document.body.style.position, 'fixed');
  h.window.UX.closeDialog(newer); newer.endAnimation(); h.flush();
  assert.equal(h.document.body.style.position, '');
  assert.equal(h.document.activeElement, trigger);
});

test('deferred opening focus never moves into a dialog already closed', () => {
  const h = harness(), modal = h.node('modal'), trigger = h.node('trigger'); trigger.focus();
  h.window.UX.openDialog(modal); h.window.UX.closeDialog(modal); h.flush();
  assert.equal(h.document.activeElement, trigger);
});

test('closing lower dialogs first rewires the focus return chain', () => {
  const h = harness(false), trigger = h.node('trigger'); trigger.focus();
  const first = h.node('first'), second = h.node('second'), third = h.node('third');
  first.children = [h.node('firstField')]; second.children = [h.node('secondField')];
  h.window.UX.openDialog(first); h.flush(); h.window.UX.openDialog(second); h.flush();
  h.window.UX.openDialog(third); h.flush();
  h.window.UX.closeDialog(first); first.endAnimation();
  h.window.UX.closeDialog(second); second.endAnimation();
  h.window.UX.closeDialog(third); third.endAnimation(); h.flush();
  assert.equal(h.document.activeElement, trigger);
  assert.equal(h.document.body.style.position, '');
});
