const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
test('scanner sound preference actually controls audio and survives a reload', () => {
  const source = fs.readFileSync('public/assets/js/owner.js', 'utf8');
  const start = source.indexOf('let ownerSoundEnabled');
  assert.ok(start >= 0);
  const values = new Map(); let played = 0;
  const c = { window: { AudioContext: class { constructor() { played++; throw new Error('audio checked'); } } },
    localStorage: { getItem: key => values.get(key) ?? null, setItem: (key, value) => values.set(key, value) }, showToast() {} };
  vm.createContext(c);
  const code = source.slice(start, source.indexOf('let payoutRequestInFlight'));
  vm.runInContext(code, c);
  c.setOwnerSoundEnabled(false); c.playSuccessChime();
  assert.equal(played, 0);
  const reloaded = vm.createContext({ ...c }); vm.runInContext(code, reloaded); reloaded.playSuccessChime();
  assert.equal(played, 0);
  reloaded.setOwnerSoundEnabled(true); reloaded.playSuccessChime(); assert.equal(played, 1);
  const template = fs.readFileSync('templates/web/partials/owner/_tab-settings.html.twig', 'utf8');
  assert.match(template, /id="smsPayoutAlertsToggle"[^>]*disabled/);
  assert.doesNotMatch(template, /SMS Payout notifications saved/);
});
