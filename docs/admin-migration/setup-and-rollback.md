# Setup, upgrade and rollback

For the primary Docker workflow (no XAMPP required), see the [project README](../../README.md). The commands and historical evidence below describe native/XAMPP installations and existing-data upgrades.

## Requirements
PHP **8.2+** (CLI and Apache module must match; XAMPP ships 8.2.12) with ctype, fileinfo, iconv, json, mbstring, pdo_mysql; Composer 2; MySQL/MariaDB (tested on MariaDB 10.4.32); Apache with `mod_rewrite` and `AllowOverride All`. Symfony **7.4 LTS** (7.4.20 locked). Symfony 8 was not used because it requires PHP 8.4.

## Fresh install (development)
```bash
composer install                      # installs exactly what composer.lock pins
cp .env.example .env                  # then set DB_* (and APP_SECRET for production)
php bin/console picklers:schema:install # creates the configured database if missing and installs its base schema
php bin/console doctrine:migrations:migrate -n
php -S 127.0.0.1:8000 -t public scripts/dev-router.php # or browse the XAMPP URL
php bin/console picklers:admin:privilege list
php bin/console picklers:admin:privilege grant you@example.com --reason="Initial privileged administrator"
```
`Picklers\Persistence\SchemaInstaller` owns the existing 23 base tables and their additive upgrades. The explicit installation command uses the configured Doctrine connection, creates the database only when absent, and preserves existing rows. Development/test environments retain the existing demo seeds; `prod`, `production` and `live` skip them. Doctrine migrations add the remaining tables and columns on top; migration 1 aborts if base tables are missing. Install before serving requests; HTTP requests and migration status do not install the schema.

Two supported layouts, both verified:
- **XAMPP sub-directory** (`http://localhost/PICKLERS%20WEBDEV%20PROJECT/…`): root `.htaccess` rewrites into `public/`.
- **`public/` as document root**: point the virtual host at `public/`.

PHP's development server needs `scripts/dev-router.php` to route legacy URLs such as `/auth.php` and serve the asset aliases that Apache handles through `.htaccess`. The router uses your normal environment and database; it does not install or seed them. The separate e2e router below deliberately selects disposable fixtures.

Private application documents live outside the web root in `storage/permits` (override with `PRIVATE_DOCUMENT_DIR`).

## Upgrading an existing installation (e.g. the current `picklers_db`)
The normal local `picklers_db` was upgraded on October 13, 2026 after restored-copy rehearsals and backup verification. It now has all eight migrations. The following procedure remains applicable to other existing installations.

1. Back up: `C:\xampp\mysql\bin\mysqldump -u root --single-transaction --routines --triggers picklers_db > picklers_db-YYYYMMDD.sql`
2. Rehearse on a copy (reads the live DB only): `php scripts/rehearse-upgrade.php` — must end with `OK — upgrade, rollback and restore rehearsed; source untouched`.
3. `composer install` (if `vendor/` is absent).
4. `php bin/console cache:clear --env=prod` after deploying code, then `php bin/console picklers:schema:install` and `php bin/console doctrine:migrations:migrate -n` (from the project root; uses `.env`). A reused production container can otherwise retain commands and service definitions from the previous version.
5. Grant the first privileged administrator with the CLI (above). Existing administrators keep ordinary admin rights and are **not** promoted.
6. Sign in and open `/admin` → System & Audit should show "Schema migrations: Up to date".

What the migrations change (ids and history are preserved; optional blank phone values become NULL):
| Migration | Adds |
|---|---|
| 20261005000001 | Nothing — asserts the legacy baseline exists |
| 20261005000002 | `audit_events` (+ triggers blocking UPDATE/DELETE), `admin_privileges` |
| 20261005000003 | Review columns on `owner_applications`; `operating_status`/reason on `facilities`; reason/actor on `courts` and `bookings`; idempotency key, actor, kind, booking link, reason, balance-after on `wallet_transactions`; archive columns on `promo_codes`; unique `(promo_id, booking_id)` on `promo_redemptions` (skipped with a warning if historical duplicates exist) |
| 20261005000004 | `moderation_cases`, `moderation_case_events`, hidden columns on `feed_posts`/`feed_comments`, `notification_broadcasts`, `notifications.broadcast_id`, `admin_activity_seen` |
| 20261005000005 | `users.password_changed_at` |
| 20261009000001 | Imports `database/tournaments.json` into the MySQL `tournaments` table, preserving record order and JSON fields; refuses to replace populated tables from the earlier design |
| 20261012000001 | Unique populated phone identity; preflight refuses noncanonical contacts or collisions before changing data. Empty/whitespace-only optional phones become NULL; preserve their original values separately if exact formatting recovery is required. |
| 20261012000002 | Nonunique booking index `(match_id, status, booking_date)` for Open Play counts; no booking rows or rules change. |

Back up `database/tournaments.json` separately before upgrading. Tournament rollback writes the current database records back to that file before dropping the table, and aborts if the write fails. A custom `--data-path` selects the file directory for that process. Keep that directory writable during rollback.

Existing rows keep their behaviour: all facilities stay `active`, no wallet row is reclassified (old rows show "derived from label"), nothing is hidden.

The October 12 phone migration was rehearsed on a restored current-data copy: all 31 application tables retained their row hashes after accounting for two backed-up blank optional phones becoming NULL. On October 13 the normal local database received both October 12 migrations and passed the same preservation check, from six to eight migration versions. Phone migration rollback drops the constraint but retains NULL absence values; restore the protected original-value backup to recover their exact old formatting.

## Rollback
Code and schema roll back independently; roll back the **schema first** only if you are also reverting the code.

