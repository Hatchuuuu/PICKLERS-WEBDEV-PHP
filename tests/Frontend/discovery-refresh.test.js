const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const vm = require('node:vm');
const { test } = require('node:test');
const source = readFileSync('public/assets/js/app.js', 'utf8');
function harness() {
  const ids = Object.fromEntries(['facilitiesGrid', 'playTabContent', 'facilityDetailView', 'facilityDetailHeaderWrap', 'facilityCourtsList', 'facilityDetailActionBtns', 'facilityFollowButton'].map(id => [id, { style: {}, innerHTML: '', dataset: {} }]));
  ids.facilityDetailView.style.display = 'none';
  const pending = [], renders = [];
  const c = { document: { getElementById: id => ids[id] || null, querySelector: () => null }, window: { scrollTo() {} }, setInterval() { return 1; },
    fetch: url => new Promise((resolve, reject) => pending.push({ url, resolve: data => resolve({ json: () => data }), reject })),
    renderFacilitiesGrid: data => renders.push(data), filterFacilitiesLive: () => { c.filtered = true; },
    renderFollowFacility: (button, following) => { button.dataset.following = following; },
    escapeHtml: value => String(value || ''), jsAttr: JSON.stringify, isFacilityOpen: () => true };
  vm.createContext(c);
  vm.runInContext('let currentSelectedFacility = null; let cachedFacilityCourts = []; let slotAvailabilityGeneration = 0;', c);
  for (const [start, end] of [['function silentRefreshDiscover()', '/** Toggle the small unread'], ['function openFacilityDetail(', 'function setHeroImage('], ['function closeFacilityDetail()', 'function openVenueNavigationModal(']]) vm.runInContext(source.slice(source.indexOf(start), source.indexOf(end)), c);
  vm.runInContext(source.slice(source.indexOf('function renderDetailCourtsList('), source.indexOf('const ALL_COURT_TIME_SLOTS')), c);
  const renderCourts = c.renderDetailCourtsList;
  c.renderDetailCourtsList = (courts, facility) => { renderCourts(courts, facility); c.last = facility; c.courts = courts; };
  return { c, ids, pending, renders };
}
const settle = () => new Promise(resolve => setImmediate(resolve));
const detail = id => ({ success: true, facility: { id, name: `Venue ${id}`, is_favorited: true }, courts: [{ id, status: 'available' }] });
test('visible detail refresh updates selected court cache and preserves Follow state', async () => {
  const h = harness(); h.c.openFacilityDetail(1); h.pending[0].resolve(detail(1)); await settle();
  h.c.silentRefreshDiscover(); assert.match(h.pending[1].url, /facility_detail&id=1$/);
  const update = detail(1); update.courts[0].status = 'maintenance'; update.facility.is_favorited = false;
  h.pending[1].resolve(update); await settle();
  assert.equal(vm.runInContext('cachedFacilityCourts[0].status', h.c), 'maintenance');
  assert.equal(h.c.courts[0].status, 'maintenance'); assert.match(h.ids.facilityCourtsList.innerHTML, /dot-maintenance/); assert.doesNotMatch(h.ids.facilityCourtsList.innerHTML, /Book Now/); assert.equal(h.c.last.is_favorited, true); assert.equal(h.ids.facilityFollowButton.dataset.following, true);
});
test('A responding after B cannot replace B, including header and cached courts', async () => {
  const h = harness(); h.c.openFacilityDetail(1); h.c.openFacilityDetail(2);
  h.pending[1].resolve(detail(2)); await settle(); const header = h.ids.facilityDetailHeaderWrap.innerHTML;
  h.pending[0].resolve(detail(1)); await settle();
  assert.equal(h.c.last.id, 2); assert.equal(h.c.courts[0].id, 2); assert.equal(h.ids.facilityDetailHeaderWrap.innerHTML, header);
});
test('closing during opening or refreshing ignores late success and failure', async () => {
  for (const refresh of [false, true]) for (const failure of [false, true]) {
    const h = harness(); h.c.openFacilityDetail(1);
    if (refresh) { h.pending[0].resolve(detail(1)); await settle(); h.c.silentRefreshDiscover(); }
    h.c.closeFacilityDetail(); const before = h.ids.facilityCourtsList.innerHTML;
    const request = h.pending[refresh ? 1 : 0]; if (failure) request.reject(new Error('offline')); else request.resolve(detail(1)); await settle();
    assert.equal(vm.runInContext('currentSelectedFacility', h.c), null); assert.equal(h.ids.facilityCourtsList.innerHTML, before);
  }
});
test('list refresh reapplies existing filters without fetching detail', async () => {
  const h = harness(); h.c.silentRefreshDiscover(); assert.equal(h.pending[0].url, 'api.php?action=facilities');
  h.pending[0].resolve({ success: true, facilities: [{ id: 1 }] }); await settle(); assert.equal(h.c.filtered, true);
});

test('latest refresh wins and closing then reopening the same venue invalidates old responses', async () => {
  const h = harness(); h.c.openFacilityDetail(1); h.pending[0].resolve(detail(1)); await settle();
  h.c.silentRefreshDiscover(); h.c.silentRefreshDiscover();
  const update = detail(1); update.courts[0].status = 'maintenance';
  h.pending[2].resolve(update); await settle(); h.pending[1].resolve(detail(1)); await settle();
  assert.equal(h.c.courts[0].status, 'maintenance');
  h.c.silentRefreshDiscover(); h.c.closeFacilityDetail(); h.c.openFacilityDetail(1);
  h.pending[4].resolve(update); await settle(); h.pending[3].resolve(detail(1)); await settle();
  assert.equal(h.c.courts[0].status, 'maintenance');
});

test('latest list refresh preserves newer Follow and court availability summary', async () => {
  const h = harness(); h.c.silentRefreshDiscover(); h.c.silentRefreshDiscover();
  const newer = [{ id: 1, is_favorited: true, available_courts: 0 }];
  h.pending[1].resolve({ success: true, facilities: newer }); await settle();
  h.pending[0].resolve({ success: true, facilities: [{ id: 1, is_favorited: false, available_courts: 2 }] }); await settle();
  assert.equal(h.renders.length, 1); assert.deepEqual(h.renders[0], newer);
});

test('list refresh started before detail navigation cannot render after returning to list', async () => {
  const h = harness(); h.c.silentRefreshDiscover(); h.c.openFacilityDetail(1); h.c.closeFacilityDetail();
  h.pending[0].resolve({ success: true, facilities: [{ id: 1 }] }); await settle();
  assert.equal(h.renders.length, 0);
});


test('confirmed favorite mutation invalidates a list poll that captured the older Follow state', async () => {
  const h = harness();
  h.c.getCsrfToken = () => ''; h.c.showToast = () => {};
  h.c.document.querySelectorAll = () => [];
  vm.runInContext(source.slice(source.indexOf('const favoriteToggleInFlight'), source.indexOf('// Notifications Management')), h.c);
  const button = { disabled: false, classList: { toggle() {} } };
  h.c.silentRefreshDiscover();
  const mutation = h.c.toggleFavoriteFacility(button, 1, 'Venue 1');
  h.pending[1].resolve({ success: true, favorited: true }); await mutation;
  h.pending[0].resolve({ success: true, facilities: [{ id: 1, is_favorited: false }] }); await settle();
  assert.equal(h.renders.length, 0);
});
