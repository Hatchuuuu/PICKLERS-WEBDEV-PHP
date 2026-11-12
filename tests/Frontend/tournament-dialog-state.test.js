const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { test } = require('node:test');
const vm = require('node:vm');

function harness() {
  const nodes = {}, handlers = {};
  const document = {
    body: { style: { overflow: '' } }, activeElement: null,
    getElementById: id => nodes[id], contains: node => !!node?.connected,
    addEventListener: (name, handler) => handlers[name] = handler,
    querySelector: selector => Object.values(nodes).find(n => selector.includes('tb-crown') ? n.id === 'tbCrown' && n.open : n.id === 'match' && n.open) || null,
    querySelectorAll: () => Object.values(nodes).filter(n => n.matchId),
  };
  function node(id) {
    return nodes[id] = { id, connected: true, open: false, hidden: false, innerHTML: '',
      getAttribute: name => name === 'data-match' ? nodes[id].matchId : null,
      classList: { add: () => nodes[id].open = true, remove: () => nodes[id].open = false, contains: name => name === 'is-open' ? nodes[id].open : name === 'tb-crown' && id === 'tbCrown' },
      querySelector() { return this.button; }, querySelectorAll() { return this.button ? [this.button] : []; },
      contains(target) { return target === this || target === this.button; },
      focus() { if (this.connected) document.activeElement = this; },
    };
  }
  for (const id of ['tbCrown', 'tbCrownName', 'tbCrownPlayers', 'tbCrownEyebrow', 'tbConfetti']) node(id);
  const championButton = node('championButton'), closeButton = node('closeButton'); nodes.tbCrown.button = closeButton;
  const context = { document, reduceMotion: true, state: { activeMatchId: 'final', tournament: { champion_team_id: 't1', champion: 'Winners', title: 'Cup' } },
    el: { crownBtn: championButton }, teamOf: () => ({ name: 'Winners', player1: 'Player' }) };
  vm.createContext(context);
  const source = readFileSync('public/assets/js/tournament.js', 'utf8');
  vm.runInContext(source.slice(source.indexOf('  var crown = {'), source.indexOf('  // Inline SVG')), context);
  return { context, document, node, nodes, championButton, closeButton, handlers };
}

test('champion dialog locks scrolling and returns focus when dismissed', () => {
  const h = harness(); h.championButton.focus(); h.context.openCrown();
  assert.equal(h.document.body.style.overflow, 'hidden');
  assert.equal(h.document.activeElement, h.closeButton);
  h.context.closeCrown();
  assert.equal(h.document.body.style.overflow, '');
  assert.equal(h.document.activeElement, h.championButton);
  assert.equal(h.nodes.tbConfetti.innerHTML, '');
});

test('closing a final-match editor never takes focus behind the champion dialog', () => {
  const h = harness(), trigger = h.node('trigger'), match = h.node('match'); match.button = h.node('matchButton');
  trigger.focus(); h.context.openModal(match); h.context.openCrown(); h.context.closeModal(match);
  assert.equal(h.document.activeElement, h.closeButton);
  assert.equal(h.document.body.style.overflow, 'hidden');
  h.context.closeCrown();
  assert.equal(h.document.activeElement, trigger);
});

test('a re-rendered bracket restores focus to the replacement match control', () => {
  const h = harness(), original = h.node('original'), match = h.node('match'); match.button = h.node('matchButton');
  original.matchId = 'final'; original.focus(); h.context.openModal(match);
  original.connected = false; original.matchId = null;
  const replacement = h.node('replacement'); replacement.matchId = 'final';
  h.context.closeModal(match);
  assert.equal(h.document.activeElement, replacement);
});
