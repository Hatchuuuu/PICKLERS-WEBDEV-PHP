# Local professor presentation

This release is for local testing and presentation. Use synthetic accounts. Real payments, email/SMS recovery and public hosting are not configured.

## Start a separate synthetic demo

From PowerShell in the project directory, with the existing local MySQL service running:

```powershell
php bin/console picklers:schema:install --env=test --database=picklers_e2e --data-path=database/.e2e-state
php bin/console doctrine:migrations:migrate -n --env=test --database=picklers_e2e --data-path=database/.e2e-state
php scripts/seed-fixtures.php picklers_e2e
$env:APP_ENV = 'development'
$env:APP_DEBUG = '0'
php bin/console cache:clear --env=dev
$env:PAYMENTS_SIMULATED = 'true'
php -S 127.0.0.1:8098 -t public scripts/e2e-router.php
```

The fixture seeder recreates its fixture rows and removes generated records owned by fixture accounts/facilities, including old refund keys, reservations, staff assignments, messages and moderation state. This makes repeated presentations consistent; save any rehearsal evidence before reseeding. Unrelated records and append-only audit history are retained. Do not use fixture accounts for personal data. The router selects `picklers_e2e`, separate schema-stamp state and private fixture documents. It does not replace the normal XAMPP site. The schema and seeder use the configured database host: keep that configured host on your local machine.

If port 8098 is already serving this session's demo, reuse it; do not launch another copy. Open `http://127.0.0.1:8098/`. Also test `http://127.0.0.1:8098/PICKLERS%20WEBDEV%20PROJECT/` for the XAMPP subdirectory shape.

| Role | Synthetic email | Synthetic password |
|---|---|---|
| Player | p1@fixture.test | Fixture-Pass-2026 |
| Venue owner | owner1@fixture.test | Fixture-Pass-2026 |
| Administrator | admin@fixture.test | Fixture-Pass-2026 |
| Privileged administrator, only for authorized fixture demonstrations | root@fixture.test | Fixture-Pass-2026 |

These are public test fixture values, not real account credentials. Use separate browser profiles/sessions when presenting different roles. Staff access requires an owner to link a synthetic registered user using Staff Management; a staff name/email alone does not grant access.

## Suggested presentation sequence

1. Guest discovery: open a venue and inspect courts, operating hours and date/time availability.
2. Sign in as the player. Show the wallet's existing credits and clearly labelled simulation. A simulated top-up never contacts GCash, Maya or a bank.
3. Reserve a future available slot within venue hours. Review the server total, method and pending approval status.
4. In a separate owner session, find the request and approve it. Refresh the player view to show persisted confirmation.
5. Open Cancel for the future confirmed booking; review the refund explanation. Cancel only a disposable demonstration reservation. Pending credits and confirmed credits have different existing refund eligibility.
6. Demonstrate an owner decline for each relevant payment type. The interface must describe the actual credits refund or external follow-up, rather than claiming every decline refunds a wallet.
7. Demonstrate Open Play joining/cancelling within the occurrence's capacity and venue hours.
8. Show owner/staff separation: staff can use their assigned portal operations but acceptance/decline controls remain owner/admin only.
9. Submit an identity verification request from an unverified synthetic player. The administrator can filter Pending Review and make the existing authorized decision.
10. Show the local recovery explanation. No email/SMS or support recovery promise is made. For an authenticated player, the existing Change Password flow remains available.

Test the sequence before presenting: seed dates are relative to setup time, and current court occupancy and Open Play session times affect which actions are valid. A quote alone never holds a court. Verify a timed-out mutation from persisted bookings/wallet state before retrying.

Start and warm the local app before class; the first PHP/container request can be slow on Windows. Owner booking acceptance now opens a review dialog; select Keep Pending to dismiss or Accept Booking to submit. Once submitted, approval/decline/player cancellation dialogs stay locked until the result returns. Scanner chimes use a real browser-local preference; SMS alerts and automatic funds transfers remain unavailable.

## Checks and evidence

```powershell
php scripts/verify.php
node --test scripts/audit/flows.test.mjs scripts/audit/load.test.mjs scripts/audit/mixed.test.mjs
php scripts/audit/ledger-check.test.php
php scripts/audit/facility-hours.test.php
```

The verification runner rebuilds named disposable test databases; do not point these tests at personal data. The separate mixed HTTP runner requires its explicit isolated manifest and synthetic capacity fixture. Its short local measurements are a rehearsal, not public-service or sustained-load certification.

Run one complete verification suite at a time. The admin suite now holds a MySQL advisory lock before clearing its shared cache or rebuilding `picklers_admin_test`; a competing suite exits with status75 and asks you to retry after the active run finishes. Its intentional child concurrency workers reuse the parent database. A rejected competing run is not a passing test result.

## Stop and recover

Stop a foreground PHP demo with Ctrl+C. Remove the temporary environment values in that PowerShell session when finished:

```powershell
Remove-Item Env:APP_ENV -ErrorAction SilentlyContinue
Remove-Item Env:APP_DEBUG -ErrorAction SilentlyContinue
Remove-Item Env:PAYMENTS_SIMULATED -ErrorAction SilentlyContinue
```

This execution used a temporary isolated Apache audit listener on port 8089 and GET-only before-view helper on 8097; both were stopped after verification. Their configuration/PID/evidence remain under `var/essential-20261021/`. Apache service shutdown is unavailable without service permission on this machine, so cleanup checked each process command line for the exact audit configuration before stopping only those processes. Do not stop all Apache/MySQL processes or reset normal databases.

Application rollback restores only this execution's reviewed file edits against the per-file copies under `var/essential-20261021/*-before/`, preserving prior migration work and later unrelated edits. Do not restore an entire old file over subsequent edits without comparing it. No new schema migration or real payment is required by the selected local changes.

Final verification and review results belong in `verification-and-release.md`; this runbook does not claim those gates have already passed.
