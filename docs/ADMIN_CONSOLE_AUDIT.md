# PICKLERS admin console audit

Audited: 5 October 2026. Scope: current working tree, supplied desktop screenshot, source inspection, PHP syntax checks, and the existing automated test runner. This is an audit, not an implementation. No application source was changed.

## Feasibility and recommended architecture

Yes: Symfony can run the whole admin console without imposing a different UI. Keep the existing HTML structure, CSS, assets and JavaScript behavior while moving admin requests into Symfony controllers and converting presentation to Twig. Symfony does not require EasyAdmin or a replacement dashboard theme.

The installed CLI is PHP 8.2.12. Composer currently requires PHP >=8.1 and has no framework dependencies. Symfony 7.4 LTS requires PHP >=8.2, so it is a suitable target subject to checking Apache's PHP runtime, extensions, Composer resolution and the teacher's rubric. Raise the declared PHP minimum if selecting 7.4. Use a supported patch release and lock dependencies.

Recommended: introduce a Symfony kernel for admin routes and preserve a controlled legacy route bridge for player/owner/public pages. Reuse business services through dependency injection; move every admin action into Symfony. A temporary adapter is useful during migration but simply including the old admin PHP file is not the finished result. Preserve IDs, users, password hashes, bookings, wallets, uploaded documents, and route aliases.

Alternatives: a separate Symfony admin application creates extra session, deployment and configuration coordination; rewriting the entire platform unnecessarily expands this request. Neither is the default recommendation.

