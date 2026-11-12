const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { test } = require('node:test');

for (const surface of ['app', 'owner']) test(surface + ' header avatar does not clip its outer gradient ring or oversize its glow', () => {
  const css = readFileSync('public/assets/css/' + surface + '.css', 'utf8');
  const wrapper = css.match(/\}\s*\.user-avatar-wrap\s*\{([^}]+)\}/)[1];
  assert.match(wrapper, /overflow:\s*visible/);
  const wrappers = [...css.matchAll(/\.user-avatar-wrap\s*\{([^}]+)\}/g)];
  for (const [, rule] of wrappers) assert.doesNotMatch(rule, /overflow:\s*hidden/);
  const oldGlow = css.match(/\n\s*\.user-avatar-wrap::before\s*\{([^}]+)\}/);
  if (oldGlow) assert.doesNotMatch(oldGlow[1], /(?:width|height):\s*160%/);
});
