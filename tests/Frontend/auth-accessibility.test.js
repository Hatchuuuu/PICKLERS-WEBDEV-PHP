const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
test('auth controls have programmatic labels and normal keyboard order', () => {
  const html = fs.readFileSync('templates/web/auth.html.twig', 'utf8');
  assert.match(html, /<label[^>]*for="signInIdentifier"/);
  assert.match(html, /<label[^>]*for="signInPassword"/);
  assert.doesNotMatch(html, /tabindex="[123]"/);
  assert.doesNotMatch(html, /id="toggleMethodLink"[^>]*tabindex="-1"/);
  for (const name of ['Full Name', 'Email address', 'Password', 'Confirm password']) {
    assert.ok(html.includes('aria-label="' + name + '"'), name + ' needs a label');
  }
});

test('profile, password and account deletion fields have associated visible labels', () => {
  const html = fs.readFileSync('templates/web/partials/app/_modals.html.twig', 'utf8');
  for (const id of ['deleteAccountConfirmInput', 'modalProfileNameInput', 'modalProfilePhoneInput', 'currentPassInput', 'newPassInput', 'confirmPassInput']) {
    assert.match(html, new RegExp('<label[^>]*for="' + id + '"'), id + ' needs its visible label');
  }
});

test('actionable settings rows and avatar support keyboard activation without scrolling', () => {
  const html = fs.readFileSync('templates/web/partials/app/_tab-settings.html.twig', 'utf8');
  const controls = [...html.matchAll(/<div[^>]*class="(?:settings-row-item|settings-avatar-ring-wrap)"[^>]*onclick="[^"]+"[^>]*>/g)];
  assert.equal(controls.length, 7);
  for (const [tag] of controls) {
    assert.match(tag, /role="button"/); assert.match(tag, /tabindex="0"/);
    const handler = tag.match(/onkeydown="([^"]+)"/); assert.ok(handler, 'settings action needs keyboard support');
    let clicks = 0, prevented = 0;
    const context = { event: { key: 'Enter', preventDefault: () => prevented++ }, click: () => clicks++ };
    vm.runInNewContext(handler[1], context);
    context.event.key = ' '; vm.runInNewContext(handler[1], context);
    context.event.key = 'Tab'; vm.runInNewContext(handler[1], context);
    assert.equal(clicks, 2); assert.equal(prevented, 2);
  }
});
