const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const vm = require('node:vm');
const { test } = require('node:test');
const source = readFileSync('public/assets/js/app.js', 'utf8');
function harness() {
  // 6 AM Manila keeps the 7 AM fixtures valid regardless of runtime or host timezone.
  const now = Date.parse('2026-10-11T22:00:00Z');
  class FakeDate extends Date {
    constructor(...args) { super(...(args.length ? args : [now])); }
    static now() { return now; }
  }
  const elements = {}, requests = [], payments = [], timers = [];
  const node = id => elements[id] ||= { value: '', textContent: '', innerHTML: '', style: {}, disabled: false, classList: { add() {}, remove() {}, contains: () => false }, querySelectorAll: () => [] };
  node('qbSelectedFacilityId').value = '1'; node('qbSelectedDateFull').value = '2026-10-12';
  const c = { document: { getElementById: node, querySelectorAll: () => [], body: {style: {}} }, window: {}, URLSearchParams, Intl, Date: FakeDate,
    currentSelectedFacility: null, isFacilityOpen: () => true, cachedFacilityCourts: [{ id: 'a', name: 'Court A', status: 'available', price: 300 }],
    fetch: url => new Promise((resolve, reject) => requests.push({url, resolve: slots => resolve({ ok: true, json: async () => ({success: true, slots}) }), reject})),
    setTimeout: (fn, delay) => { if (delay === 950) timers.push(fn); }, showToast() {}, openModal() {}, closeModal() {}, openPaymentReview: (...args) => payments.push(args), escapeHtml: x => x, jsAttr: JSON.stringify };
  vm.createContext(c);
  vm.runInContext(source.slice(source.indexOf('const ALL_COURT_TIME_SLOTS ='), source.indexOf('function filterDetailCourts(')) + '\n' + source.slice(source.indexOf('const QB_TIMES ='), source.indexOf('let currentBookingBaseTotal')), c);
  vm.runInContext(source.slice(source.indexOf('function closeModal('), source.indexOf('// Toggle Notifications')), c);
  return {c, node, requests, payments, timers, run: code => vm.runInContext(code,c), flush: async () => { await new Promise(resolve => setImmediate(resolve)); }};
}
const free = [{label:'7:00 AM – 8:00 AM', available:true}];
function fullDaySlots() {
  const label = hour => `${hour % 12 || 12}:00 ${hour % 24 < 12 ? 'AM' : 'PM'}`;
  return Array.from({length:24},(_, hour)=>({ start_min:hour*60,end_min:(hour+1)*60,label:`${label(hour)} – ${label(hour+1)}`,available:hour>=6 && hour<23,reason:hour>=6 && hour<23 ? null : 'outside_operating_hours' }));
}

test('configured day metadata exposes early and late hours and keeps midnight as the next-day endpoint', async () => {
  const h=harness();
  h.c.openConfirmBookingModal({facilityId:1,courtId:'a',dateStr:'2026-10-13',timeRange:'7:00 AM – 8:00 AM'});
  h.requests[0].resolve(fullDaySlots()); await h.flush();
  assert.equal(h.run('QB_TIMES.length'),25);
  h.run('cbStartIdx=6; cbEndIdx=7; renderConfirmBookingTimes()');
  assert.equal(h.node('btnConfirmProceedBooking').disabled,false);
  h.run('cbStartIdx=22; cbEndIdx=23; renderConfirmBookingTimes()');
  assert.equal(h.node('btnConfirmProceedBooking').disabled,false);
  h.run('cbStartIdx=23; cbEndIdx=24; renderConfirmBookingTimes()');
  assert.equal(h.node('btnConfirmProceedBooking').disabled,true);
  assert.equal(h.run('QB_TIMES[24]'),'12:00 AM');
  assert.equal(h.run('QB_TIME_MINUTES[24]'),1440);
});

