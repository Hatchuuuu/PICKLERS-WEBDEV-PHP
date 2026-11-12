# Symfony / Docker verification — October 21, 2026

## Delivered

The existing application uses Symfony 7.4.20 for its kernel, 48 attribute routes, dependency injection, Security, sessions, CSRF, validation, Twig and Console. Doctrine DBAL provides the shared application connection, and all eight Doctrine migrations are installed. PHP compatibility URLs remain Symfony routes. `public/index.php` is the only executable PHP front controller under `public/`.

Added a production PHP 8.2/Apache image, development target/override, MariaDB 10.11 Compose services, ordered schema installation/migrations, HTTP/database health checks, persistent volumes, an isolated test profile, GitHub Actions verification, setup documentation and HTTP acceptance checks. Production runs as `www-data` with debug/simulated payments disabled and without PHPUnit/Node or copied host secrets/data. Development supplies Node 24 and live source mounts.

Fixtures, schema verification and upgrade rehearsal now use the same environment bootstrap as the application. Inherited Docker settings take precedence over native `.env` settings. Doctrine's server version is configurable. PHP lint exclusions are project-relative, so `/var/www/html` no longer causes the entire application scan to be skipped; an empty scan fails. Failed verification gates retain their detailed diagnostics.

Fresh production installs can explicitly promote the first registered active account using `picklers:admin:privilege bootstrap ... --reason=...`. It refuses deleted accounts and installations that already have an active administrator, and records role/privilege changes in the same transaction. Existing grant/revoke protections remain.

## Measured acceptance

| Check | Result |
| --- | --- |
| Production, development and test image builds | Passed |
| Production/dev/test Compose configuration | Valid |
| Fresh production schema and migrations | 8/8; zero pending |
| Repeat setup | Preserved rows; migrations already current |
| `php scripts/verify.php` inside Docker | All gates passed |
| PHP syntax in Docker | 236 project PHP files checked |
| Composer validation/platform/audit | Passed; no vulnerability advisories reported |
| JavaScript syntax and frontend regression suite | Passed |
| Symfony container lint | Passed in dev, test and prod |
| Twig lint | 59 templates passed |
| Service suite | 387 assertions passed |
| PHPUnit | 275 tests, 2,249 assertions; no warnings/failures |
| Final strengthened bootstrap concurrency check | 3 tests, 11 assertions passed; exactly one success and one refusal |
| Apache HTTP acceptance | 21/21 in production and development |
| Container replacement | Database user count and five file-volume markers retained |
| Web process identity | UID/GID 33 (`www-data`) |
| Production kernel | `prod`, debug false, Asia/Manila |
| Development kernel | `dev`, debug true; Node v24.22.0 |
| Fresh database collation | `utf8mb4_unicode_ci`; administrator listing works |
| Review | No remaining findings after correcting legacy-document access |

HTTP checks cover public/compatibility routes, authentication redirects, private API denial, anonymous bookings returning an empty unsuccessful response, JSON CSRF rejection, assets/aliases, hidden/config/vendor/private paths, executable upload denial and legacy permit-directory denial. A real synthetic PHP upload returned 403. A real synthetic legacy permit PDF reproduced the initial Apache configuration error and returned 403 after the explicit directory-deny rule was added. Both probes were removed.

Concurrency tests retain database evidence of overlapping locking reads. MariaDB can queue its second locking read in the optimizer's `Statistics` state before exposing that transaction through `INNODB_TRX`; the test accepts the exact queued `users ... FOR UPDATE` query while the test holds that user row, in addition to structured InnoDB lock-wait records. Final application outcomes, notifications, provisioning and audit counts remain asserted.

## Data and operating boundaries

The normal XAMPP application database and private documents were not imported, replaced, migrated or used for Docker fixtures. Docker starts with separate named volumes. Native disposable test databases were used for targeted regression checks. A separate fresh acceptance Compose project exercised production followed by development demo seeding; its temporary probes were removed. The original working tree contained substantial uncommitted migration/UI work, which was preserved.

The new main Docker database was initially empty but inherited MariaDB's general collation. This was caught by the administrator-list command. Its empty acceptance schema was corrected, Compose now specifies Unicode collation before database initialization, and a second independently fresh production installation passed the same check. No existing XAMPP database collation was changed.

Graphify was refreshed after code changes (2,805 nodes / 5,335 edges at the final update). It reports 24 JSON/config/data files with no AST nodes; that is a graph coverage warning, not an application test failure.

No real payment gateway was added: production top-ups continue to fail closed. Public hosting still requires operator-managed TLS/domain configuration, backups and monitoring. Existing-data transfer and deployment-specific capacity/real-browser acceptance remain separate work. GitHub Actions configuration was added and its commands were exercised locally; a hosted Actions run was not triggered. Automated checks establish the measured behavior above, not a guarantee of zero defects.

See [README](../../README.md) for start/develop/test commands. Local verification logs are in ignored `var/docker-*.log`; credentials are in ignored `.env.docker` and are excluded from image build context.
