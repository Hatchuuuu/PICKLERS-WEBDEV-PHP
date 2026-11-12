# PICKLERS final system check - November 10, 2026

## Scope and evidence policy

Authoritative reference: `C:/Users/Nitro/Downloads/Rubrics for Final Project.pdf`, all five pages. The pasted master prompt is the user's request; the PDF supplies grading criteria, not execution instructions. This report audits the current working tree, including extensive pre-existing uncommitted work. No reset, data migration, production database write, deployment, or cosmetic redesign is authorized by this report.

PASS means behavior was executed successfully, not merely found in source. PARTIAL means an implementation has a demonstrated gap. UNVERIFIED means source exists but sufficient execution evidence has not yet been collected. Only fully verified criteria receive their entire weight; partial credit is left to the teacher. This is a conservative engineering estimate, not a promised grade.

## Architecture and traced behavior

- PHP 8.2+, Symfony 7.4, Twig, Symfony Security/CSRF/Validator, Doctrine DBAL and Doctrine migrations; MySQL 8.4 in Docker. Static JavaScript/CSS, no frontend build. Entry: `public/index.php` -> `Kernel` -> attribute routes in `config/symfony/routes.php`. Legacy `.php` aliases reach the same controllers.
- Authentication: `AuthController` -> Symfony password hasher -> `AuthService/UserRepository` -> users; `PlatformSession` regenerates and binds the session; authenticators reload account status and password state. Public registration always creates players. Profile/password: `AccountController` -> `AccountService` -> repositories. Deactivation retains history and blocks authentication.
- Admin: `AdminAreaMatcher` includes admin pages AND API aliases; `security.php` requires ROLE_ADMIN; controllers require capabilities. `CapabilityVoter` distinguishes ordinary and privileged administrators and blocks impersonated admin operations. Admin services own transactions, last-admin protection, financial invariants and append-only audits. `AccountRepository` omits password hashes from admin reads.
- Staff are existing player accounts linked by stable `staff.user_id` to facilities, not a global users.role string. `OwnerScope` computes memberships; owner portal permits assigned court operations while settings/staff management require ownership/admin. Membership is rechecked inside court-write transactions. Existing tests intentionally permit shared court edits/deletes.
- Booking: `ApiController` -> `PlayerActions`/`OwnerActions` -> BookingRepository/BookingRules/Schedule/Payments/WalletLedger. Rates and scope are server-owned; cancellation and ledger changes are transactional. Tournaments use TournamentActions/TournamentService/BracketEngine and locking. Discovery uses DiscoverController/DiscoverService. Staff/owner view data comes from OwnerPortalView. These modules are retained.
- Community: `CommunityActions` -> `CommunityRepository` -> feed_posts, feed_comments, feed_likes and direct_messages. Post creation and reading exist, with author_id, input limits and moderation visibility; no post editing/deleting endpoint or current app feed tab exists. Moderation lists all posts/comments, searches/filtering, and hides/restores content without deleting it.
- Persistence: SchemaInstaller owns base tables, Doctrine migrations own incremental additions. Existing repositories use DBAL arrays, not ORM entities. Replacing them with ORM entities solely for a rubric would be an unnecessary rewrite; teacher acceptance of DBAL repositories is explicitly unverified.
- Existing verification: isolated service runner, PHPUnit admin/web suites, Node frontend regression tests, `scripts/verify.php`, and Apache smoke tests. Test DB is a separate Docker service. Live XAMPP and application Docker data remain untouched.

Assumed load: classroom demonstration and ordinary concurrent venue use. This audit does not certify public production capacity. Existing README explicitly documents simulated payments and missing real payment-provider integration; those are not rubric requirements and will not be invented.

## Initial 100-point compliance matrix (frozen baseline, before application edits)

The Docker image for the baseline was built before application edits. Statuses below incorporate its completed execution. The initial publication had conservatively awarded zero while that run was pending; this section now records the measured baseline instead.

