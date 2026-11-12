const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const vm = require('node:vm');
const source = readFileSync('public/assets/js/owner.js', 'utf8');
const helpers = source.slice(source.indexOf('function apiUrl('), source.indexOf('function submitCreateTournamentForm('));
test('owner requests carry the validated selected facility at both bases', () => {
  for (const base of ['', '/PICKLERS%20WEBDEV%20PROJECT']) {
    const context = { window: { PICKLERS_BASE_URL: base, PICKLERS_FACILITY_ID: '42' } };
    vm.createContext(context); vm.runInContext(helpers, context);
    for (const name of ['apiUrl', 'ownerUrl']) {
      const url = new URL(context[name]('update_facility_settings'), 'https://picklers.test');
      assert.equal(url.searchParams.get('facility_id'), '42');
      assert.equal(url.searchParams.get('action'), 'update_facility_settings');
      assert.ok(url.pathname.startsWith(base));
    }
  }
});
