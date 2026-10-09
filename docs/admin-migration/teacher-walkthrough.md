# Teacher walkthrough — Symfony in the PICKLERS admin console (≈10 minutes)

Run from the project root.

## 1. It is a real Symfony application
```bash
php bin/console about
php bin/console debug:router           # 48 routes (the whole app), all from #[Route] attributes
php bin/console debug:container --tag=controller.service_arguments
php bin/console lint:container && php bin/console lint:twig templates
```
- Kernel: `src/Kernel.php` (`MicroKernelTrait`), bundles in `config/symfony/bundles.php` (Framework, Security, Twig, Doctrine, DoctrineMigrations).
- Front controller: `public/index.php` hands every request to `$kernel->handle($request)`; `Admin\Http\AdminArea` decides which ones run on the admin firewall.

## 2. Routing + controllers
- `src/Admin/Controller/ConsoleController.php` — `#[Route('/admin')]` pages, `/admin/panel/{tab}` fragments, `/admin/detail/{type}/{id}`.
- `src/Admin/Controller/ActionController.php` — the action endpoint; a table maps each legacy `admin_*` action to a capability, then delegates to an injected service. It returns `JsonResponse` objects; no business rules in the controller.
- `Web\Controller\ApiController` shows backward compatibility: `/api?action=admin_*` reuses the same dispatcher.

## 3. Services + dependency injection
`config/symfony/services.php` autowires `src/Admin/`. Examples to open:
- `Service/WalletAdjustmentService.php` — exact money, limits, idempotency, one transaction with the audit row.
- `Service/BookingAdminService.php` — uses the platform's shared booking rules (`src/Domain/BookingRules.php`), which the owner portal also calls.
- `config/symfony/packages/doctrine.php` — Doctrine DBAL owns the admin's MySQL connection.

## 4. Security
- `config/symfony/packages/security.php`: stateless firewall, custom authenticator, role hierarchy, access control.
- `Security/AdminSessionAuthenticator.php` (authenticator + entry point), `PlatformSession.php` (the Symfony session), `AdminUserProvider.php`, `RoleMapper.php`, `CapabilityVoter.php` + `Capability.php` (the permission matrix):
  ```bash
  php bin/console picklers:admin:privilege matrix
  ```
- CSRF: Symfony's built-in `framework.csrf_protection` (`CsrfTokenManagerInterface`, tokens in the session).

## 5. Twig
`templates/admin/base.html.twig` → `console.html.twig` → `panels/_*.html.twig`, `details/_*.html.twig`, macros in `components/ui.html.twig`, filters/functions in `src/Admin/Twig/AdminExtension.php` (`peso`, `status_label`, `icon`), plus SecurityBundle's `is_granted()` to show only permitted actions. Autoescaping is on; templates contain no SQL, `$_GET` or `$_SESSION`.

## 6. Doctrine migrations
```bash
php bin/console doctrine:migrations:list
```
Additive, reversible migrations in `src/Migrations/`; rollback and restore rehearsed by `php scripts/rehearse-upgrade.php`.

## 7. Proof it works
```bash
php vendor/bin/phpunit                 # 111 functional/integration tests incl. multi-process concurrency
php scripts/verify.php                 # all gates
```
Live demo (fixture data, never the real database): start `php -S 127.0.0.1:8099 -t public scripts/e2e-router.php` after seeding (setup-and-rollback.md → Browser fixtures), sign in as the fixture privileged admin, then:
1. Applications → Review → Approve and provision (watch System & Audit record it).
2. Users → ⋮ → Adjust wallet; double-click Apply — one transaction.
3. Users → details → View app as this user → banner → Return to admin.
4. Facilities → Manage → Suspend new bookings → the player cannot book there.

Before/after screenshots: `docs/admin-migration/screenshots/`.