| Criterion (PDF wording) | Points | Status | Evidence | Problem | Solution |
|---|---:|---|---|---|---|
| Working Login & Logout System | 6 | PASS | Baseline PagesTest login/logout tests passed | Forced logout remains a separate CSRF gap | R01 |
| Proper Password Hashing & Security | 5 | PARTIAL | Hash/password tests passed; composer audit failed | 12 advisories in 8 packages; password boundary checks incomplete | R07, R01 |
| Correct Role Implementation (Admin & Staff) | 4 | PASS | Baseline SecurityTest, StaffIdentityTest, StaffCourtPermissionsTest passed | Contextual membership model is enforced; direct admin creation is separately missing | Preserve role model |
| Unauthorized access properly blocked | 2 | PASS | Baseline SecurityTest and staff scope tests passed | No demonstrated bypass in tested paths | Preserve checks |
| Role-based route protection (security.yaml / Controller) | 5 | PASS | Symfony firewall/capability and alias tests passed | PHP configuration is Symfony's equivalent supported configuration | Preserve checks |
| Proper access denial (403 / redirect) | 3 | PASS | Guest, player, owner and scope denials passed | No demonstrated denial defect | Preserve handlers |
| Role checks in controller & templates | 3 | PASS | Admin/owner page and security tests passed | No demonstrated defect in covered controls | Preserve capability checks |
| Create users/staff | 5 | FAIL | ActionController action map, UserAdminService, users panel | No admin create-account workflow; owner staff invitation only links existing players | R02 |
| Update user/staff | 4 | PARTIAL | UserAdminService changeRole/resetPassword | No admin name/email editing | R02 |
| Delete user/staff | 4 | PASS | Baseline UsersTest lifecycle and confirmation; owner staff revocation passed | New account creation needs deletion coverage as it is added | R02 regression |
| View all data records | 3 | PASS | Baseline admin panels and moderation content search passed | Admin edit/delete of posts is separately mandatory and missing | R04 |
| Admin dashboard (total users, total staff, total records) | 3 | PARTIAL | MetricsService::summary, overview Twig | Active users and bookings exist; staff/post totals missing | R03 |
| Create records (products, posts, etc.) | 6 | PARTIAL | CommunityActions::createPost, author_id | API exists; app currently has no community view | R04 |
| Edit own records | 6 | FAIL | Community action map/repository | No post update endpoint or form | R04 |
| View records | 4 | PARTIAL | feed_posts API, moderation content list | No current player feed tab | R04 |
| Create | 4 | PASS | Baseline ApiTest, courts and promo persistence tests passed | Keep existing creation invariants | R04 UI integration |
| Read | 3 | PASS | Baseline discovery/app/admin read tests passed | Keep existing reads | R04 UI integration |
| Update | 4 | PARTIAL | Court/profile/booking updates | Post update missing | R04 |
| Delete with confirmation | 4 | PARTIAL | Account/court/promo dialogs exist | Own post deletion missing | R04 |
| Form validation | 4 | PASS | Baseline invalid/duplicate account, booking and field tests passed | Add boundary coverage for new forms | R02/R04 |
| Flash messages | 2 | PASS | Baseline PagesTest HTML error/flash and AccountTest passed | Preserve existing feedback patterns | R05 |
| CSRF protection | 3 | PARTIAL | Shared CSRF guards, admin CSRF | GET logout aliases bypass token checking | R01 |
| No plain-text passwords | 2 | PASS | Baseline hash verification, account sanitization and export tests passed | Keep secrets out of new responses/audit | R02 regression |
| Clean layout & navigation | 3 | UNVERIFIED | Existing app/owner/admin layouts | Browser verification pending | R05 |
| Role-based menu display | 2 | PASS | Baseline role/capability menu and staff management tab tests passed | Add new controls using same checks | R02/R04 |
| Mobile readability | 3 | UNVERIFIED | Responsive CSS, horizontal table regions | Actual viewport verification pending | R05 |
| Clean controller usage | 1 | PASS | Traced service ownership; baseline PHP/container lint passed | No architectural rewrite needed | Preserve layers |
| Proper entity & repository usage | 1 | UNVERIFIED | Doctrine DBAL repositories, no ORM entities | Teacher interpretation of entity requirement unresolved | Retain architecture; disclose |
| Organized templates & routes | 1 | PASS | Baseline 59 Twig files linted, 48 routes and aliases verified | Keep structure | R04 integration |

Measured baseline verified score: **49/100**, with **51 points not fully verified**: 33 points in eight PARTIAL criteria, 11 in two FAIL criteria, and seven in three UNVERIFIED criteria. Category baseline: authentication 12/17, authorization 11/11, admin 7/19, staff 0/16, CRUD 7/15, validation/security 8/11, UI 2/8, structure 2/3. No partial credit is assumed. Known gaps affect account CRUD, dashboard counts, staff post CRUD, logout CSRF, dependency security and browser evidence. Potential after fixes: 100 only if every criterion and teacher interpretation are verified.

