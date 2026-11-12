import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { Session, csrf } from './load.mjs';

const projectVar = fileURLToPath(new URL('../../var/', import.meta.url));
export function inside(base, target) {
  const relative = path.relative(base, target);
  return relative !== '' && relative !== '..' && !relative.startsWith(`..${path.sep}`) && !path.isAbsolute(relative);
}
export function manifest(file, configuredOrigin) {
  const actual = fs.realpathSync(file), allowed = fs.realpathSync(projectVar);
  assert(inside(allowed, actual), 'manifest outside project var');
  const m = JSON.parse(fs.readFileSync(actual, 'utf8'));
  const url = new URL(m.baseUrl);
  assert.equal(url.protocol, 'http:'); assert.equal(url.hostname, '127.0.0.1');
  assert.equal(url.origin, new URL(configuredOrigin).origin, 'configured origin mismatch');
  assert.equal(url.username, ''); assert.equal(url.password, ''); assert.equal(url.search, ''); assert.equal(url.hash, '');
  assert.equal(m.rootConfirmed, true); assert.equal(m.externalServicesDisabled, true);
  assert.equal(m.db?.name, 'picklers_capacity_test'); assert.equal(m.db?.host, '127.0.0.1'); assert.equal(m.db?.port, 3306);
  assert.equal(typeof m.fixturePassword, 'string'); assert(m.fixturePassword.length >= 12);
  const root = fs.realpathSync(m.auditRoot); assert(inside(allowed, root)); assert(inside(root, actual));
  for (const key of ['statePath', 'privatePath', 'sessionPath']) assert(inside(root, fs.realpathSync(m[key])), key);
  m.baseUrl = url.href.endsWith('/') ? url.href : `${url.href}/`;
  return m;
}
export function decode(response) {
  assert.match(response.headers['content-type'] ?? '', /application\/json/i, 'JSON content type');
  const value = JSON.parse(response.body);
  assert.equal(typeof value?.success, 'boolean', 'JSON contract missing success');
  return { ...value, httpStatus: response.status };
}
export async function api(session, action, params = {}, { method = 'GET', endpoint = 'api.php', token = session.token, expected = 200, success = true } = {}) {
  const query = new URLSearchParams({ action, ...(method === 'GET' ? params : {}) });
  const response = await session.request(`${endpoint}?${query}`, {
    method, headers: { 'X-CSRF-Token': token ?? '', 'X-Requested-With': 'XMLHttpRequest', ...(method === 'POST' ? { 'Content-Type': 'application/x-www-form-urlencoded' } : {}) },
    ...(method === 'POST' ? { body: new URLSearchParams({ action, ...params }).toString() } : {}),
  });
  const data = decode(response);
  assert.equal(response.status, expected, `${action}: HTTP status`);
  assert.equal(data.success, success, `${action}: ${data.message ?? 'semantic failure'}`);
  return data;
}
export function cents(value) {
  const n = Number(value); assert(Number.isFinite(n), 'invalid money');
  const result = Math.round(n * 100); assert(Math.abs(n * 100 - result) < 0.000001, 'fractional cent'); return result;
}
const userId = n => `audit_user_${String(n).padStart(5, '0')}`;
export async function run(m) {
  const rows = [], sessions = new Map(), marker = `audit-flow-${Date.now()}`;
  const login = async n => {
    const session = new Session(m.baseUrl); await session.login(`user${n}@capacity.invalid`, m.fixturePassword, userId(n));
    sessions.set(n, session); return session;
  };
  const check = async (name, work) => {
    const started = Date.now();
    try { await work(); rows.push({ name, outcome: 'passed', elapsedMs: Date.now() - started }); }
    catch (error) { rows.push({ name, outcome: 'failed', error: error.message, elapsedMs: Date.now() - started }); }
  };
  const post = (s, action, params, options = {}) => api(s, action, params, { method: 'POST', ...options });
  const p = await login(9001), peer = await login(9002), owner = await login(99), foreign = await login(98), admin = await login(101);
  const detail = () => api(p, 'facility_detail', { id: 99 });
  const wallet = () => api(p, 'wallet');
  const bookings = async () => (await api(p, 'bookings')).bookings;
  await check('guest and forged-CSRF boundaries', async () => {
    const guest = new Session(m.baseUrl); const me = await api(guest, 'me'); assert.equal(me.user, null);
    const page = await guest.request('auth'); guest.token = csrf(page.body);
    await guest.request('auth', { method: 'POST', body: new URLSearchParams({ auth_action: 'signin', identifier: 'user9001@capacity.invalid', password: 'deliberately-wrong-audit-password', csrf_token: guest.token }).toString(), headers: { 'Content-Type': 'application/x-www-form-urlencoded' } });
    assert.equal((await api(guest, 'me')).user, null, 'invalid sign-in authenticated');
    guest.token = (await api(guest, 'me')).csrf_token;
    await post(guest, 'book_court', { facility_id: 99 }, { expected: 401, success: false });
    await post(p, 'create_post', { content: marker }, { token: 'forged', expected: 403, success: false });
    await api(p, 'no_such_action', {}, { expected: 400, success: false });
    await post(p, 'create_post', { content: ' ' }, { expected: 400, success: false });
  });
  await check('favorites persist and restore', async () => {
    const initial = (await detail()).facility.is_favorited; assert.equal(typeof initial, 'boolean');
    try {
      assert.equal((await post(p, 'toggle_favorite_facility', { facility_id: 99 })).favorited, !initial);
      assert.equal((await detail()).facility.is_favorited, !initial);
      const other = await login(9001); assert.equal((await api(other, 'facility_detail', { id: 99 })).facility.is_favorited, !initial);
    } finally {
      if ((await detail()).facility.is_favorited !== initial) await post(p, 'toggle_favorite_facility', { facility_id: 99 });
      assert.equal((await detail()).facility.is_favorited, initial);
    }
  });
  let court;
  await check('owned court create/edit and foreign ownership', async () => {
    court = (await post(owner, 'add_court', { facility_id: 99, name: 'Court 999', surface: 'Hard Court', rate: 300 }, { endpoint: 'owner.php' })).court;
    assert(court?.id); assert.equal(String(court.facility_id), '99');
    assert((await detail()).courts.some(c => c.id === court.id));
    await post(foreign, 'edit_court', { court_id: court.id, name: 'Court 998' }, { endpoint: 'owner.php', expected: 403, success: false });
    await post(owner, 'edit_court', { court_id: court.id, name: court.name, surface: 'Hard Court', rate: 320 }, { endpoint: 'owner.php' });
    assert.equal(cents((await detail()).courts.find(c => c.id === court.id)?.price), 32000);
  });
  const date = new Date(Date.now() + 7 * 86400000).toISOString().slice(0, 10);
  for (const [index, payment] of ['Pay at Venue', 'Pickle Credits'].entries()) await check(`${payment} quote/book/approve/cancel ledger`, async () => {
    assert(court, 'court setup failed');
    const before = await wallet(), start = 9 + index;
    const params = { facility_id: 99, court_id: court.id, court_name: court.name, date, duration: 1, time: `${start}:00 AM - ${start + 1}:00 AM`, payment_method: payment, price: 1 };
    const quote = (await api(p, 'quote_booking', params)).quote;
    let booking;
    try {
      const created = await post(p, 'book_court', params); booking = created.booking; assert(booking?.id);
      assert.equal(cents(created.quote.total), cents(quote.total));
      assert.equal(cents(booking.price), cents(quote.total));
      const charged = await wallet(); assert.equal(cents(charged.balance), cents(before.balance) - (payment === 'Pickle Credits' ? cents(quote.total) : 0));
      const debits = charged.transactions.filter(t => t.booking_id === booking.id && t.type === 'debit');
      assert.equal(debits.length, payment === 'Pickle Credits' ? 1 : 0);
      const repeat = decode(await p.request('api.php?action=book_court', { method: 'POST', body: new URLSearchParams(params).toString(), headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': p.token } }));
      assert.equal(repeat.success, false); assert.equal(cents((await wallet()).balance), cents(charged.balance));
      await post(foreign, 'approve_booking', { booking_id: booking.id }, { expected: 403, success: false });
      await post(owner, 'approve_booking', { booking_id: booking.id });
      assert.equal((await bookings()).find(b => b.id === booking.id)?.status, 'confirmed');
      if (index === 0) {
        await post(owner, 'delete_court', { court_id: court.id, name: court.name }, { endpoint: 'owner.php', expected: 409, success: false });
        await post(owner, 'edit_court', { court_id: court.id, name: 'Court 998', surface: 'Hard Court', rate: 320 }, { endpoint: 'owner.php', expected: 409, success: false });
        assert.equal((await detail()).courts.find(c => c.id === court.id)?.name, court.name);
      }
      await post(p, 'cancel_booking', { booking_id: booking.id });
      assert.equal((await bookings()).find(b => b.id === booking.id)?.status, 'cancelled');
      const refunded = await wallet(); assert.equal(cents(refunded.balance), cents(before.balance));
      assert.equal(refunded.transactions.filter(t => t.booking_id === booking.id && t.type === 'credit').length, payment === 'Pickle Credits' ? 1 : 0);
      await post(p, 'cancel_booking', { booking_id: booking.id }, { success: false });
      const after = await wallet(); assert.equal(cents(after.balance), cents(refunded.balance)); assert.equal(after.transactions.length, refunded.transactions.length);
    } finally {
      if (booking && (await bookings()).find(b => b.id === booking.id)?.status !== 'cancelled') await post(p, 'cancel_booking', { booking_id: booking.id });
    }
  });
  if (court) await check('owned court deletion persists', async () => {
    await post(owner, 'delete_court', { court_id: court.id, name: court.name }, { endpoint: 'owner.php' });
    assert(!(await detail()).courts.some(c => c.id === court.id));
  });
  await check('Open Play join/cancel restores count and wallet', async () => {
    const id = 'audit_match_99';
    const original = (await api(p, 'matches')).matches.find(x => x.id === id); assert(original);
    const before = await wallet(); let booking;
    try {
      booking = (await post(p, 'join_match', { match_id: id, payment_method: 'Pickle Credits' })).booking; assert(booking?.id);
      assert.equal((await bookings()).find(b => b.id === booking.id)?.status, 'pending');
      assert.equal(cents((await wallet()).balance), cents(before.balance) - cents(booking.price));
      await post(p, 'join_match', { match_id: id, payment_method: 'Pickle Credits' }, { success: false });
      // Only owner-approved seats increment current_players; pending joins reserve capacity separately.
      assert.equal(Number((await api(p, 'matches')).matches.find(x => x.id === id).current_players), Number(original.current_players));
      assert((await api(owner, 'get_pending_requests', { facility_id: 99 })).requests.some(b => b.id === booking.id));
      await post(p, 'cancel_booking', { booking_id: booking.id });
      assert.equal(Number((await api(p, 'matches')).matches.find(x => x.id === id).current_players), Number(original.current_players));
      assert.equal(cents((await wallet()).balance), cents(before.balance));
    } finally {
      if (booking) {
        if ((await bookings()).find(b => b.id === booking.id)?.status !== 'cancelled') await post(p, 'cancel_booking', { booking_id: booking.id });
        assert.equal((await bookings()).find(b => b.id === booking.id)?.status, 'cancelled', 'Open Play cleanup status');
        assert.equal(cents((await wallet()).balance), cents(before.balance), 'Open Play cleanup wallet');
        assert.equal(Number((await api(p, 'matches')).matches.find(x => x.id === id)?.current_players), Number(original.current_players), 'Open Play cleanup count');
      }
    }
  });
  await check('Singles entrants/draw/result/delete and category guard', async () => {
    let tournament;
    try {
      tournament = (await post(owner, 'create_tournament', { facility_id: 99, title: marker, category: 'Singles', max_teams: 4, date })).tournament;
      assert(tournament?.id); const params = { tournament_id: tournament.id };
      for (const name of ['Audit Ana', 'Audit Ben']) await post(owner, 'add_tournament_entrant', { ...params, name, player1: name });
      const get = async () => (await api(owner, 'get_tournament', params)).tournament;
      const before = await get(); assert.equal(before.teams.length, 2);
      await post(owner, 'update_tournament', { ...params, category: 'Doubles' }, { expected: 422, success: false });
      assert.deepEqual(await get(), before);
      await post(foreign, 'delete_tournament', params, { expected: 404, success: false });
      await post(owner, 'generate_tournament_bracket', params);
      const drawn = await get(); assert(drawn.matches.length > 0);
      const match = drawn.matches.find(x => x.team1 && x.team2); assert(match);
      await post(owner, 'report_tournament_match', { ...params, match_id: match.id, winner_team_id: match.team1, score1: 11, score2: 5 });
      const finished = await get(); assert.equal(finished.status, 'completed'); assert.equal(finished.champion_team_id, match.team1);
    } finally {
      if (tournament) { await post(owner, 'delete_tournament', { tournament_id: tournament.id }); await api(owner, 'get_tournament', { tournament_id: tournament.id }, { expected: 404, success: false }); }
    }
  });
  await check('community post/like/comment and message read persistence', async () => {
    const created = (await post(p, 'create_post', { content: marker })).post; assert(created?.id);
    const feedPost = async () => (await api(p, 'feed_posts')).posts.find(x => x.id === created.id);
    assert.equal((await feedPost()).content, marker);
    await post(p, 'like_post', { post_id: created.id }); assert.equal((await feedPost()).i_liked, true);
    await post(p, 'like_post', { post_id: created.id }); assert.equal((await feedPost()).i_liked, false);
    const comment = (await post(peer, 'add_comment', { post_id: created.id, comment: marker })).comment;
    assert((await feedPost()).comments.some(x => x.id === comment.id && x.comment === marker));
    const message = (await post(p, 'send_message', { partner_id: userId(9002), content: marker })).message;
    const thread = await api(peer, 'messages', { partner_id: userId(9001) }); assert(thread.messages.some(x => x.id === message.id));
    const sender = await api(p, 'messages', { partner_id: userId(9002) }); assert.equal(Number(sender.messages.find(x => x.id === message.id)?.is_read), 1);
  });
  await check('profile persists and restores', async () => {
    const original = (await api(p, 'me')).user;
    const fields = { name: `${original.name} Audit`, email: 'flow9001@capacity.invalid', phone: '+63 918 009 0001', level: original.level ?? '' };
    try {
      await post(p, 'update_profile', fields); const current = (await api(p, 'me')).user;
      for (const key of ['name', 'email', 'phone']) assert.equal(current[key], fields[key]);
    } finally {
      await post(p, 'update_profile', { name: original.name, email: original.email, phone: original.phone, level: original.level ?? '' });
      const restored = (await api(p, 'me')).user; for (const key of ['name', 'email', 'phone', 'level']) assert.equal(restored[key], original[key]);
    }
  });
  await check('password change revokes two old sessions and restores password', async () => {
    const a = await login(9003), b = await login(9003), c = await login(9003), password = `${m.fixturePassword}-Flow`;
    let changed = false;
    try {
      await post(a, 'change_password', { current_password: m.fixturePassword, new_password: password, confirm_password: password }); changed = true;
      assert.equal((await api(a, 'me')).user.id, userId(9003));
      assert.equal((await api(b, 'me')).user, null); assert.equal((await api(c, 'me')).user, null);
      const fresh = new Session(m.baseUrl); await fresh.login('user9003@capacity.invalid', password, userId(9003));
    } finally {
      if (changed) { const fresh = new Session(m.baseUrl); await fresh.login('user9003@capacity.invalid', password, userId(9003)); await post(fresh, 'change_password', { current_password: password, new_password: m.fixturePassword, confirm_password: m.fixturePassword }); await login(9003); }
    }
  });
  await check('notifications mark-read persists', async () => {
    const before = (await api(p, 'me')).notifications; assert(before.length > 0);
    await post(p, 'mark_notifications_read', {});
    const after = (await api(p, 'me')).notifications; assert(after.length > 0); assert(after.every(x => Number(x.is_read) === 1));
    assert.equal(Number((await api(p, 'sync')).unread_notifications), 0);
  });
  await check('admin ordinary read and privileged wallet denial', async () => {
    const stats = await api(admin, 'admin_stats', {}, { endpoint: 'admin/api' }); assert.equal(stats.success, true);
    const before = await wallet();
    await post(admin, 'admin_adjust_wallet', { user_id: userId(9001), type: 'credit', amount: '0', reason: marker }, { endpoint: 'admin/api', expected: 403, success: false });
    const after = await wallet(); assert.equal(cents(after.balance), cents(before.balance)); assert.equal(after.transactions.length, before.transactions.length);
    assert.equal((await api(admin, 'me')).user.id, userId(101));
  });
  return { marker, database: m.db.name, origin: new URL(m.baseUrl).origin, rows, failures: rows.filter(x => x.outcome === 'failed').length,
    limitations: ['HTTP/API assertions only; no browser or SQL invariants', 'No admin capabilities granted; ordinary stats read and zero-amount privileged wallet denial only', 'No owner application/upload/review, staff, payout, moderation or account deletion', 'Community artifacts, cancelled bookings and read notifications remain in disposable fixture', 'Sequential flows; no concurrency/load certification', 'Open Play Everyday join may correctly fail after local session end; report records failure'] };
}
if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  try {
    const m = manifest(process.argv[2], process.argv[3]);
    const report = await run(m); const output = path.join(m.auditRoot, `flows-${report.marker}.json`);
    fs.writeFileSync(output, JSON.stringify(report, null, 2), { flag: 'wx' });
    console.log(JSON.stringify(report, null, 2)); process.exitCode = report.failures ? 1 : 0;
  } catch (error) { console.error(`Audit flow failed: ${error.message}`); process.exitCode = 1; }
}
