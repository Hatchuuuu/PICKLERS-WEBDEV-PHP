# Setup, upgrade and rollback

## Requirements
PHP **8.2+** (CLI and Apache module must match; XAMPP ships 8.2.12) with ctype, fileinfo, iconv, json, mbstring, pdo_mysql; Composer 2; MySQL/MariaDB (tested on MariaDB 10.4.32); Apache with `mod_rewrite` and `AllowOverride All`. Symfony **7.4 LTS** (7.4.20 locked). Symfony 8 was not used because it requires PHP 8.4.

## Fresh install (development)
```bash
composer install                      # installs exactly what composer.lock pins
cp .env.example .env                  # then set DB_* (and APP_SECRET for production)
php -S 127.0.0.1:8000 -t public       # or browse the XAMPP URL; the first request creates the legacy schema
php bin/console doctrine:migrations:migrate -n
php bin/console picklers:admin:privilege list
php bin/console picklers:admin:privilege grant you@example.com --reason="Initial privileged administrator"
```
The legacy bootstrap (`Picklers\Core\Database`) creates/evolves the 23 legacy tables on first connection; Doctrine migrations add the admin tables and columns on top. Run the bootstrap (any request, or `bin/console doctrine:migrations:status`) before migrating — migration 1 aborts if legacy tables are missing.

Two supported layouts, both verified:
- **XAMPP sub-directory** (`http://localhost/PICKLERS%20WEBDEV%20PROJECT/…`): root `.htaccess` rewrites into `public/`.
- **`public/` as document root**: point the virtual host at `public/`.

Private application documents live outside the web root in `storage/permits` (override with `PRIVATE_DOCUMENT_DIR`).

## Upgrading an existing installation (e.g. the current `picklers_db`)
This has **not** been run against the live database — it is a deployment step for the owner of that database.

1. Back up: `C:\xampp\mysql\bin\mysqldump -u root --single-transaction --routines --triggers picklers_db > picklers_db-YYYYMMDD.sql`
2. Rehearse on a copy (reads the live DB only): `php scripts/rehearse-upgrade.php` — must end with `OK — upgrade, rollback and restore rehearsed; source untouched`.
3. `composer install` (if `vendor/` is absent).
4. `php bin/console doctrine:migrations:migrate -n` (from the project root; uses `.env`).
5. Grant the first privileged administrator with the CLI (above). Existing administrators keep ordinary admin rights and are **not** promoted.
6. Sign in and open `/admin` → System & Audit should show "Schema migrations: Up to date".

What the migrations change (all additive; ids and history are preserved):
| Migration | Adds |
|---|---|
| 20261005000001 | Nothing — asserts the legacy baseline exists |
| 20261005000002 | `audit_events` (+ triggers blocking UPDATE/DELETE), `admin_privileges` |
| 20261005000003 | Review columns on `owner_applications`; `operating_status`/reason on `facilities`; reason/actor on `courts` and `bookings`; idempotency key, actor, kind, booking link, reason, balance-after on `wallet_transactions`; archive columns on `promo_codes`; unique `(promo_id, booking_id)` on `promo_redemptions` (skipped with a warning if historical duplicates exist) |
| 20261005000004 | `moderation_cases`, `moderation_case_events`, hidden columns on `feed_posts`/`feed_comments`, `notification_broadcasts`, `notifications.broadcast_id`, `admin_activity_seen` |
| 20261005000005 | `users.password_changed_at` |

Existing rows keep their behaviour: all facilities stay `active`, no wallet row is reclassified (old rows show "derived from label"), nothing is hidden.

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
```bash
php scripts/verify.php                 # every gate (syntax, composer, containers, Twig, routes, prod warmup, both suites)
php tests/run.php --fresh              # legacy suite on a rebuilt picklers_test (+ migrations)
php vendor/bin/phpunit                 # Symfony admin suite on a rebuilt picklers_admin_test
php scripts/verify-schema-bootstrap.php
php scripts/rehearse-upgrade.php       # reads picklers_db, writes only a disposable copy
```
Test databases are dropped and recreated by the runners; the runners refuse non-test database names.

## Browser fixtures (never the live database)
```bash
php bin/console --database=picklers_e2e --data-path=database/.e2e-state doctrine:migrations:migrate -n
php scripts/seed-fixtures.php picklers_e2e          # refuses anything not named *_e2e / *_test
php bin/console --database=picklers_e2e --data-path=database/.e2e-state picklers:admin:privilege grant root@fixture.test --reason="E2E fixtures"
php -S 127.0.0.1:8099 -t public scripts/e2e-router.php
```
Fixture accounts and their test password are defined in `scripts/seed-fixtures.php` (`FIXTURE_PASSWORD`). The router also serves the XAMPP-style sub-directory URL (`/PICKLERS%20WEBDEV%20PROJECT/…`).