test('Quick Book loads configured hours before scanning and rejects stale schedule responses', async () => {
  const h=harness(); h.c.openQuickBookModal(1);
  assert.equal(h.node('btnQuickBookFindCourts').disabled,true);
  h.c.selectQuickBookDate(null,'2026-10-13');
  h.requests[1].resolve(fullDaySlots()); await h.flush();
  assert.equal(h.run('QB_TIMES.length'),25);
  h.requests[0].resolve(free); await h.flush();
  assert.equal(h.run('QB_TIMES.length'),25);
  h.run('qbStartIdx=6;qbEndIdx=7;renderQuickBookTimes()');
  assert.equal(h.node('btnQuickBookFindCourts').disabled,false);
  h.run('qbStartIdx=0;qbEndIdx=1;renderQuickBookTimes()');
  assert.equal(h.node('btnQuickBookFindCourts').disabled,true);
  assert.match(h.node('qbDurationText').textContent,/Outside venue hours/);
});
test('confirm progression fails closed for loading, failed and absent labels', async () => {
  const h = harness(); h.c.openConfirmBookingModal({facilityId:1,courtId:'a', dateStr:'2026-10-12', timeRange:'7:00 AM – 8:00 AM'});
  assert.equal(h.node('btnConfirmProceedBooking').disabled,true);
  h.requests[0].reject(Error('offline')); await h.flush();
  assert.equal(h.node('btnConfirmProceedBooking').disabled,true);
  h.node('btnConfirmProceedBooking').onclick(); assert.equal(h.payments.length,0);
  h.c.selectConfirmBookingDate(null,'2026-10-13'); h.requests[1].resolve([]); await h.flush();
  assert.equal(h.node('btnConfirmProceedBooking').disabled,true);
});
test('confirm ignores reversed date responses and checks current time labels', async () => {
 const h=harness(); h.c.openConfirmBookingModal({facilityId:1,courtId:'a',dateStr:'2026-10-12',timeRange:'7:00 AM – 8:00 AM'});
 h.c.selectConfirmBookingDate(null,'2026-10-13'); h.requests[1].resolve(free); await h.flush(); assert.equal(h.node('btnConfirmProceedBooking').disabled,false);
 h.requests[0].resolve([]); await h.flush(); assert.equal(h.node('btnConfirmProceedBooking').disabled,false);
 h.c.shiftConfirmEndTime(1); assert.equal(h.node('btnConfirmProceedBooking').disabled,true);
});
test('direct booking loads the chosen court before choosing its first free slot', async () => {
 const h=harness(); h.run("fetchedSlotAvailabilityMap = {'7:00 AM – 8:00 AM': {available:false}}");
 h.c.bookCourtDirectToPayment(2,'b','Court B',300); assert.equal(h.requests.length,1); assert.equal(h.run('pendingCourtBookingData'),null);
 h.requests[0].resolve(free); await h.flush();
 assert.equal(h.run('pendingCourtBookingData.courtId'),'b'); assert.equal(h.run('pendingCourtBookingData.timeRange'),'7:00 AM – 8:00 AM');
});
test('Quick Book rejects changed controls and unverified courts at checkout', async () => {
 const h=harness(); h.run('qbStartIdx=0; qbEndIdx=1'); h.c.proceedToPaymentFromQuickBook(); const scan=h.timers.shift()(); h.requests[0].resolve([{label:'7:00 AM – 8:00 AM',available:true},{label:'8:00 AM – 9:00 AM',available:true}]); await scan;
 h.node('qbSelectedDateFull').value='2026-10-13'; h.c.bookCourtFromQuickBookResults('a','Court A',300,'Hard','Indoor'); assert.equal(h.payments.length,0);
 h.node('qbSelectedDateFull').value='2026-10-12'; h.c.bookCourtFromQuickBookResults('b','Court B',300,'Hard','Indoor'); assert.equal(h.payments.length,0);
});
test('Quick Book ignores obsolete scans after selection changes', async () => {
 const h=harness(); h.run('qbStartIdx=0;qbEndIdx=1'); h.c.proceedToPaymentFromQuickBook(); const scan=h.timers.shift()(); h.c.selectQuickBookDate(null,'2026-10-13'); h.requests[0].resolve(free); await scan;
 assert.ok(!h.node('qbAvailableCourtsList').innerHTML.includes('bookCourtFromQuickBookResults'));
});

