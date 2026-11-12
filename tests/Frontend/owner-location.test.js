const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/assets/js/owner.js', 'utf8');
const gps = source.slice(source.indexOf('function autoLocateGps('), source.indexOf('function toggleHoursVisibility('));

for (const unavailable of [false, true]) test('GPS denial/unavailable preserves the manual address: ' + unavailable, () => {
  const address = { value: 'Actual venue address' }, toasts = [];
  const navigator = unavailable ? {} : { geolocation: { getCurrentPosition: (ok, fail) => fail({ code: 1 }) } };
  const c = { navigator, document: { getElementById: () => address }, showToast: (...args) => toasts.push(args) };
  vm.createContext(c); vm.runInContext(gps, c); c.autoLocateGps();
  assert.equal(address.value, 'Actual venue address');
  assert.ok(toasts.some(([message]) => /manual|unchanged|unavailable/i.test(message)));
  assert.ok(!toasts.some(([message]) => /✓ Located|✓ Location set/.test(message)));
});

test('geocoding failure uses actual coordinates instead of an invented nearby address', async () => {
  const address = { value: 'Old address' };
  const c = { navigator: { geolocation: { getCurrentPosition: ok => ok({ coords: { latitude: 9.3, longitude: 123.3 } }) } },
    document: { getElementById: () => address }, showToast() {}, fetch: async () => { throw new Error('offline'); } };
  vm.createContext(c); vm.runInContext(gps, c); c.autoLocateGps();
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(address.value, '9.30000, 123.30000');
});
