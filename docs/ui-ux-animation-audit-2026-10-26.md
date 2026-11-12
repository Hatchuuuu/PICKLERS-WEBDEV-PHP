# PICKLERS UI/UX and animation audit — October 26, 2026

## A. Executive summary

This pass improves the existing Symfony/Twig application without replacing its visual identity, routes, backend contracts, or frontend stack. The most important repairs address shared dialog lifecycle and keyboard behavior, misleading application GPS feedback, required uploads, unnamed controls, and missing empty-state feedback. The user's subsequent requests also align booking actions to the right, restore the header avatar ring, remove the green Court Reservation pill decoration, correct clipped Open Play filter glows, and improve the support/privacy/terms dialog contents.

The existing emerald/cyan palette, typography, navigation, booking functionality, restrained dialog entrance, avatar ring animation, and useful loading feedback were retained. No dependency was installed and no commit was made.

## B. Baseline, scope, and coverage

- The checkout already contained extensive tracked and untracked changes. Nothing was reset, stashed, or discarded. Starting tracked differences and status are saved in ignored `var/ui-ux-audit-2026-10-26/baseline.patch` and `baseline-status.txt`. Individual before-files are saved there for the shared assets and most touched templates. The original owner application can be reconstructed from HEAD plus the baseline patch; it was not individually copied before editing. These checkpoints are not database backups.
- Graphify queries guided source navigation; `graphify-out/wiki/index.md` was absent. The actual stack is Symfony 7.4/Twig with static CSS/JavaScript, not React/Tailwind/shadcn. No frontend build is necessary.
- The requested Emil, Impeccable, UI/UX Pro Max, UI Styling, Improve Animations, and Review Animations guidance was used. Systematic Debugging, regression-first verification, Graphify, and Ponytail guidance informed implementation. The user's explicit instruction to implement fixes takes precedence over the animation advisor's report-only default.
- Browser testing used the existing isolated `scripts/e2e-router.php` on loopback port 8099, targeting `picklers_e2e` and synthetic fixture accounts. No real payment, personal document, account deletion, or admin mutation was performed.
- Baseline frontend suite: **123 tests passed**.

| Area | Actual coverage | Limits |
| --- | --- | --- |
| Landing | Source review of navigation, facilities, Open Play, FAQ, counters, reveal/marquee behavior; desktop browser baseline | No physical-device performance profiling; FAQ growth at arbitrary zoom was source-verified rather than exhaustively measured |
| Authentication | Source, existing frontend and PHPUnit coverage; synthetic browser sign-in/sign-out | No external identity provider or recovery service exists to verify |
| Player Play | Mobile/desktop discovery and loaded court detail; Quick Book opening/focus/viewport fit; search semantics | No new reservation submitted during this UI pass |
| Explore | Browser-confirmed empty initial page, corrected empty state, All/Beginner filter interaction and glow | Session join and owner approval covered by automated suites rather than a new browser transaction |
| Wallet | Mobile rendering, transaction list, existing payment tests | No real-money gateway; top-ups remain unavailable |
| Bookings | Mobile/768px tablet rendering, confirmed pass dialog, right-aligned actions | No cancellation executed for visual testing |
| Settings | Mobile rendering, dark/light toggle via visible switch, labels and support/policy entry points | No password, personal avatar, or account deletion submitted |
| Owner | All five navigation sections rendered in the browser at 1280px; dashboard income action activated with Enter; source review of earnings, inbox, controls, requests and settings | No staff grants, payouts, camera scan, messages, or facility edits submitted; owner mobile touch behavior not exhaustively exercised |
| Owner application/onboarding | Source and executable frontend regressions for GPS, uploads, wizard focus and dialog behavior; actual Twig lint | No physical file picker/upload submission or location permission request |
| Tournament | Source review of search keyboard behavior, zoom/close names, score labels, reduced motion and cleanup; existing suites | Bracket gestures and frame pacing not visually profiled |
| Admin | Source review of navigation, searches, menus and dialogs plus complete functional suite | Closed mobile navigation visibility fix has not been manually exercised with a physical keyboard in an admin browser session |
| Error/permission states | Template/controller coverage and existing functional suites | No claim of a complete screen-reader or WCAG certification audit |

## C–D. Confirmed findings and implemented solutions

Locations refer to the named components; line numbers move as templates are edited. Tests and reproduction steps provide stable references.

