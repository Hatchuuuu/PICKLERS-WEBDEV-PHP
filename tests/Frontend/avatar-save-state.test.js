const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { test } = require('node:test');
const vm = require('node:vm');
const flush = () => new Promise(resolve => setImmediate(resolve));

function setup() {
  const pending = [], toasts = [];
  const nodes = { settingsAvatarContainer: { innerHTML: 'old settings avatar' }, mobileHeaderAvatarImg: { src: 'old.png' },
    sidebarUserAvatar: { innerHTML: 'old sidebar avatar' } };
  const c = { document: { getElementById: id => nodes[id] }, getCsrfToken: () => 'csrf',
    downscaleImageFile: async () => 'data:image/png;base64,new', showToast: (...args) => toasts.push(args),
    fetch: (url, options) => new Promise((resolve, reject) => pending.push({ url, options, reject,
      resolve: body => resolve({ json: async () => body }) })) };
  vm.createContext(c);
  const source = readFileSync('public/assets/js/app.js', 'utf8');
  vm.runInContext(source.slice(source.indexOf('function escapeHtml('), source.indexOf('// A value passed as a string argument')) +
    source.slice(source.indexOf('function handleAvatarUpload('), source.indexOf('// Player Profile Management')), c);
  c.handleAvatarUpload({ target: { files: [{ type: 'image/png', size: 100 }] } });
  return { nodes, pending, toasts };
}

function assertOriginal(nodes) {
  assert.equal(nodes.settingsAvatarContainer.innerHTML, 'old settings avatar');
  assert.equal(nodes.mobileHeaderAvatarImg.src, 'old.png');
  assert.equal(nodes.sidebarUserAvatar.innerHTML, 'old sidebar avatar');
}

test('avatar remains unchanged until the save is confirmed', async () => {
  const { nodes, pending } = setup(); await flush();
  assert.equal(JSON.parse(pending[0].options.body).avatar_url, 'data:image/png;base64,new');
  assertOriginal(nodes);
});

test('rejected, offline or malformed avatar save preserves every displayed avatar', async () => {
  for (const result of [{ success: false, message: 'Expired session' }, null, { success: true }]) {
    const { nodes, pending, toasts } = setup(); await flush();
    if (result === null) pending[0].reject(new Error('offline'));
    else pending[0].resolve(result);
    await flush();
    assertOriginal(nodes);
    assert.equal(toasts.at(-1)[1], 'error');
  }
});

test('successful avatar upload displays the server persisted image everywhere', async () => {
  const { nodes, pending, toasts } = setup(); await flush();
  pending[0].resolve({ success: true, user: { avatar_url: 'data:image/webp;base64,persisted' } }); await flush();
  assert.match(nodes.settingsAvatarContainer.innerHTML, /data:image\/webp;base64,persisted/);
  assert.equal(nodes.mobileHeaderAvatarImg.src, 'data:image/webp;base64,persisted');
  assert.match(nodes.sidebarUserAvatar.innerHTML, /data:image\/webp;base64,persisted/);
  assert.equal(toasts.at(-1)[1], 'success');
});
