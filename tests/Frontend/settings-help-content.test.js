const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const source = readFileSync('templates/web/partials/app/_modals.html.twig', 'utf8');
const policies = source.slice(source.indexOf('<!-- Help & Support Modal -->'), source.indexOf('<!-- Change Password Modal -->'));

test('settings information dialogs have named headings, sections and a separate close footer', () => {
  for (const id of ['supportModal', 'privacyModal', 'termsModal']) {
    const section = policies.slice(policies.indexOf('id="' + id + '"'));
    assert.ok(section.startsWith('id="' + id + '" role="dialog" aria-modal="true" aria-labelledby="' + id + 'Title"'));
    assert.ok(section.includes('id="' + id + 'Title"'));
    assert.ok(section.includes('id="' + id + 'Summary"'));
  }
  assert.equal((policies.match(/class="modal-body info-dialog-body"/g) || []).length, 3);
  assert.equal((policies.match(/class="info-dialog-footer"/g) || []).length, 3);
  assert.ok((policies.match(/<h4>/g) || []).length >= 10);
  assert.doesNotMatch(policies, /font-size:12px|I Agree|support@|8888-PICK|mailto:/);
});

test('support offers working booking navigation and honestly describes human support availability', () => {
  assert.match(policies, /url_to\('app\.php\?tab=bookings'\)/);
  assert.match(policies, /No monitored support email or hotline is configured/);
  assert.match(policies, /booking reference/);
  assert.match(policies, /Never share your password/);
});

test('policy copy preserves implemented payment and Open Play cancellation boundaries', () => {
  assert.match(policies, /Real-money top-ups are currently unavailable/);
  assert.match(policies, /Pending Pickle Credits bookings[\s\S]*full refund/);
  assert.match(policies, /Open Play requests[\s\S]*owner approval/);
  assert.match(policies, /Confirmed Pickle Credits bookings[\s\S]*at least 24 hours/);
  assert.match(policies, /within 24 hours[\s\S]*no refund/);
  assert.match(policies, /Confirmed bookings cannot be cancelled after play starts/);
  assert.match(policies, /Free sessions[\s\S]*nothing to refund/);
  assert.match(policies, /Selecting an external payment method does not confirm payment/);
});

test('privacy describes actual data, recipients and retained records without unsupported certifications', () => {
  assert.match(policies, /account and booking records/);
  assert.match(policies, /facility operators/);
  assert.match(policies, /OpenStreetMap/);
  assert.match(policies, /Google Fonts/);
  assert.match(policies, /QR Server[\s\S]*booking reference, facility name, and court name/);
  assert.match(policies, /deactivates your account[\s\S]*does not erase all stored records/);
  assert.match(policies, /retention schedule[\s\S]*not been configured/);
  const privacy = policies.slice(policies.indexOf('id="privacyModal"'), policies.indexOf('<!-- Terms of Service'));
  assert.doesNotMatch(privacy, /complies with|RA 10173|GDPR|end-to-end|never share|never store/i);
});

test('icon close controls in the player modal partial expose an accessible name', () => {
  const buttons = source.match(/<button\b[^>]*class="modal-close-btn"[^>]*>/g) || [];
  assert.ok(buttons.length > 0);
  for (const button of buttons) assert.match(button, /aria-label="Close"/);
});