- Schema to the pre-admin baseline (drops only what the migrations added):
  ```bash
  php bin/console doctrine:migrations:migrate "Picklers\Migrations\Version20261005000001" -n
  ```
  If audit events exist, migration 2 refuses to drop them: export the audit trail first (System & Audit → Export audit CSV), then set `PICKLERS_ALLOW_AUDIT_DROP=1` for that command. Rolling back loses admin-only data (audit, moderation history, privileges, review notes, hidden flags — hidden content becomes visible again).
- Full restore: drop and recreate the database from the step-1 dump: `mysql -u root picklers_db < picklers_db-YYYYMMDD.sql`.
- Code: revert to the previous commit (the legacy admin controller and its routes are in git history). Legacy code tolerates the new columns being present or absent.

Both the down-migrations and the restore were rehearsed on a copy (verification.md).

## Tests and verification
The complete verification command requires Node on PATH for JavaScript syntax and frontend regression checks (verified with Node 24.16.0). No npm install is needed. Run database suites serially: they rebuild the same disposable test databases.

```bash
php scripts/verify.php                 # every gate (syntax, composer, containers, Twig, routes, prod warmup, both suites)
php tests/run.php --fresh              # legacy suite on a rebuilt picklers_test (+ migrations)
php vendor/bin/phpunit                 # Symfony admin suite on a rebuilt picklers_admin_test
php scripts/verify-schema-bootstrap.php
php scripts/rehearse-upgrade.php       # reads picklers_db, writes only a disposable copy
```
Test databases are dropped and recreated by the runners; the runners refuse non-test database names.

The upgrade rehearsal targets a legacy-schema source. It does not provide already-migrated-source expectations for privileges, facility statuses or retained tournament JSON. Use a disposable legacy-schema fixture for legacy upgrade testing; never downgrade the real application database just to satisfy a rehearsal.

### Already-migrated current-data recovery evidence (2026-10-11)

The Phase 2 repair audit used a cold copy of the actual local MySQL data directory, restored to a separate MariaDB instance on loopback port 3307. That copy already had all six migrations. A no-op migration run preserved all 32 tables / 458 rows by sorted row hashes, including account IDs, balances, ledger rows, booking statuses, staff memberships, venue states, privileges and tournaments. This establishes preservation for that captured local state, rather than a legacy-source upgrade result. The original database was not migrated or downgraded.

The immutable cold backup, restore rehearsal, per-file hashes, row-hash baseline and result are outside the web root at:
`C:\Users\Nitro\.codex\visualizations\2026\10\10\01a1273e-4d18-7be2-bad0-9d790850cdd3\picklers-phase2-recovery`.
Private documents were backed up there separately: 16 files / 21,897,101 bytes, with verified source/backup hashes. Treat this directory as private; do not commit or serve it.

For recovery, keep MySQL stopped while using a cold data-directory copy. First preserve the current directory separately, then restore the immutable copy into a separate test instance with compatible MariaDB version/configuration. Compare the saved file and row hashes and migration status before considering replacement of the normal installation. Keep the previous directory and private-document copy until the restored application is accepted. No destructive restore was performed on the original installation in this audit.

See `docs/symfony-migration/phase-2-repair-report.md` for repair, verification and remaining acceptance limits.


After the staff identity repair, old staff rows without `user_id` no longer grant access by email. The venue owner must revoke and re-grant that membership through Staff Management, selecting the registered player. Newly granted access stays attached to that account if its email changes.

The current normal database contains two active legacy staff entries without an account link; one has a matching registered email and the other does not. Their records were preserved. Email alone does not establish the owner's intended account assignment, so no access was silently granted. Owners must complete that existing onboarding step before those entries can manage courts.

### Current local startup acceptance (October 13, 2026)

The normal database and its immutable pre-upgrade copy matched all 410 saved file hashes before startup; all 16 private documents and their copies matched too. After the normal migration, every application row hash matched the expected result, apart from the two explicitly backed-up optional blank phones becoming NULL. Guest startup checks did not change application rows.

Normal local HTTP checks passed through the supported development router (14 checks) and the actual Apache XAMPP subdirectory layout (13 checks), including assets, guest authorization and private-path denial. The temporary audit and Apache test servers were stopped; normal MySQL and the loopback development server were left running at `http://127.0.0.1:8000/`.

To restart the development server from this project directory when it is stopped:
```powershell
& C:\xampp\php\php.exe -S 127.0.0.1:8000 -t public scripts/dev-router.php
```
Start normal MySQL through XAMPP first if it is not already running. Do not start a second database process on occupied port 3306. Preserve the protected pre-upgrade backup; obtain a fresh backup before any future restore after using the application.

See `docs/symfony-migration/whole-flow-repair-report.md` for the final verification and measured capacity limits. Short 500-session read stages passed; the 1,000-session same-machine test failed with Windows database socket allocation errors. This is not 10,000-concurrent-user certification.

## Browser fixtures (never the live database)
```bash
php bin/console --database=picklers_e2e --data-path=database/.e2e-state picklers:schema:install
php bin/console --database=picklers_e2e --data-path=database/.e2e-state doctrine:migrations:migrate -n
php scripts/seed-fixtures.php picklers_e2e          # refuses anything not named *_e2e / *_test
php bin/console --database=picklers_e2e --data-path=database/.e2e-state picklers:admin:privilege grant root@fixture.test --reason="E2E fixtures"
php -S 127.0.0.1:8099 -t public scripts/e2e-router.php
```
Fixture accounts and their test password are defined in `scripts/seed-fixtures.php` (`FIXTURE_PASSWORD`). The router also serves the XAMPP-style sub-directory URL (`/PICKLERS%20WEBDEV%20PROJECT/…`).

On October 13 the project owner confirmed that the venue owners will complete both legacy staff account links using existing Staff Management. No account assignment was inferred or changed during this audit.
