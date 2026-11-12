const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { test } = require('node:test');
const css = readFileSync('public/assets/css/app.css', 'utf8');

test('booking labels and shared prices use theme colors rather than fixed white', () => {
  for (const selector of ['.card-price-cyan', '.booking-subnav-btn', '.booking-subnav-btn:hover', '.booking-subnav-btn.active']) {
    const block = css.slice(css.indexOf('    ' + selector + ' {')).split('}')[0];
    assert.match(block, /color: var\(--pk-text-primary\)/, selector);
  }
  for (const selector of ['.booking-subnav-btn .booking-subnav-label', '.booking-subnav-btn.active .booking-subnav-label']) {
    const block = css.slice(css.indexOf('    ' + selector + ' {')).split('}')[0];
    assert.match(block, /color: inherit !important/, selector);
  }
});