## Mandatory function mapping

| PDF requirement | Existing PICKLERS mapping | Initial result / work |
|---|---|---|
| Admin/staff login, logout, own profile/password | Shared AuthController, AccountController, app Settings | Present, verify; protect logout |
| Create Admin accounts | Existing privileged role policy + new UserAdminService creation | Missing; only privileged admins may create admins |
| Create Staff accounts | Create player + existing facility membership atomically | Missing from admin console |
| View accounts: username/email, role, date created | User directory | Present; add staff membership label/filter |
| Edit name/email/role/reset password | UserAdminService and account dialog | Name/email missing; preserve role/reset privilege rules |
| Delete with confirmation; disable/archive staff | Typed DELETE + history guard; deactivation keeps data | Existing, verify with new staff accounts |
| Total users/staff/records | MetricsService and overview | Add unique active linked staff + all post count; keep current KPIs |
| Admin view/edit/delete any staff record; search/filter | Community posts plus existing moderation content search | Add actual update/delete, including hidden posts for admin |
| Admin config, controller checks, Twig menus | security.php + capabilities + Twig | Existing PHP equivalent of security.yaml; verify aliases |
| Staff create/read/edit/delete own records only | Community feed_posts.author_id | Add complete UI/API, reject other authors/admin posts |
| Staff cannot manage accounts/roles/admin dashboard | AdminArea + ROLE_ADMIN firewall | Existing, explicitly test with staff membership |
| Manual restricted URL -> 403 or redirect | Firewall and scope checks | Verify GET pages, POST actions, legacy API aliases |

**User decision recorded:** preserve venue staff's shared court management; use community posts as staff-owned rubric records (response November 10). Court operations are operational shared resources, not the rubric's authored record module. Teacher acceptance of that mapping remains external; it must not be represented as literal creator-only court access.

## Prioritized development plan (published before implementation)

| ID / priority | Problem and root cause | Files / module / rubric | Solution and implementation | Risks / dependencies | Test procedure / expected result |
|---|---|---|---|---|---|
| R00 P0 | Existing working tree lacks fresh verification for this audit | scripts/verify.php, tests, Docker test profile / all categories | Run existing complete gates against separate disposable DB; retain output | No production DB access; baseline before edits | Capture suite counts, failures and environment limitations |
| R01 P0 | Forced signout through GET auth/logout/API aliases | AuthController, AccountController, logout callers, PagesTest / auth + CSRF | Require existing CSRF for logout; update first-party callers; retain legacy routes with token check | Must preserve all signout flows and post-delete redirect; no schema change | Missing/forged token leaves session intact; valid logout clears it; all aliases covered |
| R02 P1 | Admin cannot create accounts or edit names/emails; staff membership not shown in directory | UserAdminService, AccountRepository, Capability, ActionController, users/dialog Twig, UsersTest / admin CRUD + roles | Reuse UserRepository creation and OwnerRepository membership in one audited transaction. Staff is still player+membership. Explicit creation/update capabilities. Enforce existing privileged-admin boundary for admin creation/editing; validate names/email/password/facility; DB uniqueness remains authoritative. Add directory staff filter/label and forms | Creation and staff grant must roll back together; no auto-privilege; keep wallet/signup policy; preserve self/last-admin rules. Depends R00 | Real endpoint tests for admin/staff create, duplicate/invalid fields, staff/admin denial, name/email persistence, hash secrecy, audit, disable/delete behavior |
| R03 P1 | Mandatory dashboard totals incomplete | MetricsService, overview Twig, metrics tests / dashboard | Count active distinct user IDs with memberships and feed_posts rows; display defined totals using current KPI refresh | Duplicate memberships must not double count; legacy unlinked staff not login-capable | Compare displayed/JSON totals to SQL; deactivated users excluded from active-staff count; empty tables zero |
| R04 P1 | Authored posts have no current UI or update/delete | CommunityActions/Repository, ApiController, AppController, feed Twig, app.js/CSS, moderation Twig/actions, tests / staff CRUD, admin full access | Reuse feed_posts.author_id; validate mutation input; enforce author/admin access while locking post. Hide edit/delete controls for others, confirmation before delete. Preserve moderation snapshots/audits. Add community access without replacing existing tabs; admin controls reuse current dialog system | Concurrent likes/comments must serialize with delete; no orphan child rows; hidden content must not be publicly editable; admin edits audited. Depends R00 + user mapping decision (received) | Staff CRUD persistence; other staff/admin authors denied; admin any-post edit/delete; invalid IDs, missing CSRF, GET mutation rejected, HTML escaping, confirmation, rollback and child cleanup |
| R05 P2 | Remaining verification gaps including real viewports and HTML feedback | Existing suites + targeted rubric tests; docs / every criterion | Run complete gates after edits, Apache smoke and available browser checks. Reassess each criterion with executed evidence; record unverified items | Do not deploy or mutate real accounts just for demonstration. Depends R01-R04 | No regressions in bookings/payments/tournaments/owner scope; desktop/tablet/mobile review where available |
| R06 P3 | Teacher needs a reproducible final-check walkthrough | This report and classroom checklist | Document exact demo path, evidence, limitations and remaining tasks | Teacher decides actual grade; no fabricated compliance | Final category totals and explicit readiness statement |
| R07 P0 (baseline discovery) | Composer reports 12 security advisories in eight packages | composer.lock / security, compatible runtime dependencies | Update existing Symfony/Twig dependency tree within current Composer constraints; install locally and test in Docker. No new package or major framework change | 37 locked packages receive compatible updates; full suite required | Composer audit returns zero advisories, install/platform/container/functional gates pass |
| R08 P2 (browser discovery) | Screen-reader-only table elements escape their scrolling container at 390px | public/assets/css/admin.css / mobile readability | Give the existing table scroll region position:relative | Must retain horizontal table scrolling and menu placement | Dashboard/users/moderation at 390/768/1440px have no document overflow; menu editing still works |

