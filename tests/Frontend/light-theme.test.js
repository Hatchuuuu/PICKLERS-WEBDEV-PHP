const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const vm = require('node:vm');
const { test } = require('node:test');
const read = path => readFileSync(path, 'utf8');
const theme = read('templates/web/partials/_theme.html.twig');

test('every entry point restores the saved theme before rendering content', () => {
  for (const file of ['home.html.twig', 'auth.html.twig', 'owner-application.html.twig', 'partials/app/_head.html.twig', 'partials/owner/_head.html.twig', '../admin/base.html.twig']) {
    const source = read('templates/web/' + file);
    assert(source.indexOf("include 'web/partials/_theme.html.twig'") < source.indexOf('rel="stylesheet"'), file);
    assert.match(source, /include 'web\/partials\/_theme.html.twig'/, file);
  }
  for (const saved of ['light', 'dark', null, 'invalid', 'blocked']) {
    const classes = new Set(['dark']);
    vm.runInNewContext(theme.replace(/<\/?script>/g, ''), {
      localStorage: { getItem() { if (saved === 'blocked') throw new Error('Storage disabled'); return saved; } },
      document: { documentElement: { classList: { toggle(name, enabled) { enabled ? classes.add(name) : classes.delete(name); } } } },
    });
    assert.deepEqual([...classes], [saved === 'light' ? 'light' : 'dark']);
  }
});

function contrast(a, b) {
  const luminance = hex => hex.match(/[a-f\d]{2}/gi).map(v => parseInt(v, 16) / 255)
    .map(v => v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4)
    .reduce((sum, v, i) => sum + v * [0.2126, 0.7152, 0.0722][i], 0);
  const x = luminance(a), y = luminance(b);
  return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05);
}

test('light text tokens meet AA on page and card surfaces, including accent text', () => {
  const css = read('public/assets/css/style.css');
  const light = css.slice(0, css.indexOf('html.dark {'));
  const token = name => light.match(new RegExp(name + ': (#[a-f\\d]{6})', 'i'))[1];
  for (const fg of ['--ink-primary', '--ink-secondary', '--ink-muted', '--accent-primary-text', '--accent-warning-text', '--accent-danger-text', '--accent-secondary-text']) {
    for (const bg of ['--surface-base', '--surface-raised', '--surface-overlay']) {
      assert(contrast(token(fg), token(bg)) >= 4.5, `${fg} on ${bg}`);
    }
  }
});

test('filled actions use the requested white foreground in both themes', () => {
  const css = read('public/assets/css/style.css');
  for (const [, color] of css.matchAll(/--on-accent:\s*([^;]+);/g)) assert.equal(color, '#FFFFFF');
  assert.match(css, /html.light \{[^}]*--on-accent: #FFFFFF;/);
  for (const selector of ['.btn-hero-primary', '.btn-nav-signup', '.btn-view-courts', '.pill-btn.active']) {
    const block = css.slice(css.indexOf(selector + ' {')).split('}')[0];
    assert.match(block, /color: var\(--on-accent\)/, selector);
  }
});

test('shared prices, brand labels and native controls follow theme tokens', () => {
  const css = read('public/assets/css/style.css');
  for (const [selector, token] of [['.card-price-cyan', '--ink-primary'], ['.brand-tag', '--ink-secondary'], ['.openplay-card-title', '--ink-primary'], ['.btn-view-courts', '--on-accent']]) {
    const block = css.slice(css.indexOf(selector + ' {')).split('}')[0];
    assert(block.includes(`color: var(${token})`), selector);
  }
  assert.match(css, /html \{[^}]*color-scheme: light;/);
  const select = css.slice(css.indexOf('select {')).split('}')[0];
  assert.doesNotMatch(select, /color-scheme: dark/);
  assert.match(css, /select option \{[^}]*background-color: var\(--surface-overlay\)/);
  const owner = read('public/assets/css/owner.css');
  assert.match(owner, /html.light \.owner-mobile-header \{[^}]*background: var\(--pk-bg-card\)/);
  assert.match(owner, /html.light \.owner-segmented-pills \{[^}]*background: var\(--pk-input-bg\)/);
  const itemNameBlock = owner.slice(owner.indexOf('.autocomplete-item-name {')).split('}')[0];
  assert(itemNameBlock.includes('color: var(--pk-text-primary)'), 'autocomplete name must follow primary theme token');
  assert.doesNotMatch(itemNameBlock, /color:\s*#FFFFFF/i, 'autocomplete name must not hardcode white text');
});
