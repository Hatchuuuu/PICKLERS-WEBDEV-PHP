# Progress & decision log

Checkpoint: 6 Oct 2026. R01–R15 implemented and evidenced (see acceptance-matrix.md). Nothing has been committed — all changes are in the working tree for review.

## Needs your decision
1. **Discover filter (pre-existing uncommitted edit).** `src/Core/Database.php::getFacilitiesUncached()` contained, before this work, an uncommitted change that hides facilities without an image (`f.image IS NOT NULL AND f.image != ''`). A newly approved facility has no image, so it is invisible to players until the owner uploads one, and `OwnerProvisioningTest` fails on that. I left your edit as is. Keep it (then update that test's expectation) or drop the image condition.
2. **Live database migration.** Run the upgrade steps in setup-and-rollback.md when ready. Until then the console on the XAMPP site shows "database schema is out of date" to signed-in admins (verified on an un-migrated copy); player/owner pages are unaffected.
3. **First privileged administrator.** Choose who gets it: `php bin/console picklers:admin:privilege grant <email> --reason="…"`. The only admin in `picklers_db` today has `role=player, is_admin=1, is_owner=1`; the console labels it truthfully as "Admin · Owner".
4. **Legacy views backup.** `views/` was deleted when every page moved to Twig; a copy (with your uncommitted view edits as `uncommitted-view-edits.patch`) is in `var/legacy-views-backup/`. Delete it when you are satisfied.

## Decision log
| # | Decision | Reason |
|---|---|---|
| D1 | Symfony 7.4 LTS, PHP floor raised to 8.2 | Current LTS; Symfony 8 needs PHP 8.4; CLI and Apache both run 8.2.12. `config.platform.php=8.2.12` pins resolution. |
| D2 | ~~Kernel runs only admin-owned requests~~ → superseded: the kernel serves every request; `Admin\Http\AdminArea` picks the admin firewall | The bridge was the incremental step; see docs/symfony-migration/sections.md. |
| D3 | Symfony config in `config/symfony/` (PHP format, no YAML dependency) | Avoids `MicroKernelTrait` importing the legacy `config/routes.php`. |
| D4 | One Symfony session (`framework.session`, native storage); stateless firewalls + custom authenticators | One session lifecycle for every area; fresh role/permission state each request. |
| D5 | Doctrine DBAL owns the MySQL connection; `Core\Database` runs on it inside the kernel (`LegacyPdoDriver` only for CLI scripts outside the kernel) | Mutation + shared rule + audit row commit in one transaction; no cross-connection row-lock deadlocks; strict SQL mode kept. |
| D6 | No ORM entities | The brief asks not to force an entity rewrite; repositories use DBAL. |
| D7 | Shared rules live in `src/Domain/` (`Database` delegates) and are reused by owner, player and admin paths: `confirmBookingRequest`, `cancelBookingAsStaff`, `creditWalletOnce`, `bookingBlockReason`, conditional promo redemption | No duplicated financial/booking logic; owner approve/decline now use the same code as the console. |
| D8 | Legacy transactions made composable only where admin composes them (`beginOwnTransaction`) | Minimal change to the 7,400-line legacy class. |
| D9 | The app requires MySQL; the JSON fallback store was removed | Never write financial data to the fallback store. |
| D10 | Storage convention kept: naive Asia/Manila wall-clock DATETIMEs; timezone now set in the shared bootstrap | Installing Composer would otherwise have switched PHP to php.ini's Europe/Berlin and shifted every new timestamp by 6 hours. |
| D11 | Money as integer centavos in admin code; DECIMAL strings in SQL | Exact amounts (tests prove 0.10 + 0.20 = 0.30). |
| D12 | Two-tier permissions (admin / privileged admin) via `Capability` + voter; privileged granted only by CLI | Smallest coherent model; no automatic promotion. |
| D13 | Deactivation no longer overwrites email; legacy action name `admin_delete_user` keeps meaning "deactivate" | Reactivation must restore the account; contract compatibility. |
| D14 | Permanent deletion only for accounts without booking/financial history | Retain transaction and history integrity. |
| D15 | Moderation entry path = admin flagging | No user reporting feature exists to reuse; schema supports `user_report` later. |
| D16 | Booking "upcoming" not settable; completed is final; admin cancellation refunds Pickle Credits in full once, never external payments | Valid transitions; truthful money handling. |
| D17 | Broadcasts require a previewed recipient count; individual notices and broadcasts deduplicated by key | Proportional confirmation; duplicate-send protection. |
| D18 | Visual identity retained; refinements limited to typography weights, table density, truthful labels, pinned row actions, one contextual search | Brief: refine, don't redesign. |
| D19 | Rejecting an application only clears a *pending* verification flag | The old rule removed earned player verification. |

## Defects found along the way (fixed)
Schema stamp trusted without tables (A01); stale seed flag; seed users skipped; attribute catalog never seeded; timezone dependent on the fallback autoloader; promo `usage_limit` race; refunds possible twice across player/owner paths; owner-approval capacity logic duplicated in a controller; `switch_user` impersonation bypass on `/api`; legacy `requireAdmin` accepting `role=admin` without `is_admin`; pending bookings shown as "Upcoming"; expired/exhausted promos shown "ACTIVE"; promo expiry at start of day; user menu clipped by tables; table actions unreachable on phones; refused impersonated actions not audited (found in browser testing).

## Changed files
- Modified: `.gitignore`, `.env.example`, `composer.json`, `config/app.php`, `public/index.php`, `src/Core/Database.php`, `src/Services/AuthService.php`, `src/Services/PricingService.php`, `tests/run.php`, `scripts/maintenance.php`.
- Deleted (whole-project migration): `config/routes.php`, `src/Controllers/**`, `src/Core/{Router,Request,Response,Autoloader}.php`, `src/Middleware/**` (rate limiter moved to `src/Web/Security/RateLimiter.php`), `src/Models/**`, `src/Helpers/{Url,Security}.php`, `views/**`.
- Added: `composer.lock`, `bin/console`, `config/bootstrap.php`, `config/symfony/**`, `src/Kernel.php`, `src/Admin/**`, `src/Migrations/**`, `src/Exceptions/PromoUnavailableException.php`, `templates/admin/**`, `templates/web/**`, `templates/bundles/**`, `src/Domain/**`, `src/Web/**`, `tests/Web/**`, `public/assets/css/admin.css`, `public/assets/js/admin.js`, `phpunit.xml.dist`, `tests/Admin/**`, `scripts/{e2e-router,seed-fixtures,verify,verify-schema-bootstrap,rehearse-upgrade}.php`, `docs/admin-migration/**`, `.claude/launch.json`.
- Your pre-existing uncommitted edits in `app.css`, `style.css`, `home.php`, `admin.php` and the Discover query in `Database.php` were preserved.

## Status by ID
Done: R01–R15, A01–A15 (A01 at 253/254 because of decision 1).
Open: decisions 1–4 above; live-database migration; formal accessibility audit (not performed).

## Next action if resuming
Apply decision 1, re-run `php scripts/verify.php` (expect all gates green), then follow setup-and-rollback.md → "Upgrading an existing installation".