References: [Symfony 7.4 requirements and support](https://symfony.com/releases/7.4), [Symfony's incremental migration guidance](https://symfony.com/doc/7.4/migration.html).

## What already exists

- Ten admin panels: overview, applications, facilities, bookings, users, moderation, ledger, promos, analytics and system.
- Owner application approval/rejection and protected permit/ID streaming.
- User details, role changes, verification, deactivation/reactivation, deletion, password reset, individual notices and account impersonation.
- Booking status changes/cancellation; wallet credit/debit operations; promo creation, toggle and deletion.
- Server handlers for broadcast notices and court status overrides, though their presence does not prove a usable admin UI.
- CSRF checks, admin checks, session timeout, inactive-account rejection, private document checks, and several transactional domain operations.
- Shared dialog utilities already handle focus, Escape and focus restoration. Preserve these rather than assuming all modal accessibility is absent.
- Responsive CSS, real data summaries, and a custom test runner already exist.

These features need migration and end-to-end verification, not duplicate implementations.

## Findings and acceptance requirements

Severity: P0 blocks reliable completion; P1 major correctness or capability gap; P2 usability/maintainability improvement. These are static findings unless marked as reproduced. Missing features mean not found in the inspected admin implementation, not proof of absence in every possible deployment.

| ID | Priority | Finding and evidence | Required outcome |
|---|---|---|---|
| A01 | P0 | **Reproduced: test baseline is failing.** `php tests/run.php` exits 1: 31 assertions pass, 13 suites error on missing `picklers_test` tables. `Database.php:118` trusts a schema stamp; `.test-state/.schema_version` contains `8`. | Diagnose schema bootstrap against a disposable database. Test fresh creation and stale stamp recovery. No production database reset. Do not claim a passing migration until baseline and new tests pass. |
| A02 | P1 | **Moderation is unfinished.** `views/pages/admin.php:1380` explicitly says “No moderation backend wired up yet.” | Persisted post/comment review queue, inspect, hide/restore, resolve/dismiss, reasons and audit history; enforce visibility in the public feed too. Provide a real way to populate the queue. |
| A03 | P1 | **Ledger mislabels bookings as settlement.** `admin.php:1412` includes everything except cancelled bookings and labels rows “settled.” All row statuses use the approved style. | Separate booking value from recorded payments, wallet movements, refunds and adjustments. Pending/declined/pay-on-site bookings must not automatically become settled receipts. Use evidence-backed payment status. |
| A04 | P1 | **“Dispute-Free Rate” is not a dispute metric.** `AdminController.php:78` derives it from cancellations; `:230` also includes declines, so initial and refreshed definitions differ. | Use one service for both summaries and label a cancellation-derived metric accurately. Do not invent dispute statistics without dispute records. |
| A05 | P1 | **Admin authority is coarse and inconsistent.** `AuthMiddleware.php:106` accepts role=admin OR is_admin=1; `AdminController.php:208` requires is_admin. `admin.php:108,888` labels ordinary admins SUPER_ADMIN. | One consistent role mapping and server permission checks. Make privileged role management, wallet adjustments, deletion and impersonation explicit permissions; protect the last administrator. Display the actual role. |
| A06 | P1 | **No dedicated administrator audit trail found.** `admin.php:1660` shows environment facts and recent bookings/applications. No `audit_log`/`audit_event` implementation found in inspected source. Wallet labels alone do not identify the acting admin. | Append-only audit history with actor, action, target, reason, timestamp, outcome and redacted changes. Persist successful mutation and audit event atomically where applicable. Add searchable read-only audit UI. |
| A07 | P1 | **Facilities panel cannot manage facilities/courts.** `admin.php:1135–1186` is a search and read-only table; `AdminController.php:946` has a court-status handler. | Facility detail with owner/contact, courts, real pricing/status, maintenance controls and governed availability changes. Enforce changes in player/owner booking paths, with explicit treatment of existing bookings. |
| A08 | P1 | **Impersonation loses the admin context.** `AdminController.php:583–609` replaces the login and explicitly requires signing out and back in to return. | Permission-gated, time-limited impersonation with original actor retained, visible banner, safe return, no nested/elevated targets, and entry/exit/action audit. |
| A09 | P2 | **Invented fallback facility data.** `admin.php:1160–1179` can show 4 courts, a 4.9 rating and a default price if fields are missing; singular reads “1 courts.” | Use real aggregates. Unknown data says “Not available” or an appropriate empty state; distinguish unknown from zero. Correct singular/plural copy. |
| A10 | P2 | **Search scope is misleading and overlaps.** `admin.php:2052` filters only loaded rows in the active panel despite “Search admin hub”; the facilities panel has a separate search. | One coherent search state, accurate labels/counts, clear action and no-results state. Search/filter/sort/page on the server for growing datasets; preserve state through navigation/reload. |
| A11 | P2 | **Bulk loading limits growth.** `AdminController.php:54–58` loads all users, facilities, bookings and applications for one page. No admin pagination/export implementation found. | Bounded server queries and pagination, stable sorting, validated filters, and permission-filtered CSV exports with formula-injection protection. Avoid rendering every panel's entire dataset up front. |
| A12 | P2 | **Notification indicator is recent activity, not unread state.** `admin.php:916–937` shows a dot when recent bookings exist, with no read tracking. | Either label it Recent Activity without an unread claim, or implement persisted admin read/unread state and actionable links. Do not mark old data “new” on every visit. |
| A13 | P2 | **Typography, controls and token usage need refinement.** `admin.php:342,410,432,567,1142` uses very heavy headings, small uppercase labels/buttons and hardcoded search styles. `docs/PICKLERS_BRAND_GUIDE.md` and current theme disagree on some colors/fonts. | Preserve the screenshot's recognizable navy/green identity. Improve density, alignment, readable labels, touch targets and focus styles; record deliberate token choices rather than globally restyling public/owner screens. |
| A14 | P2 | **Accessibility and responsive behavior need browser verification.** Search inputs lack an explicit associated label in their markup; fixed widths and compact buttons merit narrow-screen tests. Shared modal support is present. | Associated labels, keyboard navigation, announced errors/results, adequate contrast, accessible menus/dialogs, and usability at narrow widths/200% zoom. Do not claim WCAG compliance from a screenshot. |
| A15 | P2 | **Admin presentation is difficult to maintain.** `admin.php` is roughly 2,500 lines mixing data shaping, markup, styles and scripts. CSS/JS URLs use `time()` at lines 119–121. | Twig layout/partials, focused controllers/services and scoped assets, stable asset versioning, centralized KPI definitions. Maintain appearance and behavior through decomposition. |

The audit found 1 P0, 7 P1 and 7 P2 findings. This is not a penetration test. The authorization inconsistency is a confirmed policy mismatch; no exploit was attempted. Schema-stamp behavior is a likely contributor to the test failure, not proof of the database's history.

## UI audit assessment

Implementation integrity needs improvement: the console has a coherent PICKLERS identity, but unfinished moderation and misleading financial/role labels undermine operational trust.

Provisional code-review scores, not browser-certified accessibility or performance results:

| Dimension | Score / 4 | Basis |
|---|---|---|
| Accessibility | 2 | Shared accessible dialogs exist; search labels and compact controls need work. |
| Performance | 2 | All-panel data loading and timestamp cache busting; no measured load timings. |
| Responsive design | 2 | Breakpoints exist; no mobile browser session was performed. |
| Theming | 2 | Useful tokens coexist with inline colors and conflicting brand guidance. |
| Implementation integrity | 1 | Placeholder workflow and misleading operational labels. |
| Total | 9 / 20 | Provisional; preserve the design and fix the operational gaps. |

Impeccable's detector reported four font warnings and one empty lightbox-image source. The font warnings are not a reason to change the brand: Inter/Montserrat are incumbent choices. The lightbox source is populated by `openImageLightbox()` before opening, so it is not evidence of a visible broken image. Neither is counted as a confirmed defect above.

Visual refinement direction: retain the logo, dark shell, green active navigation, sidebar groupings, table-first content and recognizable panel names. Use calmer heading weights, consistent spacing, right-aligned monetary values, clearer table actions, truthful status pills, a less cramped toolbar and responsive navigation. Avoid introducing a new dashboard template, gratuitous charts or decorative motion.

Suggested UI passes after functionality: `$impeccable harden` for state/error handling, `$impeccable clarify` for search and metric labels, `$impeccable adapt` for mobile/zoom, then `$impeccable polish` for final consistency.

## Verification performed and limits

- `php -v`: PHP 8.2.12 CLI. Apache runtime has not been verified.
- `php -l src/Controllers/AdminController.php`: passes.
- `php -l views/pages/admin.php`: passes.
- `php tests/run.php`: FAIL, exit 1, 31 passed assertions and 13 suite errors; missing tables in the isolated `picklers_test` database. This runner can create/update its own test state and fixtures. It was not directed at the live database.
- UI detector: five warnings, reviewed as described above.
- Source inventory: controllers, routes, authentication, database bootstrap, tests, admin UI, shared UX utilities and theme/brand references inspected. No existing graph index was available; findings use direct source evidence.
- No authenticated browser session, manual admin mutation, load test, live financial reconciliation or full security review was performed. The supplied screenshot is the visual reference.
- Existing user edits were present in `app.css`, `style.css`, `Database.php`, `admin.php` and `home.php`; they were preserved. Only this audit and the accompanying prompt are deliverables of this turn.

“100% complete” must mean every agreed acceptance criterion has recorded evidence. Neither a prompt nor a passing subset of tests can guarantee that software has no bugs.
