const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const vm = require('node:vm');
const { test } = require('node:test');

const source = readFileSync('templates/web/home.html.twig', 'utf8');
const script = source.slice(source.indexOf('// 4. Drag the brand strip'), source.indexOf('// 5. Accordion'));

function setup(reduced = false) {
  const handlers = {}, timers = new Map();
  let timerId = 0;
  const animation = {
    currentTime: 18000, paused: false, plays: 0,
    effect: { getTiming: () => ({ duration: 36000 }) },
    pause() { this.paused = true; },
    play() { this.paused = false; this.plays++; },
  };
  const track = {
    getAnimations: () => reduced ? [] : [animation],
    getBoundingClientRect: () => ({ width: 1972 }),
    style: {},
  };
  const marquee = {
    querySelector: () => track,
    addEventListener: (type, handler) => { handlers[type] = handler; },
    setPointerCapture(id) { this.captured = id; },
  };
  vm.runInNewContext(script, {
    document: { getElementById: () => marquee },
    window: { matchMedia: () => ({ matches: reduced }) },
    getComputedStyle: () => ({ columnGap: '28px' }),
    setTimeout: (fn, delay) => { timers.set(++timerId, { fn, delay }); return timerId; },
    clearTimeout: id => timers.delete(id),
  });
  const send = (type, values = {}) => handlers[type]({ isPrimary: true, button: 0, pointerId: 1, clientX: 100, ...values });
  return { animation, track, marquee, timers, send };
}

test('drag follows either direction and resumes from the dragged position after 1.5 seconds', () => {
  const { animation, marquee, timers, send } = setup();
  send('pointerdown');
  assert.equal(animation.paused, true);
  assert.equal(marquee.captured, 1);
  send('pointermove', { clientX: 200 });
  assert.equal(animation.currentTime, 21600);
  send('pointermove', { clientX: 50 });
  assert.equal(animation.currentTime, 16200);
  send('pointerup');
  assert.equal(animation.paused, true);
  const timer = [...timers.values()][0];
  assert.equal(timer.delay, 1500);
  timer.fn();
  assert.equal(animation.paused, false);
  assert.equal(animation.currentTime, 16200);
});

test('wraps in both directions and a new drag cancels the pending resume', () => {
  const { animation, timers, send } = setup();
  send('pointerdown');
  send('pointermove', { clientX: 1700 });
  assert.equal(animation.currentTime, 3600);
  send('pointermove', { clientX: -500 });
  assert.equal(animation.currentTime, 32400);
  send('pointerup');
  send('pointerdown');
  assert.equal(timers.size, 0);
  assert.equal(animation.paused, true);
});

test('cancellation and lost capture schedule one resume; unrelated pointers are ignored', () => {
  for (const end of ['pointercancel', 'lostpointercapture']) {
    const { animation, timers, send } = setup();
    send('pointerdown', { button: 2 });
    assert.equal(animation.paused, false);
    send('pointerdown');
    send('pointermove', { pointerId: 2, clientX: 900 });
    send('pointerup', { pointerId: 2 });
    assert.equal(animation.currentTime, 18000);
    assert.equal(timers.size, 0);
    send(end);
    send('lostpointercapture');
    assert.equal(timers.size, 1);
  }
});

test('reduced motion still allows manual touch swiping without starting automatic motion', () => {
  const { animation, track, timers, send } = setup(true);
  send('pointerdown', { pointerType: 'touch' });
  send('pointermove', { clientX: 200, pointerType: 'touch' });
  assert.equal(track.style.transform, 'translateX(-900px)');
  send('pointerup');
  [...timers.values()][0].fn();
  assert.equal(animation.plays, 0);
});
