# Symfony migration — complete

PICKLERS runs entirely on Symfony 7.4. The admin console moved first (docs/admin-migration/); every other page and API action followed. There is no legacy router, controller, view or middleware left.

| # | Section | Status |
|---|---|---|
| 0 | Shared groundwork (session, firewalls, per-section routing) | Done |
| 1 | Discover & facilities | Done |
| 2 | Landing page `/` | Done |
| 3 | Account & notifications | Done |
| 4 | Community (feed, comments, messages, user search) | Done |
| 5 | Booking & wallet | Done |
| 6 | Player app `/app` + the `sync` poll | Done |
| 7 | Owner portal (pages + `owner.php` writes) | Done |
| 8 | Owner application | Done |
| 9 | Tournaments (API + bracket console) | Done |
| 10 | Sign-in & registration | Done |
| 11 | Retire the legacy Router/Request/Response/controllers/views/middleware | Done |

## How a request is served
- `public/index.php` is a plain Symfony front controller: bootstrap → `Picklers\Kernel` → response. `Web\Http\InstallBase` keeps the XAMPP sub-directory install's base URL right (`/PICKLERS%20WEBDEV%20PROJECT`).
- Routes are `#[Route]` attributes in `src/Admin/Controller` and `src/Web/Controller` (`php bin/console debug:router`). The legacy URLs (`app.php`, `owner.php`, `auth.php`, `api.php`, `/app/owner/tournaments/{id}`…) are kept as routes, so links, bookmarks and the JavaScript are unchanged.
- **Firewalls** (`config/symfony/packages/security.php`): `admin` for `/admin*` and `/api` admin actions (`Admin\Http\AdminArea`); `web` for everything else, where `Web\Security\SessionAuthenticator` turns the session into a `SessionUser` (two-hour idle timeout, impersonation expiry, deactivated accounts, sign-out after a password change). Guests continue anonymously.
- **Session**: Symfony's (`framework.session`, native storage, cookie `picklers_session`), read through `Admin\Security\PlatformSession`.
- **Sign-in / registration** (`Web\Controller\AuthController`): Symfony's password hasher (`auto`) against the existing hashes, `LoginThrottle`, the shared password policy (`Domain\PasswordPolicy`). Sign-out invalidates the session.
- **CSRF**: Symfony's token manager, token id `picklers`, embedded by every page (meta tag / hidden fields) and checked by `AbstractWebController::validCsrf()`.
- **Impersonation** on the player/owner side: `Web\Security\ImpersonationGate` (restricted actions, per-action audit written with the real HTTP status on `kernel.terminate`).
- **Templates**: Twig under `templates/web/` (pages + `partials/app`, `partials/owner`). View logic that needed PHP (regex formatting, labels) lives in `Web\Twig\ViewExtension`, `PlayerViewExtension` and `OwnerViewExtension`. Error pages: `templates/bundles/TwigBundle/Exception/error.html.twig` (HTML) and `Web\EventSubscriber\JsonErrorSubscriber` (API/XHR).
- **Database**: Doctrine DBAL owns the MySQL connection. `Core\Database` (the persistence engine every service uses) runs on that same connection inside the kernel (`Kernel::boot()`), so Symfony code, the shared rules in `src/Domain` and the engine share one session per request. There is no JSON-file fallback any more: MySQL is required.

## API (`/api?action=…`, `Web\Controller\ApiController`)
One dispatcher keeps the app's JSON contract. Admin actions go to the admin console's dispatcher, Discover/Account actions to their REST controllers, and the rest to handler services in `src/Web/Api/`:

| Handler | Actions |
|---|---|
| `PlayerActions` | sync, my_tournaments, matches, join_match, book_court, quote_booking, bookings, cancel_booking, wallet, top_up |
| `CommunityActions` | feed_posts, create_post, like_post, add_comment, messages, send_message |
| `OwnerActions` | get_open_play_roster, get_pending_requests, verify_checkin, update_court_status / owner_update_court, approve_booking, decline_booking, search_players / search_users |
| `TournamentActions` | list/get/create/update/delete_tournament, add_tournament_player/team/entrant, update/remove_tournament_team, remove_tournament_player, mix_tournament_teams, generate/reset_tournament_bracket, report/reset_tournament_match |
| `OwnerPortalActions` (POST `owner.php`) | add/edit/delete_court, toggle_court_status, end_court_session, host/cancel_open_play, update_facility_settings, get_open_play_roster, create_tournament, add/revoke_staff, request_payout |

The shared checks run first, in the original order: CSRF (by method and by action, so a crafted GET cannot mutate), impersonation audit and restriction, the per-action rate limit (`Web\Security\RateLimiter`).

## How the conversion was verified
- Every page was rendered through its old PHP view and its new Twig template with the same data, and the normalised HTML compared: identical except the stylesheet now goes through `asset()` and values the old views echoed unescaped (e.g. "Bread & Butter") are now HTML-escaped.
- `tests/Web/ApiTest.php`, `PagesTest.php`, `OwnerPagesTest.php` drive the real endpoints; `php scripts/verify.php` runs every gate.
- Browser (fixture server): signed in through the real form, the player app loaded with its live API calls, and an owner listed a court through the portal (CSRF token from the page, row saved).

## Behaviour changes
- Tests and the service-level suite use the same Asia/Manila timezone as the app.
- Registration hashes new passwords with Symfony's `auto` hasher; existing hashes keep working.
- Facility logos and owner documents are validated from the uploaded bytes; documents go to the private document directory the admin console reads (`PRIVATE_DOCUMENT_DIR`).
- Sessions opened before the session moved to Symfony sign in once more.

## Deploying a change
Run `composer install`, then `php bin/console cache:clear --env=prod` — the production container is compiled once and does not notice code changes on its own.