No ORM rewrite, payment provider, load infrastructure, visual redesign or new dependency is planned. Application changes will be backed up under ignored `var/final-check/` before editing; no pre-existing edits are reverted. Rollback consists of reverting only this task's patches against those saved current-file copies, never resetting the entire working tree. No schema migration is planned.

## Execution log and final reassessment

- Initial audit and roadmap were published before application edits. User chose posts as authored rubric records while preserving shared court operations.
- Frozen baseline: service suite passed 406 assertions; frontend 170/171 passed (hard-coded signup claim); PHPUnit 311/312 passed (empty-view fixture omitted the controller's city_count); composer audit failed with 12 advisories. All other baseline gates passed. The empty-view failure was a test-fixture defect: HomeController already supplied city_count; the fixture was repaired instead of changing correct production behavior.
- R01 implemented: all logout aliases require CSRF for an authenticated session; player and owner signout use POST forms. Administrative password reset preserves exact whitespace in JS and PHP. Shared password policy rejects null bytes and passwords exceeding bcrypt's 72-byte limit; existing hashes remain valid for login.
- R02 implemented: audited transactional player/staff/admin creation, account name/email editing, staff labels/filter, staff membership cleanup on permanent account deletion. Admin creation/edit of another admin remains privileged. Review also found a stale invitation race: insertStaff now locks and rechecks the selected player and uses current identity data; existing legacy unlinked staff rows remain supported. Staff account creation enforces the table's 100-character name bound.
- R03 implemented: unique active staff accounts and all-post record counts added to the existing dashboard and KPI refresh, with explicit definitions. Existing financial figures retained.
- R04 implemented: existing community API now supports own-post updates/deletes; Community tab exposes create/read/edit/delete. Other authors' records and hidden posts are unavailable to ordinary authors. Admin moderation retains search/filter and gains audited edit/delete for every post, including hidden posts. Post deletion removes likes/comments transactionally, retains moderation cases/snapshots, and serializes with comment/like writers through post locks.
- R07 implemented: compatible dependency updates, local composer install and Docker installation succeeded. No new dependencies or composer.json changes. Composer audit reports zero advisories.
- Landing claim corrected by removing the unsourced "200 signups/day" card; the existing sourced counters remain.
- R08 implemented and browser-verified. Community controls use existing theme tokens and buttons; their text contrast is adjusted within the new Community cards only.
- Before-file copies, hashes, this task's standalone diff, raw test logs, screenshot evidence and the browser harness are in ignored `var/final-check/`. Pre-existing changes are preserved. No production schema/data migration or deployment was performed.

## Final 100-point reassessment

The selected record module is community posts, per the user's decision. PASS below combines source tracing with successfully executed tests; UI criteria additionally use actual Chromium viewport checks. The score is an evidence-based estimate for the tested workspace, not the teacher's awarded grade or a guarantee that no defect exists.

| Criterion | Points | Final status | Executed evidence / reasoning | Remaining problem / next action |
|---|---:|---|---|---|
| Working Login & Logout System | 6 | PASS | PagesTest; RubricComplianceTest logout aliases; browser staff login/password-change/logout/relogin | Existing hashes and first-party flows preserved |
| Proper Password Hashing & Security | 5 | PASS | PasswordChange/PasswordWhitespace/SessionRevocation tests; exact admin-reset hash verification; >72-byte/null rejection; composer audit clean | Public deployment requires normal operational security described below |
| Correct Role Implementation (Admin & Staff) | 4 | PASS | StaffIdentity/StaffCourtPermissions plus new admin creation/escalation tests | Staff remains a venue membership, as approved |
| Unauthorized access properly blocked | 2 | PASS | SecurityTest, own-post foreign-author/admin-author denials, staff alias tests | No demonstrated bypass in tested paths |
| Role-based route protection (security.yaml / Controller) | 5 | PASS | AdminAreaMatcher and controller/capability tests, real Apache guest denials | Uses supported Symfony PHP security config, not a redundant YAML file |
| Proper access denial (403 / redirect) | 3 | PASS | Staff direct admin URLs and API aliases denied; browser redirect verified | Foreign posts use indistinguishable 404 to avoid confirming IDs |
| Role checks in controller & templates | 3 | PASS | Capability tests; staff lacks account actions/admin link; ordinary admin lacks admin-creation option | Existing privilege boundaries retained |
| Create users/staff | 5 | PASS | New real-endpoint player/staff/admin creation, hash secrecy, membership rollback; browser staff creation | Use privileged administrator to create another administrator |
| Update user/staff | 4 | PASS | Existing role/reset tests; new name/email/duplicate rollback; staff identity synchronization; browser prefill/save | Staff assignments continue through existing facility staff workflow |
| Delete user/staff | 4 | PASS | UsersTest lifecycle/history protection; new staff lifecycle/membership cleanup and confirmation tests | Accounts with financial/booking history must be deactivated, not erased |
| View all data records | 3 | PASS | Admin panels/moderation content tests and real browser search/edit of staff posts | Selected authored records are posts |
| Admin dashboard (total users, total staff, total records) | 3 | PASS | SQL-backed active user/staff/post KPI tests, duplicate-membership/deactivated-account checks, rendered KPI assertions | Definitions explicitly state what is counted |
| Create records (products, posts, etc.) | 6 | PASS | Staff create post persisted with server-owned author_id; browser create/reload | No new unrelated record module |
| Edit own records | 6 | PASS | Own edit persists; another staff/player and admin-authored posts denied; browser edit/reload | Shared venue courts intentionally retain existing permissions |
| View records | 4 | PASS | Shared feed and rendered escaped post content; visible records readable by staff | Moderated hidden posts stay out of public reads |
| Create | 4 | PASS | Existing court/booking/promo creation regressions; new post/account creation | Existing business validations retained |
| Read | 3 | PASS | Discovery/app/admin/feed functional tests and browser post reads | No new read exposure in tested endpoints |
| Update | 4 | PASS | Existing court/profile/booking regressions; new post/account updates | Invalid and duplicate inputs leave rows unchanged |
| Delete with confirmation | 4 | PASS | Account typed DELETE; post server confirmation; browser opens confirmation before deleting own post | Post deletion is permanent; UI states child-record impact |
| Form validation | 4 | PASS | Required/format/duplicate/type/length tests, staff name bound and transactional failure injection | Browser constraints supplement server checks |
| Flash messages | 2 | PASS | PagesTest ordinary HTML auth flash/errors; existing async messages; browser success/error behavior | New fetch forms use current inline/toast feedback conventions |
| CSRF protection | 3 | PASS | Forged/missing tokens across post/account/admin/logout paths; GET post mutations refused | Legacy logout GET is accepted only with valid token |
| No plain-text passwords | 2 | PASS | Stored hashes verify; creation response/audit contain no password; SessionUser and exports omit hashes | Password sharing remains an operator responsibility |
| Clean layout & navigation | 3 | PASS | Existing layout retained; real Chromium admin directory/moderation/dashboard and Community forms exercised | No full redesign performed |
| Role-based menu display | 2 | PASS | Existing role/capability tests plus staff browser/admin URL restrictions and own-only post controls | Dual owner/admin capacities preserve their intended additional access |
| Mobile readability | 3 | PASS | Admin overview/directory/moderation and Community at 1440, 768 and 390px; no document overflow; screenshot review; dark/light Community | Table content scrolls within its existing region |
| Clean controller usage | 1 | PASS | Traced services own transactions/business rules; all PHP/container checks passed | No unnecessary layer added |
| Proper entity & repository usage | 1 | UNVERIFIED | DBAL repositories and schema/migration tests pass; ORM entities are absent | Ask teacher whether DBAL repositories meet the literal entity criterion; do not rewrite working persistence solely to assume acceptance |
| Organized templates & routes | 1 | PASS | 60 Twig templates lint; 48 routes including legacy aliases; full web/admin functional suites | Existing URL structure retained |

### Final score

| Category | Maximum | Verified |
|---|---:|---:|
| Authentication & Roles | 17 | 17 |
| Authorization | 11 | 11 |
| Admin Features | 19 | 19 |
| Staff Features | 16 | 16 |
| CRUD | 15 | 15 |
| Security & Validation | 11 | 11 |
| UI & Usability | 8 | 8 |
| Code Quality | 3 | 2 |
| TOTAL | 100 | **99** |

**49 -> 99 verified points. One point remains unverified; no partial point is invented.** All achievable mandatory functions in the selected post module are implemented and tested. Teacher acceptance of contextual staff roles, the agreed post/court mapping, and PHP configuration remains a grading interpretation; the tests establish behavior, not the teacher's interpretation. Do not claim 100/100 until the entity/repository criterion is settled.

### Final mandatory function checklist

- Admin and staff: login/logout, own profile and own password change: PASS (shared account tests and browser staff lifecycle).
- Admin account management: create admin/staff/player; list identity, role, joined date; edit name/email; change role; reset password; confirmation deletion; deactivate/reactivate: PASS. Administrative creation or modification of admin access uses the existing privileged tier. Existing staff assignment/revocation remains in the venue's Staff tab.
- Admin dashboard user/staff/post totals: PASS. Staff excludes unlinked legacy entries and deactivated accounts; duplicate memberships count once.
- Admin full access to selected records: view/search/filter/edit/delete any post, including moderated posts, with audit history: PASS.
- Staff authored records: create/read/edit/delete own visible posts with confirmation; cannot modify another staff member's or an admin's post: PASS.
- Staff account/role/admin-page restrictions, including manual URLs and API aliases: PASS. Guest restrictions and disabled account/session revocation regressions: PASS.
- Shared court access: deliberately unchanged under the user's recorded choice. It is not represented as creator-only court access.

### Tests actually executed

| Command / check | Final result | Evidence |
|---|---|---|
| `docker compose --env-file .env.docker --profile test run --build --rm test` | **All 15 gates PASS** | Latest rerun: `var/final-check/recheck-final-verification.log`; earlier acceptance retained separately |
| Full PHPUnit admin/web suite (inside that command) | **326 tests, 2,589 assertions; PASS** | Includes 14 new rubric tests and all existing suites |
| Service runner `php tests/run.php --fresh` (inside that command) | **406 assertions; PASS** | Separate disposable picklers_test database |
| Node frontend regression suite | **173 tests; PASS** | Includes two new pending-request/error/retry tests; `frontend-final.log` and final gate |
| Composer validate/platform/audit | **PASS; zero security advisories** | Compatible installed lock; local `composer install --no-scripts --no-interaction` also succeeded |
| All project PHP syntax; container lint dev/test/prod; Twig lint; route map; prod cache warmup | **PASS** | 60 Twig files and 48 routes; final verification log |
| Fresh/stale schema bootstrap checks | **PASS** | Existing safety checks; no production migration |
| Chromium browser workflow/viewport checks | **18 checks PASS, zero page JavaScript errors** | `browser-results.json`; synthetic staff created in UI; account editing, post create/edit/admin edit/confirmation delete, direct URL denial, password change/logout/relogin; 1440x1000, 768x1024, 390x844; light/dark |
| `php scripts/docker-smoke.php http://127.0.0.1:8101` | **21/21 PASS** | Separate Apache test container attached only to disposable test database; `apache-smoke.log` |
| `php bin/console lint:twig templates --env=dev` and `composer audit --locked --no-interaction` locally | **PASS** | Confirms local installed dependency tree and final Twig syntax |
| `git diff --check` | **PASS** | No whitespace errors; existing CRLF conversion notices are not failures |
| `graphify update .` | **PASS** | AST graph refreshed; unsupported/empty JSON/config sources reported by Graphify remain a graph indexing limitation |

Initial failures are recorded above and repaired. Expected fault-injection errors (notification/provisioning/staff write failures) intentionally test rollback and are not failing tests. During browser harness development, a strict text locator, a hidden-checkbox target, an immediate reload assertion, and case-sensitive signout targeting were corrected; the final recorded browser run passed. No product success was inferred from those earlier harness failures.

### Readiness, risks and remaining work

- **Ready for a classroom rehearsal of the tested workspace using the walkthrough below**, subject to the teacher's entity/repository interpretation. This is not a public production readiness or capacity certification.
- The existing running application Docker containers were **not rebuilt/redeployed**. Their code/dependencies may still be the earlier image; the updated workspace and isolated test image are what were verified. Deploy through the existing documented build/setup process, preserve volumes, and back up before changing a real deployment. No production data was read or changed by these tests.
- No full line-by-line review of every untouched file or arbitrary concurrent workload was performed. High-risk authentication, authorization, account/record mutations and persistence ownership were traced; the full existing booking/wallet/tournament/owner/session regression suites passed. Real payment processing, production TLS/backups/monitoring and load capacity were not added or certified; the existing README explicitly documents those deployment limits.
- Existing development seed posts can contain old promotional text or remote image URLs; those user-content rows were not rewritten. Real-money top-ups remain fail-closed in production.
- No confirmed remaining failing automated check or demonstrated bypass in the tested changed paths. Broader security testing can find issues beyond these tests; a clean dependency audit does not prove every application path is secure.

### Task status and continuation

R00, R01, R02, R03, R04, R05, R07 and R08: COMPLETE with the executed evidence above. R06: report/walkthrough COMPLETE; external teacher confirmation of the entity criterion remains OPEN. Next action: rehearse with a privileged admin and a newly created facility staff account on the updated installation, then obtain the teacher's answer about DBAL repositories. Do not add ORM entities or change the shared court policy without a concrete requirement.

### Classroom walkthrough

1. Start the **updated** installation using the existing README/demo guide. Use an isolated demo database, never reset the live database. Use your own demonstration credentials; the fixture credentials in test scripts are synthetic and must not be deployed as real accounts.
2. Sign in as a privileged administrator. Open User Directory, choose Create account, and create one Administrator and one Staff account. Staff creation needs an existing facility. Verify their role and joined date in the directory; ordinary administrators can create players/staff but cannot grant admin power.
3. Filter users to Staff. Open its menu, edit name/email, and verify persistence. Demonstrate a duplicate email rejection, a password reset, and the dashboard's active-user/staff/post counts. User role changes remain in the same existing account menu.
4. Sign in as the Staff account. Open Community (desktop sidebar or mobile header), create a post, reload/read, edit, and open the deletion confirmation. Cancel once, then confirm deletion. Create another post for the administrator demonstration.
5. Attempt `/admin`, `/admin?tab=users`, and an admin API action as staff. Show the redirect/403. Sign in as a second staff account and show that the first account's post has no edit/delete controls; a forged request is denied by the server as well.
6. As administrator, open Content Moderation -> Community content, search for the staff post, edit it, and delete with typed DELETE confirmation. Audit history remains available. Record totals reflect the persisted rows.
7. Show own profile/password in player Settings. Change a password, sign out using the confirmation form, and sign back in. Disable the staff account from admin and demonstrate refused access; reactivate it. Delete an unused account only with confirmation; demonstrate deactivation for accounts whose history must be retained.
8. Repeat the relevant views on desktop/tablet/mobile. Keep the full verification log and this criterion matrix available. Explain staff memberships and the selected post record module accurately; do not claim that shared courts became creator-only.

### Files changed in this task

- src/Domain/PasswordPolicy.php
- src/Repositories/CommunityRepository.php
- src/Repositories/OwnerRepository.php
- src/Web/Api/CommunityActions.php
- src/Web/Api/OwnerPortalActions.php
- src/Web/Controller/AccountController.php
- src/Web/Controller/ApiController.php
- src/Web/Controller/AppController.php
- src/Web/Controller/AuthController.php
- src/Admin/Controller/ActionController.php
- src/Admin/Repository/AccountRepository.php
- src/Admin/Security/Capability.php
- src/Admin/Security/RoleMapper.php
- src/Admin/Service/MetricsService.php
- src/Admin/Service/ModerationService.php
- src/Admin/Service/PanelBuilder.php
- src/Admin/Service/UserAdminService.php
- templates/admin/_dialogs.html.twig
- templates/web/app.html.twig
- templates/web/home.html.twig
- templates/web/partials/app/_mobile-header.html.twig
- templates/web/partials/app/_modals.html.twig
- templates/web/partials/app/_sidebar.html.twig
- templates/web/partials/app/_tab-community.html.twig
- templates/web/partials/owner/_modals.html.twig
- templates/admin/panels/_moderation.html.twig
- templates/admin/panels/_overview.html.twig
- templates/admin/panels/_users.html.twig
- public/assets/css/admin.css
- public/assets/css/app.css
- public/assets/js/admin.js
- public/assets/js/app.js
- tests/Frontend/community-posts.test.js
- tests/Frontend/portal-accessibility.test.js
- tests/Web/PagesTest.php
- tests/Web/RubricComplianceTest.php
- composer.lock
- docs/final-system-check-2026-11-10.md

Graphify also refreshed its generated graph.json, graph.html and GRAPH_REPORT.md and retained its curated-graph backup. Ignored var/final-check artifacts are verification records, not application modules.

## Second verification requested by the user

Repeated against the current workspace on November 10, 2026. This was a fresh verification and additional boundary review, not a reuse of the earlier pass result.

Findings corrected:

1. **Staff name edits could return HTTP 500.** Creation enforced staff.name's 100-character bound, but admin identity editing only enforced users.name's 120-character bound. A new real-endpoint regression first reproduced 500 for a 101-character staff name. The service now rejects it with HTTP 422 before changing either table; the test proves both records remain unchanged and a 100-character edit succeeds. Evidence: `recheck-staff-name-red.log`, then the passing 326-test suite. Affected files: UserAdminService.php and RubricComplianceTest.php.
2. **The Community header link crowded 320px screens.** The earlier browser evidence began at 390px. Rechecking all six app tabs at 320px reproduced horizontal overflow and a clipped avatar. The link now uses the existing header icon style, keeps its Community posts accessible name, and retains the desktop text navigation. Affected file: `_mobile-header.html.twig`.
3. **The existing wallet top-up button clipped at 320px.** After fixing the header, the wallet balance row still overflowed. Its flex row now wraps when space runs out. Only layout changed; payment rules and top-up actions are unchanged. Affected file: app.css.
4. **A tracked Symfony reference file had been removed during the previous cleanup.** It was mistakenly treated as a disposable generated file. Restored `config/symfony/reference.php` from the saved pre-task copy, including any pre-existing content. Both SHA-256 hashes match `26fac67784984a78c342a7f28aFC5A5826146dddb57832417e44a8f75507daa7` (case-insensitive); Git no longer reports that file deleted. No whole-tree reset was used.

Fresh results:

- Full Docker verification: **all 15 gates PASS**, including **326 PHPUnit tests / 2,589 assertions**, **406 service assertions**, **173 frontend tests**, clean dependency audit, schema bootstrap, Twig/container lint and route checks. Log: `var/final-check/recheck-final-verification.log`.
- Real Chromium layout checks: **36/36**, covering all six app tabs at **320, 360, 375, 390, 768 and 1440 pixels**. No document overflow remains in these checks. Actual screenshot review confirmed that the wallet action remains visible. Evidence: `recheck-layout-final.log`, `recheck-layout.json`, `recheck-wallet-320.png`.
- `git diff --check`: PASS. The restored reference file exactly matches the saved copy.
- Read-only Docker image/mount and hash comparison confirms that `picklers-app-1` uses a production image with source and composer.lock hashes different from the updated workspace. The deployment limitation is verified rather than assumed. No deployment or live-data mutation was performed.

The rubric estimate stays **99/100**, with the same external entity/repository interpretation outstanding. The new evidence repairs gaps in the previous verification; it does not establish a teacher-awarded grade, universal device compatibility or production capacity.
