const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const vm = require('node:vm');
const { test } = require('node:test');
const source = readFileSync('templates/web/auth.html.twig', 'utf8');

test('recovery explains the installation limitation and returns focus to sign in', () => {
  assert.match(source, /<button type="button"[^>]*onclick="showView\('forgot-password'\)"[^>]*>Forgot Password\?<\/button>/);
  assert.doesNotMatch(source, /mailto:support@picklers.ph/);
  assert.match(source, /Password recovery is unavailable in this installation/);
  assert.match(source, /contact the person managing this installation/);
  assert.doesNotMatch(source, /Use the demonstration account credentials/);
  const elements = { authViewMain: { style: {} }, authViewForgot: { style: {} } };
  let focused;
  elements.recoveryHeading = { focus: () => focused = 'recoveryHeading' };
  elements.signInIdentifier = { focus: () => focused = 'signInIdentifier' };
  const context = { document: { getElementById: id => elements[id] } };
  vm.createContext(context);
  vm.runInContext(source.slice(source.indexOf('function showView('), source.indexOf('function otpNext(')), context);
  context.showView('forgot-password');
  assert.equal(elements.authViewMain.style.display, 'none');
  assert.equal(elements.authViewForgot.style.display, 'block');
  assert.equal(focused, 'recoveryHeading');
  context.showView('main');
  assert.equal(elements.authViewMain.style.display, 'block');
  assert.equal(elements.authViewForgot.style.display, 'none');
  assert.equal(focused, 'signInIdentifier');
});
