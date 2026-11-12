const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const vm = require('node:vm');
const { execFileSync } = require('node:child_process');
const { test } = require('node:test');
const source = readFileSync('templates/web/home.html.twig', 'utf8');

test('landing renders peso prices, ratings and navigation without corrupted characters', () => {
  const html = execFileSync('php', ['-r', `
    require 'vendor/autoload.php';
    $twig = new Twig\\Environment(new Twig\\Loader\\FilesystemLoader('templates'), ['strict_variables' => true]);
    $twig->addFilter(new Twig\\TwigFilter('float', static fn($value) => (float)$value));
    $twig->addFilter(new Twig\\TwigFilter('int', static fn($value) => (int)$value));
    $twig->addFunction(new Twig\\TwigFunction('stripos_found', static fn($value, $needle) => stripos($value, $needle) !== false));
    $twig->addFunction(new Twig\\TwigFunction('hours_label', Picklers\\Web\\Twig\\ViewExtension::hoursLabel(...)));
    $twig->addFunction(new Twig\\TwigFunction('addslashes', addslashes(...)));
    $twig->addFunction(new Twig\\TwigFunction('asset', static fn($path) => $path));
    echo $twig->render('web/home.html.twig', [
      'site_name' => 'PICKLERS', 'currentUser' => ['avatar_url' => ''],
      'brands' => [], 'faqs' => [], 'city_count' => 1,
      'facilities' => [
        ['min_price' => 250, 'max_price' => 350, 'rating' => 4.9, 'reviews' => 142, 'real_courts_count' => 5],
        ['min_price' => 200, 'max_price' => 200, 'real_courts_count' => 1],
      ],
      'matches' => [['price' => 150]],
    ]);
  `], { encoding: 'utf8' });
  const text = html.replace(/&#(\d+);/g, (_, code) => String.fromCodePoint(Number(code)));
  assert.match(text, /₱250 - ₱350/);
  assert.match(text, /₱200/);
  assert.match(text, /₱150/);
  assert.match(text, /★ 4\.9/);
  assert.match(text, /Open App →/);
  assert.match(text, /© \d{4} Picklers PH/);
  assert.doesNotMatch(text, /â|Ã|Â|\uFFFD/);
});

test('mobile navigation opens, dismisses with Escape and returns focus', () => {
  const handlers = {}, attrs = {}, classes = new Set(); let focused = false;
  const toggle = { getAttribute: key => attrs[key], setAttribute: (key, value) => attrs[key] = value,
    addEventListener: (key, fn) => handlers['toggle:' + key] = fn, focus: () => focused = true };
  const nav = { classList: { toggle: (key, enabled) => enabled ? classes.add(key) : classes.delete(key) },
    addEventListener: (key, fn) => handlers['nav:' + key] = fn };
  const context = { document: { getElementById: id => id === 'mobileToggle' ? toggle : nav,
    addEventListener: (key, fn) => handlers[key] = fn } };
  vm.createContext(context);
  const start = source.indexOf('    const mobileToggle =');
  assert.notEqual(start, -1, 'mobile toggle must have behavior');
  vm.runInContext(source.slice(start, source.indexOf('    // 2. Scroll-', start)), context);
  handlers['toggle:click'](); assert.equal(attrs['aria-expanded'], 'true'); assert(classes.has('is-open'));
  handlers.keydown({ key: 'Escape' }); assert.equal(attrs['aria-expanded'], 'false'); assert.equal(focused, true);
  handlers['toggle:click'](); handlers['nav:click']({ target: { closest: () => ({}) } });
  assert.equal(attrs['aria-expanded'], 'false');
  assert.match(source, /id="mobileToggle"[^>]*aria-controls="homeNav"[^>]*aria-expanded="false"/);
});

test('FAQ buttons expose expanded state and hide collapsed answers', () => {
  const items = [false, false].map(() => ({ classList: { toggle() {}, contains: () => false, remove() {} },
    button: { getAttribute(key) { return this[key]; }, setAttribute(key, value) { this[key] = value; } }, answer: {},
    querySelector(selector) { return selector === '.faq-question-btn' ? this.button : this.answer; } }));
  const context = { document: { querySelectorAll: () => items } }; vm.createContext(context);
  vm.runInContext(source.slice(source.indexOf('    function toggleFaq('), source.indexOf('    // 6. Interactive')), context);
  context.toggleFaq(items[0]); assert.equal(items[0].button['aria-expanded'], 'true'); assert.equal(items[0].answer.hidden, false);
  context.toggleFaq(items[1]); assert.equal(items[0].answer.hidden, true); assert.equal(items[1].button['aria-expanded'], 'true');
  context.toggleFaq(items[1]); assert.equal(items[1].answer.hidden, true);
  assert.match(source, /<button type="button" class="faq-question-btn"[^>]*aria-expanded="false"/);
});

test('landing policy and navigation advertise only available destinations and payments', () => {
  assert.doesNotMatch(source, /href="#"/);
  assert.match(source, /href="owner-application.php">List Your Court/);
  assert.doesNotMatch(source, /instant cashless top-ups|instant 100% refund/);
  assert.match(source, /Real-money top-ups are currently unavailable/);
  assert.match(source, /external payments.*contact the facility/i);
});

test('reduced-motion counters immediately show the final formatted value without animation', () => {
  let frames = 0;
  const context = { window: { matchMedia: () => ({ matches: true }) }, performance: { now: () => 0 },
    requestAnimationFrame: () => frames++ };
  vm.createContext(context);
  const start = source.indexOf('    function animateCountUp(');
  vm.runInContext(source.slice(start, source.indexOf('  </script>', start)), context);
  const counter = { getAttribute: name => name === 'data-target' ? '12000' : ',', textContent: '0' };
  context.animateCountUp(counter);
  assert.equal(counter.textContent, (12000).toLocaleString());
  assert.equal(frames, 0);
});

test('landing shows sourced counts and existing capabilities without fabricated social proof', () => {
  assert.doesNotMatch(source, /Split Payments|split court costs|Split the fee|leaderboards|Join clubs|15 seconds|testimonial-card|toggleFav\(|TRUSTED BY FAMOUS|#1 Philippines|data-target="\d+"/i);
  assert.match(source, /data-target="{{ facilities\|length }}"/);
  assert.match(source, /data-target="{{ matches\|length }}"/);
  assert.match(source, /Booking Tracking/);
  assert.doesNotMatch(source, /class="card-heart-btn"/);
});

test('statistics fill four columns and count actual listed courts, including an empty catalogue', () => {
  const output = execFileSync('php', ['-r', `
    require 'vendor/autoload.php';
    $source = file_get_contents('templates/web/home.html.twig');
    preg_match('/<section class="stats-section">.*?<\\/section>/s', $source, $section);
    $twig = new Twig\\Environment(new Twig\\Loader\\ArrayLoader(['stats' => $section[0]]), ['strict_variables' => true]);
    $results = [];
    foreach ([[], [['real_courts_count' => '2', 'courts_count' => 99], ['real_courts_count' => '3', 'courts_count' => 99]]] as $facilities) {
      $html = $twig->render('stats', ['facilities' => $facilities, 'matches' => [], 'city_count' => 0]);
      preg_match_all('/data-target="(\\d+)"/', $html, $counts);
      $results[] = $counts[1];
    }
    echo json_encode($results);
  `], { encoding: 'utf8' });
  assert.deepEqual(JSON.parse(output), [['0', '0', '0', '0'], ['2', '0', '0', '5']]);
});
