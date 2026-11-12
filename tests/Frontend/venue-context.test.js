const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { test } = require('node:test');

const source = readFileSync(path.join(__dirname, '../../public/assets/js/app.js'), 'utf8');
const helpers = source.slice(source.indexOf('function escapeHtml('), source.indexOf('// Helper: CSRF Token Getter'));
const values = ['Picklers Courts', "O'Connor Courts", 'The "Best" Courts', 'A & B <Courts>', "x'); auditMarker = true; //", 'Back\\slash\nCourts'];

// Simulate the browser decoding HTML entities before compiling an inline handler.
function decodeAttribute(value) {
  return value.replace(/&(quot|#039|lt|gt|amp);/g, (_, entity) => ({ quot: '"', '#039': "'", lt: '<', gt: '>', amp: '&' })[entity]);
}

for (const [handler, variable, expected] of [
  ['toggleFollowFacility', 'f', value => value],
  ['setHeroImage', 'img', value => value],
]) {
  test(`${handler} keeps quoted dynamic values as data`, () => {
    const line = source.split('\n').find(line => line.includes(`onclick="${handler}(this,`) || (handler === 'showToast' && line.includes('Facility is currently closed (${')));
    assert.ok(line, 'production handler exists');
    const attribute = line.match(/onclick="([^"]*)"/)[1];
    for (const value of values) {
      let received;
      const context = { f: { id: 42, name: value }, img: { url: value }, facility: { hours: value }, auditMarker: false, event: { stopPropagation() {} } };
      context[handler] = (...args) => { received = args[handler === 'showToast' ? 0 : handler === 'toggleFollowFacility' ? 2 : 1]; };
      vm.createContext(context);
      vm.runInContext(helpers, context);
      const rendered = vm.runInContext('`' + attribute + '`', context);
      vm.runInContext(decodeAttribute(rendered), context);
      assert.equal(received, expected(value));
      assert.equal(context.auditMarker, false);
    }
  });
}

test('a venue closed now still offers its available court for future-date booking', () => {
  const list = { innerHTML: '' }, quickBook = { style: {} };
  const context = { currentSelectedFacility: null, cachedFacilityCourts: [],
    document: { getElementById: id => id === 'facilityCourtsList' ? list : null, querySelector: () => quickBook },
    isFacilityOpen: () => false, escapeHtml: value => value, jsAttr: JSON.stringify };
  vm.createContext(context);
  vm.runInContext(source.slice(source.indexOf('function renderDetailCourtsList('),source.indexOf('const ALL_COURT_TIME_SLOTS =')), context);
  context.renderDetailCourtsList([{ id:'court',name:'Court 1',status:'available',price:300 }],{id:1,hours:'6am - 10pm'});
  assert.match(list.innerHTML,/Book Now/);
  assert.doesNotMatch(list.innerHTML,/disabled/);
  assert.equal(quickBook.style.cursor,'pointer');
});

test('payment lookup matches real card names without parsing names as selectors', () => {
  const start = source.indexOf('  const matchingCard =', source.indexOf('function openPaymentReview('));
  const end = source.indexOf('  if (matchingCard)', start);
  for (const value of values) {
    const card = { getAttribute: () => value };
    const document = {
      querySelectorAll(selector) { assert.equal(selector, '.app-facility-card'); return [{ getAttribute: () => 'Other' }, card]; },
      querySelector() { assert.fail('dynamic card selector must not be used'); },
    };
    const context = { document, fName: value };
    assert.equal(vm.runInNewContext(source.slice(start, end) + '\nmatchingCard;', context), card);
    assert.equal(vm.runInNewContext(source.slice(start, end) + '\nmatchingCard;', { document, fName: 'Missing venue' }), undefined);
  }
});
