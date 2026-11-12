const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const vm = require('node:vm');
const { test } = require('node:test');
const source = readFileSync('public/assets/js/app.js', 'utf8');
function clock(iso) {
  const RealDate = Date;
  const context = { Date: class extends RealDate { constructor(...args) { super(...(args.length ? args : [iso])); } }, Intl, fetchedSlotAvailabilityMap: {}, slotAvailabilityReady: true, slotAvailabilityKey: "" };
  vm.createContext(context);
  const times = source.slice(source.indexOf('const QB_TIMES ='), source.indexOf('let qbStartIdx'));
  vm.runInContext(times + '\nconst ALL_COURT_TIME_SLOTS = QB_TIMES.slice(0, -1).map((s, i) => `${s} – ${QB_TIMES[i+1]}`);\n' + source.slice(source.indexOf('function parseStartTimeHour('), source.indexOf('let pendingCourtBookingData')), context);
  context.verifyDay = date => {
    context.slotAvailabilityKey = JSON.stringify(['1', 'Court 1', date]);
    context.fetchedSlotAvailabilityMap = Object.fromEntries(['7:00 AM – 8:00 AM', '4:00 PM – 5:00 PM', '5:00 PM – 6:00 PM'].map(label => [label, {available: true}]));
  };
  return context;
}
test('future dates including year rollover do not inherit today’s elapsed hours', () => {
  for (const [now, next] of [['2026-10-11T07:30:00Z', 'Mon, Oct 12, 2026'], ['2026-12-31T15:30:00Z', 'Fri, Jan 1, 2027']]) {
    const c = clock(now);
    assert.equal(c.getMinAllowedStartIdx(next), 0);
    c.verifyDay(next);
    assert.equal(c.findNextFreeSlotForCourt(1, 'Court 1', next), '7:00 AM – 8:00 AM');
  }
});

test('configured midnight endpoint never becomes a same-day early-morning start', () => {
  const c=clock('2026-10-11T15:30:00Z'); // 23:30 Manila
  vm.runInContext('QB_TIME_MINUTES.splice(0,16,...Array.from({length:25},(_,i)=>i*60)); QB_TIMES.splice(0,16,...Array.from({length:25},(_,i)=>`${i%12||12}:00 ${i%24<12?"AM":"PM"}`));',c);
  assert.equal(c.getMinAllowedStartIdx('2026-10-11'),24);
  assert.equal(c.getMinAllowedStartIdx('2026-10-12'),0);
});
test('today uses Manila time and never falls back to expired hours', () => {
  const c = clock('2026-10-11T07:30:00Z'); // 15:30 Manila
  assert.equal(c.getMinAllowedStartIdx('2026-10-11'), 9); // 16:00
  c.verifyDay('2026-10-11');
  c.fetchedSlotAvailabilityMap['4:00 PM – 5:00 PM'] = { available: false, reason: 'maintenance' };
  assert.equal(c.findNextFreeSlotForCourt(1, 'Court 1', '2026-10-11'), '5:00 PM – 6:00 PM');
  const late = clock('2026-10-11T15:00:00Z');
  assert.equal(late.getMinAllowedStartIdx('2026-10-11'), 15);
  assert.equal(late.findNextFreeSlotForCourt(1, 'Court 1', '2026-10-11'), null);
  assert.equal(c.findNextFreeSlotForCourt(1, 'Court 1', '2026-10-10'), null);
});

test('Quick Book cannot scan or check out an elapsed or empty time range', () => {
  const c = clock('2026-10-11T15:00:00Z'); // 23:00 Manila
  let scans = 0, checkouts = 0, messages = 0;
  Object.assign(c, {
    document: { getElementById: id => id === 'qbSelectedDateFull' ? { value: '2026-10-11' } : null },
    setTimeout: () => { scans++; }, showToast: () => { messages++; },
    closeModal() {}, openPaymentReview: () => { checkouts++; },
  });
  vm.runInContext('let qbStartIdx = 16, qbEndIdx = 16;\n' + source.slice(source.indexOf('function proceedToPaymentFromQuickBook('), source.indexOf('let currentBookingBaseTotal')), c);
  c.proceedToPaymentFromQuickBook();
  c.bookCourtFromQuickBookResults('c1', 'Court 1', 300, 'Hard', 'Indoor');
  assert.equal(scans, 0);
  assert.equal(checkouts, 0);
  assert.equal(messages, 2);
});

function quickBookAtClosingTime() {
  const c = clock('2026-10-11T14:38:00Z'); // 22:38 Manila
  const elements = {};
  const node = id => elements[id] ||= { value: '', textContent: '', innerHTML: '', style: {}, classList: { add() {}, remove() {}, contains: () => false } };
  node('qbSelectedDateFull').value = '2026-10-11';
  let scans = 0;
  Object.assign(c, { document: { getElementById: node, querySelectorAll: () => [] }, openModal() {}, showToast() {},
    setTimeout: () => { scans++; }, cachedFacilityCourts: [] });
  vm.runInContext(source.slice(source.indexOf('let qbStartIdx'), source.indexOf('let currentBookingBaseTotal')), c);
  return { c, node, scans: () => scans, run: code => vm.runInContext(code, c) };
}

test('an exhausted Quick Book day displays zero duration and disables finding even after time shifts', () => {
  const h = quickBookAtClosingTime();
  h.c.openQuickBookModal(1);
  assert.equal(h.node('qbDurationText').textContent, '0 hours');
  assert.equal(h.node('btnQuickBookFindCourts').disabled, true);
  h.c.shiftStartTime(-1);
  assert.equal(h.node('qbDurationText').textContent, '0 hours');
  assert.equal(h.node('btnQuickBookFindCourts').disabled, true);
  h.c.proceedToPaymentFromQuickBook();
  assert.equal(h.scans(), 0);
});

test('tomorrow repairs an exhausted range to a supported hour while preserving valid chosen ranges', async () => {
  const h = quickBookAtClosingTime();
  h.c.cachedFacilityCourts = [{ id: 'court' }];
  h.c.URLSearchParams = URLSearchParams;
  const slots = h.run('ALL_COURT_TIME_SLOTS').map((label, i) => ({ label, start_min: 420 + i * 60, end_min: 480 + i * 60, available: true }));
  h.c.fetch = async () => ({ ok: true, json: async () => ({ success: true, slots }) });
  h.c.openQuickBookModal(1);
  await new Promise(resolve => setImmediate(resolve));
  h.c.selectQuickBookDate(null, '2026-10-12');
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(h.node('qbStartActive').textContent, '7:00 AM');
  assert.equal(h.node('qbEndActive').textContent, '8:00 AM');
  assert.equal(h.node('qbDurationText').textContent, '1 hour');
  assert.equal(h.node('btnQuickBookFindCourts').disabled, false);
  h.c.proceedToPaymentFromQuickBook();
  assert.equal(h.scans(), 2);
  h.run('qbStartIdx=2; qbEndIdx=4');
  h.c.selectQuickBookDate(null, '2026-10-13');
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(h.node('qbStartActive').textContent, '9:00 AM');
  assert.equal(h.node('qbEndActive').textContent, '11:00 AM');
  assert.equal(h.node('qbDurationText').textContent, '2 hours');
});
