const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const vm = require('node:vm');
const { test } = require('node:test');

test('a rejected swipe delete resets the next gesture to the closed position', () => {
  const source = readFileSync('public/assets/js/app.js', 'utf8');
  const handlers = {}, classes = new Set();
  const card = { style: {}, classList: { add() {}, remove() {} }, addEventListener: (event, fn) => { handlers[event] = fn; } };
  const row = { getAttribute: () => 'n1', querySelector: () => card, classList: { add: cls => classes.add(cls), remove: cls => classes.delete(cls) } };
  const c = { document: { getElementById: () => ({ querySelectorAll: () => [row] }), querySelectorAll: () => [card] },
    window: { addEventListener: (event, fn) => { handlers[event] = fn; } },
    dismissNotification: () => { card.style.transform = 'translateX(0px)'; classes.delete('is-swiped'); } };
  vm.createContext(c);
  vm.runInContext(source.slice(source.indexOf('function initNotificationSwipeGestures('), source.indexOf('// Notifications functions defined below')), c);
  c.initNotificationSwipeGestures();
  const gesture = (start, end) => { handlers.pointerdown({ clientX: start }); handlers.pointermove({ clientX: end }); handlers.pointerup({ clientX: end }); };
  gesture(200, 140); // snap open
  assert.equal(card.style.transform, 'translateX(-86px)');
  gesture(200, 130); // rejected full delete restores the card
  assert.equal(card.style.transform, 'translateX(0px)');
  handlers.pointerdown({ clientX: 200 }); handlers.pointermove({ clientX: 190 });
  assert.equal(card.style.transform, 'translateX(-10px)');
});
