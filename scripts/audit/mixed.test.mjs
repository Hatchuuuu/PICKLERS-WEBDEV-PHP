import test from 'node:test';
import assert from 'node:assert/strict';
import { settings, summarize, run } from './mixed.mjs';

test('local-only opt-in, workload bounds and no provider are mandatory', () => {
  assert.throws(() => settings({}));
  const valid = { localPresentationOnly: true, externalServicesDisabled: true, mixedClients: 3, mixedCycles: 1 };
  assert.deepEqual(settings(valid), { clients: 3, cycles: 1 });
  for (const mixedClients of [0, 1, 10, 1.5, '3']) assert.throws(() => settings({ ...valid, mixedClients }));
  for (const mixedCycles of [0, 4, NaN, '1']) assert.throws(() => settings({ ...valid, mixedCycles }));
  assert.throws(() => settings({ ...valid, externalServicesDisabled: false }));
});
test('summary includes failed requests in latency and reports failures', () => {
  const s = summarize([{ action: 'read', elapsedMs: 10, outcome: 'passed' }, { action: 'write', elapsedMs: 100, outcome: 'failed' }]);
  assert.equal(s.requests, 2); assert.equal(s.errors, 1); assert.equal(s.errorRate, .5);
  assert.equal(s.p50Ms, 10); assert.equal(s.p95Ms, 100); assert.equal(s.p99Ms, 100);
  assert.equal(s.byAction.write.errors, 1);
});
function fake({ corruptWallet = false, failSend = false } = {}) {
  const users = new Map(), bookings = [], messages = []; let sequence = 0, active = 0, peak = 0;
  const state = n => { if (!users.has(n)) users.set(n, { balance: 100000, transactions: [] }); return users.get(n); };
  const sessionFactory = () => ({ async login(email) { this.n = Number(email.match(/user(\d+)/)[1]); state(this.n); } });
  const request = async (session, action, params, options = {}) => {
    active++; peak = Math.max(peak, active); await new Promise(resolve => setImmediate(resolve)); active--;
    const u = state(session.n), uid = n => `audit_user_${String(n).padStart(5, '0')}`;
    if (action === 'wallet') return { balance: u.balance / 100, transactions: [...u.transactions] };
    if (action === 'bookings') return { bookings: bookings.filter(b => b.user_id === uid(session.n)) };
    if (action === 'quote_booking') return { quote: { total: '300.00' } };
    if (action === 'book_court' || action === 'join_match') {
      const booking = { id: `b${++sequence}`, user_id: uid(session.n), ...params, status: 'pending', price: '300.00' };
      bookings.push(booking); u.balance -= 30000; u.transactions.push({ booking_id: booking.id, type: 'debit' });
      return { booking, quote: { total: '300.00' } };
    }
    if (action === 'approve_booking') { bookings.find(b => b.id === params.booking_id).status = 'confirmed'; return {}; }
    if (action === 'cancel_booking') {
      const b = bookings.find(b => b.id === params.booking_id);
      if (b.status === 'cancelled') { assert.equal(options.success, false); return { success: false }; }
      b.status = 'cancelled'; u.balance += corruptWallet ? 29999 : 30000; u.transactions.push({ booking_id: b.id, type: 'credit' }); return {};
    }
    if (action === 'send_message') { if (failSend) throw new Error('fake_send_failure'); const message = { id: `m${++sequence}`, sender_id: uid(session.n), receiver_id: params.partner_id, content: params.content, is_read: 0 }; messages.push(message); return { message }; }
    if (action === 'messages') { for (const m of messages) if (m.receiver_id === uid(session.n)) m.is_read = 1; return { messages: messages.filter(m => [m.sender_id,m.receiver_id].includes(uid(session.n)) && [m.sender_id,m.receiver_id].includes(params.partner_id)) }; }
    if (action === 'mark_notifications_read') return {};
    if (action === 'me') return { notifications: [{ is_read: 1 }] };
    if (action === 'sync') return { unread_notifications: 0 };
    if (action === 'matches') return { matches: [{ id: 'audit_match_99', current_players: 0 }] };
    if (action === 'facility_detail') return { courts: [{ id: 'audit_court_393', name: 'Court 1' }] };
    throw new Error(`unexpected action ${action}`);
  };
  return { sessionFactory, request, bookings, peak: () => peak };
}
const m = { localPresentationOnly: true, externalServicesDisabled: true, mixedClients: 3, mixedCycles: 1, baseUrl: 'http://127.0.0.1:1234/', fixturePassword: 'synthetic-password' };
test('bounded concurrent writes, cross-role approval and readback complete', async () => {
  const f = fake(); const result = await run(m, f);
  assert.equal(result.failures, 0); assert.equal(result.sessionCount, 4); assert(f.peak() > 1); assert(f.peak() <= 3);
  assert(f.bookings.every(b => b.status === 'cancelled'));
  assert(result.metrics.byAction.approve_booking.requests >= 1);
  assert(result.metrics.byAction.join_match.requests >= 1);
  assert(result.metrics.byAction.mark_notifications_read.requests >= 3);
});
test('wallet invariant failure and send failure fail the run, booking cleanup remains', async () => {
  for (const mode of [{ corruptWallet: true }, { failSend: true }]) {
    const f = fake(mode), result = await run(m, f);
    assert(result.failures > 0); assert(f.bookings.every(b => b.status === 'cancelled'));
  }
});

