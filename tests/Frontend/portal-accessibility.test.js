const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const vm = require('node:vm');
const read = path => readFileSync('templates/web/partials/' + path + '.html.twig', 'utf8');

test('owner conversation and daily-income actions support keyboard activation without page scrolling', () => {
  for (const [file, selector] of [['owner/_tab-messages', 'inbox-item'], ['owner/_tab-dashboard', 'quick-stat-item quick-stat-clickable']]) {
    const markup = read(file).split('\n').find(line => line.includes('class="' + selector));
    assert.ok(markup.includes('role="button"') && markup.includes('tabindex="0"'));
    const handler = markup.match(/onkeydown="([^"]*)"/);
    assert.ok(handler, file + ' needs keyboard activation');
    for (const key of ['Enter', ' ', 'ArrowDown']) {
      let clicks = 0, prevented = false;
      const context = { event: { key, preventDefault() { prevented = true; } }, click() { clicks++; } };
      vm.runInNewContext(handler[1], context);
      assert.equal(clicks, key === 'ArrowDown' ? 0 : 1);
      assert.equal(prevented, key !== 'ArrowDown');
    }
  }
});

test('facility search and owner reply inputs expose persistent accessible names', () => {
  assert.ok(/id="facilitySearchInput"[^>]*aria-label="Search facilities"/.test(read('app/_tab-play')));
  assert.ok(/id="chatMessageInput"[^>]*aria-label="Reply to player"/.test(read('owner/_tab-messages')));
  assert.ok(/id="courtSearchInput"[^>]*aria-label="Filter courts by name"/.test(read('owner/_tab-courts')));
});

for (const [area, variable] of [['app', 'activeTab'], ['owner', 'tab']]) {
  for (const nav of ['_sidebar', '_bottom-nav']) test(area + ' ' + nav + ' exposes the current page only for the selected destination', () => {
    const source = read(area + '/' + nav);
    const links = source.split('\n').filter(line => /<a .*(?:nav-item-btn|bottom-nav-item|owner-nav-btn)/.test(line));
    assert.equal(links.length, 5);
    for (const link of links) {
      const destination = link.match(/\(\((?:activeTab|tab) == '([^']+)'\)/)[1];
      assert.ok(link.includes("{% if " + variable + " == '" + destination + "' %} aria-current=\"page\"{% endif %}"));
    }
  });
}

test('player preference checkboxes are named with their visible setting', () => {
  const inputs = read('app/_tab-settings').match(/<input type="checkbox"[^>]*>/g);
  const labels = ['Dark Mode', 'Booking Confirmations', 'Open Match Alerts', 'Chat &amp; Direct Messages'];
  assert.equal(inputs.length, labels.length);
  inputs.forEach((input, index) => assert.ok(input.includes('aria-label="' + labels[index] + '"')));
});
