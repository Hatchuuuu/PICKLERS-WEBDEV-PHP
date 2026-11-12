import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { performance } from 'node:perf_hooks';
import { pathToFileURL, fileURLToPath } from 'node:url';
import { execFileSync } from 'node:child_process';
import { manifest, api, cents } from './flows.mjs';
import { Session, percentile } from './load.mjs';

export function settings(m) {
  assert.equal(m.localPresentationOnly, true, 'explicit local presentation opt-in required');
  assert.equal(m.externalServicesDisabled, true, 'external services must be disabled');
  const clients = m.mixedClients ?? 3, cycles = m.mixedCycles ?? 1;
  assert(Number.isInteger(clients) && clients >= 2 && clients <= 9, '2–9 player clients required (plus one owner)');
  assert(Number.isInteger(cycles) && cycles >= 1 && cycles <= 3, '1–3 cycles required');
  return { clients, cycles };
}
export function summarize(rows) {
  const count = group => ({ requests: group.length, errors: group.filter(r => r.outcome !== 'passed').length,
    p50Ms: percentile(group.map(r => r.elapsedMs), .5), p95Ms: percentile(group.map(r => r.elapsedMs), .95), p99Ms: percentile(group.map(r => r.elapsedMs), .99) });
  const summary = count(rows);
  return { ...summary, errorRate: rows.length ? summary.errors / rows.length : 0,
    byAction: Object.fromEntries([...new Set(rows.map(r => r.action))].map(action => [action, count(rows.filter(r => r.action === action))])) };
}
const userId = n => `audit_user_${String(n).padStart(5, '0')}`;
export async function run(m, { sessionFactory = () => new Session(m.baseUrl), request = api } = {}) {
  const beganRun = performance.now();
  const { clients, cycles } = settings(m), rows = [], checks = [], marker = `audit-mixed-${Date.now()}`;
  let stop = false;
  const call = async (s, action, params = {}, options = {}) => {
    const began = performance.now(); let outcome = 'passed';
    try { return await request(s, action, params, options); }
    catch (e) { outcome = 'failed'; throw e; }
    finally { rows.push({ user: s.auditUser, action, method: options.method ?? 'GET', elapsedMs: performance.now() - began, outcome }); }
  };
  const post = (s, action, params = {}, options = {}) => call(s, action, params, { method: 'POST', ...options });
  const check = async (name, work) => {
    try { await work(); checks.push({ name, outcome: 'passed' }); }
    catch (e) { stop = true; checks.push({ name, outcome: 'failed', error: e.message }); }
  };
  const login = async n => {
    const s = sessionFactory(); s.auditUser = userId(n);
    const began = performance.now();
    try { await s.login(`user${n}@capacity.invalid`, m.fixturePassword, s.auditUser); rows.push({ user: s.auditUser, action: 'login', method: 'POST', elapsedMs: performance.now() - began, outcome: 'passed' }); }
    catch (e) { rows.push({ user: s.auditUser, action: 'login', method: 'POST', elapsedMs: performance.now() - began, outcome: 'failed' }); throw e; }
    return s;
  };
  const players = [];
  let owner;
  await check('synthetic identities and fixture court', async () => {
    owner = await login(99);
    for (let i = 0; i < clients; i++) players.push(await login(9101 + i));
    const detail = await call(players[0], 'facility_detail', { id: 99 });
    assert(detail.courts.some(c => c.id === 'audit_court_393'), 'expected owned synthetic Court 1 missing');
  });
  // Each player has a distinct future date; no workload shares an exclusive slot.
  const params = (i, cycle) => ({ facility_id: 99, court_id: 'audit_court_393', court_name: 'Court 1',
    date: new Date(Date.now() + (14 + i * 4 + cycle) * 86400000).toISOString().slice(0, 10),
    duration: 1, time: '9:00 AM - 10:00 AM', payment_method: 'Pickle Credits', price: 1 });
  const wallet = s => call(s, 'wallet');
  const bookings = async s => (await call(s, 'bookings')).bookings;
  const cancel = async (s, b) => {
    if ((await bookings(s)).find(x => x.id === b.id)?.status !== 'cancelled') await post(s, 'cancel_booking', { booking_id: b.id });
    assert.equal((await bookings(s)).find(x => x.id === b.id)?.status, 'cancelled');
  };
  const bookingJourney = async (s, p, approve = false) => {
    const before = await wallet(s); let booking;
    try {
      const quote = (await call(s, 'quote_booking', p)).quote;
      const created = await post(s, 'book_court', p); booking = created.booking; assert(booking?.id);
      assert.equal(cents(booking.price), cents(quote.total));
      assert.equal(cents((await wallet(s)).balance), cents(before.balance) - cents(quote.total));
      if (approve) {
        await post(owner, 'approve_booking', { booking_id: booking.id });
        assert.equal((await bookings(s)).find(x => x.id === booking.id)?.status, 'confirmed');
      }
      await cancel(s, booking);
      const after = await wallet(s); assert.equal(cents(after.balance), cents(before.balance));
      assert.equal(after.transactions.filter(t => t.booking_id === booking.id && t.type === 'debit').length, 1);
      assert.equal(after.transactions.filter(t => t.booking_id === booking.id && t.type === 'credit').length, 1);
      await post(s, 'cancel_booking', { booking_id: booking.id }, { success: false });
      assert.equal(cents((await wallet(s)).balance), cents(before.balance));
    } finally { if (booking) await cancel(s, booking); }
  };
  if (!stop) await Promise.all(players.map((s, i) => check(`player ${i + 1} mixed cycles`, async () => {
    for (let cycle = 0; cycle < cycles && !stop; cycle++) {
      await bookingJourney(s, params(i, cycle));
      const receiver = players[(i + 1) % players.length];
      const message = (await post(s, 'send_message', { partner_id: receiver.auditUser, content: `${marker}:${i}:${cycle}` })).message;
      assert(message?.id);
      assert((await call(receiver, 'messages', { partner_id: s.auditUser })).messages.some(x => x.id === message.id));
      assert.equal(Number((await call(s, 'messages', { partner_id: receiver.auditUser })).messages.find(x => x.id === message.id)?.is_read), 1);
      await post(s, 'mark_notifications_read');
      const notifications = (await call(s, 'me')).notifications;
      assert(notifications.length > 0); assert(notifications.every(x => Number(x.is_read) === 1));
      assert.equal(Number((await call(s, 'sync')).unread_notifications), 0);
    }
  })));
  if (!stop) await check('serialized owner approval and refund', () => bookingJourney(players[0], params(0, 3), true));
  if (!stop) await check('serialized Open Play pending seat/cancel/wallet', async () => {
    const s = players[0], matchId = 'audit_match_99';
    const match = async () => (await call(s, 'matches')).matches.find(x => x.id === matchId);
    const original = await match(); assert(original, 'synthetic Open Play missing');
    const before = await wallet(s); let booking;
    try {
      booking = (await post(s, 'join_match', { match_id: matchId, payment_method: 'Pickle Credits' })).booking; assert(booking?.id);
      assert.equal((await bookings(s)).find(x => x.id === booking.id)?.status, 'pending');
      assert.equal(cents((await wallet(s)).balance), cents(before.balance) - cents(booking.price));
      assert.equal(Number((await match()).current_players), Number(original.current_players));
      await cancel(s, booking);
      assert.equal(cents((await wallet(s)).balance), cents(before.balance));
      assert.equal(Number((await match()).current_players), Number(original.current_players));
    } finally { if (booking) await cancel(s, booking); }
  });
  return { marker, elapsedMs: performance.now() - beganRun, sessionCount: players.length + (owner ? 1 : 0), clients, cycles, checks, rows, metrics: summarize(rows),
    failures: checks.filter(c => c.outcome === 'failed').length,
    limitations: ['Local synthetic rehearsal only; no production capacity certification or external providers',
      'Concurrent player cycles then serialized owner/Open Play; no contested-slot race test',
      'Open Play Everyday join can fail after local end time; failure is reported, never skipped',
      'Messages, notifications and cancelled bookings remain in disposable fixture; reset before rerun',
      'Lost create response may leave a pending booking; inspect failed run and reset isolated fixture before retry'] };
}
function sqlInvariants(file) {
  const output = execFileSync(process.env.PHP_BINARY || 'php', [fileURLToPath(new URL('./invariants.php', import.meta.url)), file], { encoding: 'utf8', timeout: 60000, windowsHide: true });
  const result = JSON.parse(output); assert.equal(result.passed, true, 'SQL invariants must pass'); return result;
}
if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  let m;
  try {
    m = manifest(process.argv[2], process.argv[3]); settings(m);
    const before = sqlInvariants(process.argv[2]);
    const report = await run(m); report.beforeInvariants = before;
    try { report.afterInvariants = sqlInvariants(process.argv[2]); }
    catch { report.failures++; report.afterInvariants = { passed: false, error: 'SQL invariant check failed; inspect isolated database' }; }
    fs.writeFileSync(path.join(m.auditRoot, `mixed-${report.marker}.json`), JSON.stringify(report, null, 2), { flag: 'wx' });
    console.log(JSON.stringify(report, null, 2)); process.exitCode = report.failures ? 1 : 0;
  } catch { console.error('Mixed audit failed before completion; inspect isolated manifest/server/invariants. No credentials printed.'); process.exitCode = 1; }
}

