const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const vm = require('node:vm');
const source = readFileSync('public/assets/js/app.js', 'utf8');

function passwordForm(current, password, confirmation) {
  const elements = {
    currentPassInput: { value: current }, newPassInput: { value: password },
    confirmPassInput: { value: confirmation },
  };
  const requests = [], messages = [];
  const context = {
    document: { getElementById: id => elements[id], querySelector: () => null },
    FormData, getCsrfToken: () => 'test-token', closeModal() {},
    showToast: message => messages.push(message), console,
    fetch: async (url, options) => {
      requests.push(Object.fromEntries(options.body));
      return { json: async () => ({ success: true }) };
    },
  };
  vm.createContext(context);
  vm.runInContext(source.slice(source.indexOf('async function submitPasswordChange('), source.indexOf('function togglePasswordVisibility(')), context);
  return { context, requests, messages };
}

test('password change submits the exact current and new credentials, including surrounding spaces', async () => {
  const form = passwordForm(' Old-Secret-123 ', ' New-Secret-456 ', ' New-Secret-456 ');
  await form.context.submitPasswordChange();
  assert.equal(form.requests.length, 1);
  assert.equal(form.requests[0].current_password, ' Old-Secret-123 ');
  assert.equal(form.requests[0].new_password, ' New-Secret-456 ');
  assert.equal(form.requests[0].confirm_password, ' New-Secret-456 ');
});

test('confirmation that differs only by spaces is rejected before submission', async () => {
  const form = passwordForm('Old-Secret-123', ' New-Secret-456 ', 'New-Secret-456');
  await form.context.submitPasswordChange();
  assert.equal(form.requests.length, 0);
  assert.match(form.messages[0], /do not match/);
});
