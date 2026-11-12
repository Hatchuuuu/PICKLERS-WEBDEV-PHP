const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const vm = require('node:vm');
const { test } = require('node:test');
const source = readFileSync('public/assets/js/app.js', 'utf8');

test('player legacy dismissal does not bypass the shared dialog stack or busy guard', () => {
  const handlers = {}, overlay = { classList: { contains: () => true }, style: { display: 'flex' } };
  const document = { body: { style: { overflow: 'hidden' } }, querySelectorAll: () => [overlay] };
  const window = { UX: {}, addEventListener: (name, fn) => handlers[name] = fn };
  vm.runInNewContext(source.slice(source.indexOf('// Close overlays on outside click or ESC'), source.indexOf('// Helper: HTML Escaper')), { window, document });
  handlers.click({ target: overlay });
  handlers.keydown({ key: 'Escape' });
  assert.equal(overlay.style.display, 'flex');
  assert.equal(document.body.style.overflow, 'hidden');
  window.UX = null;
  handlers.keydown({ key: 'Escape' });
  assert.equal(overlay.style.display, 'none', 'legacy fallback still works without shared UX');
});

test('every explicit player scroll respects reduced motion without removing normal smooth scroll', () => {
  const options = [...source.matchAll(/(?:scrollTo|scrollIntoView)\((\{[^\n]+?\})\)/g)];
  assert.ok(options.length >= 5);
  for (const [, expression] of options) {
    for (const reduced of [true, false]) {
      const value = vm.runInNewContext('(' + expression + ')', { window: { UX: { prefersReducedMotion: () => reduced } } });
      assert.equal(value.behavior, reduced ? 'instant' : 'smooth', expression);
    }
  }
});
