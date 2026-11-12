const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/assets/js/owner.js', 'utf8');

test('request review shows exact card context and never sends approval until confirmed', () => {
  const start = source.indexOf('function requestReviewSummary(');
  assert.ok(start >= 0, 'request review must exist');
  const nodes = { acceptRequestSummary: {}, declineRequestSummary: {}, declineReason: { selectedIndex: 3 } };
  const fields = { '.req-court-title': 'Court 2', '.req-schedule-subtitle': 'Tomorrow 6:00 PM', '.req-price-cyan': '₱350', '.req-payment-method-badge': 'GCASH' };
  const opened = [], approved = [];
  nodes.req_card_A = { querySelector: selector => ({ textContent: fields[selector] }) };
  const c = { document: { getElementById: id => nodes[id] }, activeAcceptRequest: null, activeDeclineId: null,
    acceptBookingInFlight: false, declineBookingInFlight: false, openModal: id => opened.push(id),
    acceptBooking: (...args) => approved.push(args) };
  vm.createContext(c);
  vm.runInContext(source.slice(start, source.indexOf('function acceptBooking(')), c);
  c.openAcceptModal('A', '<Paula>');
  assert.equal(approved.length, 0);
  assert.match(nodes.acceptRequestSummary.textContent, /A.*<Paula>/s);
  for (const value of Object.values(fields)) assert.ok(nodes.acceptRequestSummary.textContent.includes(value));
  c.confirmAccept();
  assert.deepEqual(approved, [['A', '<Paula>']]);
  vm.runInContext(source.slice(source.indexOf('function openDeclineModal('), source.indexOf('function confirmDecline(')), c);
  c.openDeclineModal('A', 'Pedro');
  assert.equal(nodes.declineReason.selectedIndex, 0);
  assert.match(nodes.declineRequestSummary.textContent, /Pedro/);
  c.declineBookingInFlight = true;
  c.openDeclineModal('B', 'Another');
  assert.equal(c.activeDeclineId, 'A', 'in-flight operation cannot be retargeted');
  assert.deepEqual(opened, ['acceptModal', 'declineModal']);
});

test('initial and refreshed owner cards both open review instead of approving immediately', () => {
  const template = fs.readFileSync('templates/web/partials/owner/_tab-dashboard.html.twig', 'utf8');
  assert.match(template, /onclick="openAcceptModal\(/);
  assert.match(source, /onclick="openAcceptModal\(/);
  assert.doesNotMatch(template, /onclick="acceptBooking\(/);
});
