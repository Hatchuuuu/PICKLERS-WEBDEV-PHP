import test from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import { api, cents, decode, inside } from './flows.mjs';

test('money checks reject NaN and fractional cents', () => {
  assert.equal(cents('300.00'), 30000);
  assert.equal(cents(0.1), 10);
  assert.throws(() => cents('bad')); assert.throws(() => cents('1.001'));
});
test('containment rejects root, sibling and traversal', () => {
  const root = path.resolve('var');
  assert(inside(root, path.join(root, 'audit', 'manifest.json')));
  assert(!inside(root, root)); assert(!inside(root, path.resolve('var-sibling/file')));
  assert(!inside(root, path.resolve('var/../outside')));
});
test('JSON parser rejects HTML, malformed JSON and missing contract', () => {
  const response = { status: 200, headers: { 'content-type': 'application/json' }, body: '{"success":true}' };
  assert.equal(decode(response).success, true);
  assert.throws(() => decode({ ...response, headers: { 'content-type': 'text/html' } }));
  assert.throws(() => decode({ ...response, body: '<html>' }));
  assert.throws(() => decode({ ...response, body: '{}' }));
});
test('fake HTTP checks form encoding, CSRF, status and semantic failures', async () => {
  const fake = { token: 'token', async request(route, options) {
    assert.equal(route, 'owner.php?action=add_court');
    assert.equal(options.method, 'POST'); assert.equal(options.headers['X-CSRF-Token'], 'token');
    assert.equal(new URLSearchParams(options.body).get('name'), 'Court 9 & 10');
    return { status: 200, headers: { 'content-type': 'application/json' }, body: '{"success":true,"court":{"id":"court"}}' };
  } };
  assert.equal((await api(fake, 'add_court', { name: 'Court 9 & 10' }, { method: 'POST', endpoint: 'owner.php' })).court.id, 'court');
  const deny = { async request() { return { status: 403, headers: { 'content-type': 'application/json' }, body: '{"success":false}' }; } };
  await assert.rejects(() => api(deny, 'me'));
  assert.equal((await api(deny, 'me', {}, { expected: 403, success: false })).httpStatus, 403);
  const semantic = { async request() { return { status: 200, headers: { 'content-type': 'application/json' }, body: '{"success":false}' }; } };
  await assert.rejects(() => api(semantic, 'me'));
});
