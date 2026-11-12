# F08 — Whole-flow HTTP harness preparation

Status: prepared, not executed against an application server. No application source, database, fixture, account, external delivery or payment state was changed by this preparation.

`scripts/audit/flows.mjs` reuses `Session` from `load.mjs`: real HTTP, cookie jar, form sign-in, CSRF parsed from HTML and actual `/api.php?action=me` identity verification. There is no injected session or direct SQL. All authentication must succeed with manifest credentials before any journey begins.

The manifest must physically reside under the project's `var` and its audit root. The runner requires confirmed isolation, disabled external services, the `picklers_capacity_test` database, loopback database host/port, loopback HTTP, an explicitly supplied matching configured origin and existing contained state/private/session directories. These checks do not independently prove the live server's database binding: the operator must validate Apache/environment and SQL isolation before running it.

Run after the separate fixture/database preparation and isolation verification:

```
node --test scripts/audit/flows.test.mjs
node scripts/audit/flows.mjs var/whole-flow-20261012/capacity/manifest.json http://127.0.0.1:8089
```

The runner writes an exclusive `flows-audit-flow-<timestamp>.json` inside the audit root. Each check records passed or failed, elapsed time and assertion/error detail. Exit status is nonzero on any failure. A request returning 200 is insufficient: persisted state, identity, ownership, ledger amounts/counts and terminal state are asserted. A setup failure produces a failed dependent check, not a fabricated pass. Fatal setup/authentication errors stop execution.

| Existing journey | Assertions prepared |
| --- | --- |
| Guest/auth negatives | Guest identity null, wrong password remains unauthenticated, booking refused, forged CSRF refused, unknown action and empty post rejected |
| Favorites | Toggle differs from baseline, persists through read and another real login, finally restores baseline |
| Owned court CRUD | Creates Court 999 at synthetic venue 99, persists edited rate, foreign owner cannot edit, own deletion disappears from detail |
| Cash and wallet booking | Server quote wins over client price; balance and per-booking debit count exact; repeated occupied-slot submission fails without another debit; foreign approval denied; own approval persists confirmed; cancellation persists cancelled; full eligible refund restores exact balance with one credit; repeated cancellation creates no additional transaction |
| Court future-booking guard | Confirmed future booking blocks court rename/delete and court name stays unchanged |
| Open Play | Existing Everyday match 99 join creates pending booking visible in owner requests, exact wallet charge, duplicate join refused, approved participant count stays unchanged until owner acceptance, pending cancellation restores wallet/count; finally asserts cancelled status and restored wallet/count even after a failure |
| Tournament | Owner creates Singles event, registers two entrants, incompatible Doubles category patch rejected without state change, foreign deletion denied, draws bracket, records final, completion/champion persists, finally deletes and verifies absent |
| Community | Own synthetic post persists, like/unlike persists, peer comment persists, sent message appears for recipient, opening conversation persists read status visible to sender |
| Account profile | Name/email/phone edit persists; finally restores original name/email/phone/level |
| Password | Caller remains authenticated, two other sessions revoked, new password logs in, finally restores fixture password and verifies login |
| Notifications | Existing notifications remain present, all become read, sync unread count becomes zero |
| Administrator | Ordinary stats read succeeds; unprivileged zero-amount wallet action denied with unchanged target wallet/transaction count; no privileges granted |

Actors: players 9001–9003, owner 99, foreign owner 98 and ordinary administrator 101. This avoids mutating player IDs 102–601 used by the first 500 load sessions. Owner fixtures are synthetic. No actual gateway charge, email send, upload or original data is touched by the harness. Court number 999 deliberately fails if already present; it never adopts or deletes a conflicting preexisting court.

Preparation verification: four Node tests passed, zero failed. These exercise fake HTTP form encoding/CSRF/status/semantic checks, JSON/content-type rejection, containment and cent arithmetic. No live HTTP or database verification occurred here. Actual results must be appended from the timestamped report; preparation is not F08 acceptance or 10,000-user certification.

Root executed `flows-audit-flow-1791820432312.json`: 12 checks passed and the Open Play check failed at `0 !== 1`. Read-only source investigation establishes an incorrect harness expectation: `OpenPlayRepository::joinMatch` creates a pending request and deliberately leaves `current_players` unchanged until owner acceptance. `MatchRepository::countActiveMatchBookings` counts pending requests independently for capacity. The runner now asserts pending status, visibility in the owner requests queue and unchanged confirmed-player count. This is a harness repair, with no product code change. In the first run, the `finally` cancellation returned semantic success (a cancellation error would have replaced the count error), but that original report did not record independent wallet/count verification after cleanup. The repaired runner explicitly asserts all three cleanup postconditions; rerun evidence is still required.

Limits: no browser clicks/rendering, registration/signup, owner application and private-document lifecycle, staff/payout/settings, full admin mutation/moderation/export workflows, tournament Doubles/Mixed/mix-pool, concurrency/race/load behavior or independent SQL reconciliation. Open Play uses the fixture's Everyday 18:00–20:00 match; after its local end, a valid business refusal is reported as failed and requires a separate safely dated fixture to exercise successful joining. Profile restores only fields edited by this harness; existing empty optional fields are preserved.

Community posts/comments/messages, cancelled booking history, notifications and their read state intentionally remain in the disposable fixture because no authorized public deletion/restore API exists for these. Cleanup uses owned resources only; failures may leave an owned court, booking or account changes if transport/restoration itself fails. Inspect failed checks and fixture before another run. The runner has no automatic SQL cleanup, reseeding, capability grants or application repair.