test('closing and reopening confirm cannot apply an older court response', async () => {
 const h=harness(); h.c.openConfirmBookingModal({facilityId:1,courtId:'a',dateStr:'2026-10-12',timeRange:'7:00 AM – 8:00 AM'});
 h.c.closeModal('confirmBookingModal'); h.c.openConfirmBookingModal({facilityId:2,courtId:'b',dateStr:'2026-10-12',timeRange:'7:00 AM – 8:00 AM'});
 h.requests[0].resolve(free); await h.flush(); assert.equal(h.node('btnConfirmProceedBooking').disabled,true);
 h.requests[1].resolve(free); await h.flush(); h.node('btnConfirmProceedBooking').onclick();
 assert.equal(h.payments.length,1); assert.equal(h.payments[0][0],2); assert.equal(h.payments[0][1],'b');
});
test('closing Quick Book invalidates a delayed scan even for unchanged controls', async () => {
 const h=harness(); h.run('qbStartIdx=0;qbEndIdx=1'); h.c.proceedToPaymentFromQuickBook(); const scan=h.timers.shift()();
 h.c.closeModal('quickBookModal'); h.requests[0].resolve(free); await scan;
 h.c.bookCourtFromQuickBookResults('a','Court A',300,'Hard','Indoor'); assert.equal(h.payments.length,0);
 assert.equal(h.node('qbAvailableCourtsList').innerHTML,'');
});
test('latest direct court selection wins reversed responses', async () => {
 const h=harness(); h.c.bookCourtDirectToPayment(1,'a'); h.c.bookCourtDirectToPayment(2,'b');
 h.requests[1].resolve(free); await h.flush(); h.requests[0].resolve([]); await h.flush();
 assert.equal(h.run('pendingCourtBookingData.facilityId'),2); assert.equal(h.run('pendingCourtBookingData.courtId'),'b');
});
test('Quick Book checkout uses the verified snapshot and rejects a different venue', async () => {
 const h=harness(); h.run('qbStartIdx=0;qbEndIdx=1'); h.c.proceedToPaymentFromQuickBook(); const scan=h.timers.shift()(); h.requests[0].resolve(free); await scan;
 h.node('qbSelectedFacilityId').value='2'; h.c.bookCourtFromQuickBookResults('a','Court A',300,'Hard','Indoor'); assert.equal(h.payments.length,0);
 h.node('qbSelectedFacilityId').value='1'; h.c.bookCourtFromQuickBookResults('a','Court A',300,'Hard','Indoor');
 assert.equal(h.payments[0][7],'2026-10-12'); assert.equal(h.payments[0][8],'7:00 AM – 8:00 AM');
});

test('shared Escape and backdrop dismissal invalidate pending booking requests', async () => {
 for (const dismissal of ['escape','backdrop']) for (const modal of ['quickBookModal','confirmBookingModal']) {
  const h=harness(), listeners={};
  h.c.document.addEventListener=(type,fn)=>listeners[type]=fn;
  h.c.document.contains=()=>false;
  Object.assign(h.c.window,{matchMedia:()=>({matches:true}),scrollTo(){},setTimeout(){}});
  for (const id of ['quickBookModal','confirmBookingModal']) Object.assign(h.node(id),{id,getAttribute(){return null},setAttribute(){},querySelector(){return null}});
  const ux=readFileSync('public/assets/js/ux-core.js','utf8');
  vm.runInContext(ux.slice(0,ux.indexOf('})(window, document);')+23),h.c);
  vm.runInContext(source.slice(source.indexOf('function openModal('),source.indexOf('// Toggle Notifications')),h.c);
  h.c.window.invalidateBookingAvailability=h.c.invalidateBookingAvailability;
  if(modal==='quickBookModal') { h.run('qbStartIdx=0;qbEndIdx=1');h.c.openModal(modal);h.c.proceedToPaymentFromQuickBook(); }
  else h.c.openConfirmBookingModal({facilityId:1,courtId:'a',dateStr:'2026-10-12',timeRange:'7:00 AM \u2013 8:00 AM'});
  const scan=modal==='quickBookModal'?h.timers.shift()():null;
  if(dismissal==='escape') listeners.keydown({key:'Escape',preventDefault(){}});
  else {listeners.mousedown({target:h.node(modal)});listeners.click({target:h.node(modal)});}
  h.requests[0].resolve(free); if(scan) await scan; else await h.flush();
  if(modal==='quickBookModal') assert.equal(h.run('quickBookCheckedSelection'),null);
  else assert.equal(h.run('pendingCourtBookingData'),null);
 }
});
test('closing venue detail prevents a pending direct booking from opening confirm', async () => {
 const h=harness(); h.c.silentRefreshDiscover=()=>{};
 vm.runInContext(source.slice(source.indexOf('function closeFacilityDetail('),source.indexOf('function openVenueNavigationModal(')),h.c);
 h.c.bookCourtDirectToPayment(1,'a'); h.c.closeFacilityDetail();
 h.requests[0].resolve(free); await h.flush(); assert.equal(h.run('pendingCourtBookingData'),null);
});