| Priority / decision | Before | After | Why / evidence / verification |
| --- | --- | --- | --- |
| P1 FIX | Shared dialog close callbacks could hide a reopened instance; removing it from the stack early also released keyboard containment | Retain stack membership through exit, cancel stale close completion on reopening, ignore repeated close, and guard deferred opening focus | `ux-core.js`; failing-then-passing lifecycle tests reproduce rapid close/reopen and close-before-opening-focus |
| P1 FIX | Closing a lower dialog left higher dialogs pointing back into hidden content | Redirect return-focus chains past the closed dialog and avoid background-dialog focus stealing | A reviewer reproduced nested and out-of-order closing; new regression verifies focus reaches the original page trigger |
| P1 FIX | Player legacy Escape/backdrop listeners directly hid overlays, bypassing busy guards and stack cleanup | Legacy handlers defer when shared UX is present; fallback remains available without UX | `app.js`; regression executes both shared and fallback branches |
| P1 FIX | Application GPS failure fabricated coordinates/address and showed success | Preserve existing values and explain permission/unavailable failure; initially empty coordinates stay empty; measured coordinates remain usable if reverse geocoding fails | `owner-application.html.twig`; executable denial, unavailable, and network-failure tests |
| P1 FIX | Required application document controls were mouse-only divs | Native labelled buttons retain file-picker and drag/drop behavior | Template and executable structural regressions; existing document constraints preserved |
| P2 FIX | Wizard focus remained in the previous step; application overlays bypassed shared dialog mechanics | Focus the updated heading; use shared notice/success lifecycle and viewport-bounded scrollable cards | Application regressions verify step change and shared runtime use; submitted success still requires real server success |
| P2 FIX | Owner inbox rows and income action lacked keyboard activation | Enter/Space activation with default scrolling prevented | Portal regressions execute handlers; income dialog additionally opened with Enter in the browser |
| P2 FIX | Facility/court searches, owner reply, and five player preference checkboxes lacked persistent names | Explicit names match the visible controls | Portal regressions and browser accessibility inspection |
| P2 IMPROVE | Active player/owner destination was indicated only by styling | Conditional `aria-current="page"` reuses the existing selected-tab state | Four navigation partials; portal regressions preserve all five destinations |
| P2 FIX | An empty Explore route initially rendered no explanation | Show its existing empty state on the first server render when no matches exist | Browser reproduction and real Twig test with zero and one match; subsequent filtering behavior retained |
| P2 FIX | Five JavaScript scroll calls ignored reduced-motion preference | Explicit instant scrolling for reduced motion, normal smooth scrolling otherwise | Player motion tests exercise every scroll option under both preferences |
| P2 IMPROVE | Shared dialog exits accelerated slowly with ease-in | Existing ease-out token and 120ms exit; preserve 260ms entrance | Source motion review; existing token scale reused |
| P2 FIX | Expanded FAQ content had a fixed 200px cap | Natural answer height; existing hidden/expanded semantics and chevron retained | Fixed cap/overflow removed at its source; existing FAQ interaction regressions pass |
| P2 FIX | Forced-color mode could suppress the shadow-only focus ring | System-color outline in forced colors | Scoped CSS media query; source verified, native high-contrast mode not exercised |
| P2 FIX | Closed mobile admin navigation was translated away but its links remained keyboard eligible | Visibility follows the existing transform transition and becomes visible immediately on opening | Source review; no route or drawer-event changes |
| User refinement | Wrapped booking action row began at the left | Auto start margin aligns View Pass/Cancel to the card's right inner edge | Mobile browser measurement: action and footer right edges match |
| User refinement | Header avatar clipped its outer gradient ring and inherited an oversized old glow | Visible wrapper overflow and remove obsolete 160% dimensions | Red/green avatar regression and mobile screenshot; gradient colors and animation retained |
| User refinement | Court Reservation label had an unwanted green pill | Transparent court-label background/border in upcoming/completed cards | Keep label and Open Play distinction; booking/status behavior unchanged |
| User refinement | Open Play selected-pill glow was clipped by its horizontal scroller | Add vertical/horizontal breathing room, compensate margins to preserve content positions | Mobile All/Beginner verification; retain horizontal scrolling |
| User refinement | Help was sparse; privacy/terms were dense and included unsupported claims | Readable 14px sections, one scroll body, persistent title/Close action, real Bookings/Wallet links, accurate support/payment/retention disclosures | All three panels verified in mobile dark/light; scoped desktop checks; five new content/semantics regressions |

The animation reviewer approved the final shared lifecycle repairs after running all eight dialog tests. This is approval of the scoped source changes and runtime regressions, not a physical-device frame-rate certification.

## E. Files modified by this pass

Pre-existing changes outside this list belong to the starting checkout, not this audit.

- `public/assets/js/ux-core.js`: shared closing/opening/focus lifecycle.
- `public/assets/js/app.js`: shared dismissal delegation and motion-aware scrolls.
- `public/assets/css/ux-core.css`: responsive exit timing/easing.
- `public/assets/css/style.css`: remove the FAQ height cap.
- `public/assets/css/theme.css`: forced-colors focus outline.
- `public/assets/css/admin.css`: closed mobile navigation visibility.
- `public/assets/css/app.css`: avatar clipping, Open Play glow spacing, scoped support/policy presentation.
- `templates/web/owner-application.html.twig`: truthful GPS, native upload controls, step focus and shared dialogs.
- `templates/web/partials/app/_sidebar.html.twig`, `_bottom-nav.html.twig`: current-page semantics.
- `templates/web/partials/app/_tab-play.html.twig`, `_tab-explore.html.twig`, `_tab-settings.html.twig`: accessible names, initial empty state, support/policy summaries.
- `templates/web/partials/app/_tab-bookings.html.twig`: action alignment and requested badge refinement.
- `templates/web/partials/app/_modals.html.twig`: professional support/privacy/terms content and named close controls.
- `templates/web/partials/owner/_sidebar.html.twig`, `_bottom-nav.html.twig`: current-page semantics.
- `templates/web/partials/owner/_tab-messages.html.twig`, `_tab-dashboard.html.twig`, `_tab-courts.html.twig`: keyboard activation and names.
- `tests/Frontend/ux-dialog-keyboard.test.js`, `player-motion.test.js`, `avatar-ring.test.js`, `owner-application-accessibility.test.js`, `portal-accessibility.test.js`, `settings-help-content.test.js`: targeted regressions.
- `tests/Web/PagesTest.php`: real Twig empty/nonempty Explore rendering.
- This report and generated Graphify outputs.

## F. Existing functionality preserved

No changes to authentication, CSRF, roles/capabilities, booking prices, approval rules, refunds, ledger writes, APIs or persistence were required. Existing payment option radios, native facility actions, tournament keyboard search and reduced-motion handling, linear spinners, landing brand effects, and useful avatar/dialog motion were retained. Uploaded-document limits and actual submission validation remain enforced.

## G. Testing results

Evidence is retained in ignored `var/ui-ux-audit-2026-10-26/`.

| Check | Result | Evidence / practical limit |
| --- | --- | --- |
| Baseline frontend | PASSED | 123 tests |
| Final frontend including policy content | PASSED | 150 tests, zero failures; `frontend-final.log` |
| PHP syntax | PASSED | 328 files in `verify.log` |
| Composer validation/platform/advisories | PASSED | Existing verifier, no dependency changes |
| JavaScript syntax | PASSED | Existing verifier app/owner checks; final targeted checks include shared UX |
| Symfony container lint | PASSED | dev, test and prod |
| Twig lint | PASSED | 59 templates in verifier plus subsequent changed-template checks |
| Routing / production warmup | PASSED | 48 routes and successful production warmup |
| Schema bootstrap | PASSED | Existing disposable fresh/stale-stamp scenarios |
| Service suite | PASSED | 406 assertions |
| PHPUnit | PASSED after stale-cache resolution | 311 tests / 2,421 assertions, `phpunit-final.log` |
| Explore focused functional suite | PASSED | 20 PagesTest tests / 168 assertions, `pages-final.log` |
| Shared dialog lifecycle | PASSED | Eight targeted tests; red logs retained for reproduced failures |
| Support/privacy/terms layout and copy | PASSED for sampled views | Three mobile panels in dark and light themes; 14px copy, named headings, contained focus, scrollable body and visible footer; desktop fit checked separately |
| Graphify refresh | PASSED with extraction warning | 3,037 nodes, 5,636 edges, 254 communities; 24 files produced zero nodes and are omitted (including JSON/config/data inputs), so graph coverage is incomplete |
| Responsive checks | PASSED for sampled views | Player 390/402px mobile, 768px tablet, 1440px desktop; owner 1280px desktop; not every route at every breakpoint |
| Browser warnings/errors | PASSED for observed preview | No captured warnings/errors at the check; this is not all possible network/backend states |
| Real devices / full screen reader / performance tracing | NOT TESTED | No physical touch hardware, assistive-technology session, or frame-time trace used |

The first broad verifier's PHPUnit run failed only the new Explore rendering assertion because it used a compiled template from before the edit. This failure is retained in `verify.log`, not hidden. Clearing test cache, rerunning PagesTest, and then rerunning all 311 PHPUnit tests passed. The expected fault-injection error messages in the suite are test scenarios, not additional failures.

## H. Remaining issues and limits

- **Release content:** no monitored support/privacy contact or operator-defined retention schedule was supplied. The privacy text must describe the current implementation honestly and must not be presented as completed legal certification. The [National Privacy Commission's notice guidance](https://privacy.gov.ph/the-right-to-be-informed/) identifies the information an operator should supply; these missing operational details cannot be invented by a UI pass.
- **Device hypotheses:** ungated hover movement can behave differently on touch devices. Broad removal was avoided because no physical-device defect was reproduced. Test the actual supported phones before changing working effects globally.
- **Coverage:** live financial writes, physical document upload, GPS permission prompts, staff/payout mutations, admin browser keyboard traversal, tournament gestures, full screen-reader output and real frame pacing remain outside the fresh manual verification.
- **Existing product boundaries:** external payment/refund settlement, social sign-in/recovery availability, and a monitored support channel were not added. The UI should communicate these boundaries accurately.
- **Graph coverage:** Graphify's update reported 24 files with zero extracted nodes. The graph is useful for navigation but is not proof of complete source/data coverage; see `graphify-update.log`.

## I. Readiness assessment

The implemented repairs have targeted regression evidence and broad application-suite coverage, and the sampled responsive UI retains the established identity. This is a completed scoped audit/repair pass with disclosed manual coverage limits. It is not a claim that every accessible role/action has been exercised on every device, or that the app is legally certified or ready for an unrestricted public launch.

## J. Continuation pass — interaction state and remaining mobile coverage

The user requested continuation of the master prompt. This pass resumed the existing implementation instead of redesigning or repeating the original work. Before-files and red/green evidence are retained in `var/ui-ux-audit-2026-10-26/pass2/`.

### Findings and repairs

| Priority | Before | After | Why / evidence |
| --- | --- | --- | --- |
| P1 | Older admin panel or record-detail responses could overwrite a newer selection, clear its loading indicator, or focus a dismissed dialog | Request generations and visible-dialog checks discard stale results | Deferred-response regressions exercise old success, old failure, dismissal, reopening and current-failure fallback |
| P2 | A dismissed Recent Activity popover could reappear when its request completed | Closing invalidates the request; only the current opening may render or reposition | Automated open/close/resolve and close/reopen cases |
| P1 | Mobile admin drawer allowed Tab into content behind the backdrop and retained the backdrop after switching to desktop | Trap forward/backward Tab, use the existing shared scroll lock, and close on desktop resize | Browser reproduced the focus leak, then confirmed focus stayed inside; resizing to 1280px hid the backdrop and released the lock |
| P1 | An owner's unsent draft remained in the field after switching recipients | Keep drafts per recipient and clear only the matching submitted raw draft | Regression covers switching, successful sends, newer edits, and whitespace without sending real messages |
| P2 | Every chat poll scrolled to the newest message | Preserve reading position; follow the bottom only when already near it, switching threads, or completing the current recipient's send | Executable poll/switch/send regressions; no real conversation sent |
| P1 | Settings save completion marked newer unsaved payout values saved and erased a newly selected replacement logo | Acknowledge the submitted snapshot, retain modified state, and clear only the actual uploaded file selection | Deferred-save regressions preserve newer typed values and logo selections |
| P1 | Main settings form and payout buttons could start overlapping writes | Shared save guard returns a clear busy result while the first operation is pending | Duplicate-request and retry-after-completion regressions; no payout or settings mutation performed in the browser |
| P2 | Enter on an earlier application step checked hidden document fields | Route early submission through the existing next-step validation | Real browser Enter advanced Step 1→2 and Step 2→3 and focused the visible heading; application was not submitted |
| P2 | Facility certification was selected by default; contact help promised unsupported instant SMS | Start certification unchecked and describe a reachable contact number | Regression verifies initial markup; existing submission checks retained |
| P2 | Owner header repeated the avatar clipping defect already fixed in the player header | Apply the same visible-overflow and bounded-glow correction | Shared avatar regression now checks both stylesheets |
| P1/P2 | Champion reveal did not lock scrolling or restore focus, and closing the final-match editor could move focus behind it | Keep focus in the remaining dialog, unlock on final dismissal, and find the replacement match control after bracket rendering | Three red-before-green regressions exercise manual champion reveal, final-match reveal and replaced-card restoration |

The independent review approved the chat and tournament changes, identified the overlapping-save gap, and prompted the shared save guard. Existing motion, payment rules, authentication, backend validation and API contracts remain unchanged.

### Additional coverage and verification

- All ten admin sections were rendered at **390px**. Wide record tables remain inside their existing `.pk-table-scroll` containers; the document's raw scroll-width measurement alone was rejected as a defect because table ancestors correctly contain the overflow.
- All five owner navigation sections were rendered at **390px and 768px** with no unexpected page overflow, correct bottom-navigation current-page state and preserved content.
- Admin drawer keyboard containment, background scroll lock and resize cleanup were checked in the browser after the repair.
- Application keyboard progression was checked through the document step using synthetic text only. No documents were selected, no certification was accepted, and no application was submitted.
- The browser preview server stopped when the previous turn was interrupted. It was restarted using the existing isolated e2e router with process-local debug disabled. This is an environment interruption, not an application regression.
- This pass used a separate `localhost` fixture session to avoid changing the earlier `127.0.0.1` preview login. Observed browser error/warning logs were empty at the recorded check.
- Fresh focused PHPUnit verification passed **37 tests / 310 assertions** (`phpunit-focused.log`). The previous **311-test / 2,421-assertion** full-suite result remains historical evidence from the first pass; it is not represented as a new full run here.
- Final continuation frontend verification passed **171 tests**, JavaScript syntax passed for admin/owner/tournament, and all **59 Twig templates** linted successfully. The owner avatar check covers its base and mobile override; browser confirmation measured `overflow: visible` and the intended 42px glow at a 390px viewport. The initial base-only check missed the mobile override; the stronger regression failed before that final correction.
- Final frontend, syntax, Twig and Graphify results for this continuation are recorded in the pass2 logs. Runtime race tests use deferred local promises to reproduce timing deterministically; browser network throttling was not used.

### Files added or extended by the continuation

`public/assets/js/admin.js`, `public/assets/js/owner.js`, `public/assets/js/tournament.js`, `public/assets/css/owner.css`, `templates/web/owner-application.html.twig`, `tests/Frontend/admin-interaction-state.test.js`, `owner-settings-save-state.test.js`, `owner-chat-state.test.js`, `owner-application-accessibility.test.js`, `tournament-dialog-state.test.js`, and `avatar-ring.test.js`.

The remaining limits are physical touch/hardware, assistive-technology sessions, frame-time profiling, real uploads and financial/provider transactions, plus operator-supplied support/privacy contact and retention details. The continuation closes concrete interaction defects and expands mobile coverage; it does not certify those untested operational areas.

## K. Owner notification panel refinement

At the user's request, the owner notification dialog now uses a separated inbox list instead of repeated outlined cards. Removed glowing unread dots and decorative title emoji; reduced icon weight; made timestamps secondary; increased message text to 14px with comfortable line height; retained the emerald identity and existing message content. The header now has a clear “Mark all read” action and a labelled 44px Close control. Both initial and post-deletion empty states use the same themed SVG/message treatment.

The existing delete action is now a labelled native button with a small visible trash control. Source inspection found no swipe handler attached in the owner portal, so retaining a hidden swipe-only action would leave mouse users without access; the player app's separate swipe behavior is unchanged. No new deletion endpoint or optimistic persistence behavior was introduced. Read/delete handlers and notification IDs are preserved. Removed the fixed 220px row cap so longer messages are not clipped; the existing removal handler still measures the row before collapsing it.

Changed `public/assets/css/owner.css`, `templates/web/partials/owner/_modals.html.twig`, and only the empty-state markup in `public/assets/js/owner.js`. Backups and screenshots are in `var/notification-polish-2026-10-26/`. Verified the 390px header/panel fit and desktop light/dark theme colors, contained scrolling, focus and unchanged unread count. Light-mode generic card borders/shadows required a scoped override to retain the flat list presentation.

Verification: all 171 frontend tests passed; the three notification persistence/swipe checks and changed Twig lint also passed. No actual notification was marked read or deleted during visual checks. Physical touch and screen-reader testing remain unperformed. Graphify refreshed with its existing 24 zero-node-file warning.
