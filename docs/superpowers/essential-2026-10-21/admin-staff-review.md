# Independent admin/staff slice review — 2026-10-21

Verdict: no actionable defects found in the scoped increments. Accept for local demonstration subject to completing the queued rendered staff regression. This is not an independent approval of the mixed-workload harness I authored.

## Reviewed changes

Compared the exact current files against `var/essential-20261021/admin-before` and `staff-before`, avoiding unrelated dirty-baseline changes. Traced AccountService request writes, PanelBuilder filter allow-list, AccountRepository bound SQL, OwnerPortalView permission context, OwnerScope and owner action authorization, and request-refresh callers.

- `src/Admin/Repository/AccountRepository.php:18` now admits the actual `pending_review` state written by AccountService; `PanelBuilder.php:62` uses this allow-list. Search still binds the verification filter as a parameter (`AccountRepository.php:100–102`), so no SQL interpolation or privilege change is introduced. Legacy `pending` remains available.
- `templates/admin/panels/_users.html.twig:25` exposes that state with the existing status label. Existing AdminExtension maps it to “Pending review.” This repairs discovery without altering review decisions.
- `tests/Admin/UsersTest.php:8–21` exercises a real rendered filtered request with inclusion/exclusion assertions and selected option, plus preservation of legacy option. Fixture setup reseeds each test, so the direct state mutation does not contaminate the next case.
- `templates/web/partials/owner/_tab-dashboard.html.twig:271–284` removes Accept/Decline buttons only when existing `canManageFacility` is false. `OwnerPortalView.php:52` derives this from actual facility ownership or admin flag, so owning another facility does not grant review actions here. Staff retain the intended existing read-only request list.
- `templates/web/partials/owner/_scripts.html.twig:6` emits a boolean via JSON encoding. `public/assets/js/owner.js:308` suppresses an API the current staff role cannot use. This is a UI/request suppression guard; backend OwnerActions owner/facility checks still enforce authority. Editing the JS flag cannot grant permission.
- `tests/Web/StaffCourtPermissionsTest.php:22–35` checks rendered staff denial/explanation/flag, direct API denial and owner action preservation. It relies on the existing fixture pending request; the owner positive assertion prevents the staff no-buttons assertion from passing solely because the queue is empty.
- `tests/Frontend/staff-request-queue.test.js` demonstrates false suppresses fetch and true retains it. Existing absent-flag behavior is unchanged; no need to broaden this into a second permission model.

## Evidence and limits

Fresh independent command: `node --test tests/Frontend/staff-request-queue.test.js` — 1 test passed, 0 failed. Read supplied root red/green evidence claim (admin 1 test/5 assertions) as root-owned verification, not my independent DB execution. Did not run PHPUnit because another worker owns the shared rebuilt test database; the rendered staff regression is pending that slot. No application edits made during review.

Optional follow-up only if scope grows: add explicit admin dashboard-action and owner-as-staff-at-another-venue assertions for this request list. Existing canManageFacility construction and unchanged backend boundaries already cover the implementation logic; these extra tests are not a blocker for this small local-demo slice.

Skipped: live browser interaction, independent DB execution and changes outside the named backups.
Risk: staff request lists remain snapshots until page reload because staff cannot poll the owner-only endpoint; this preserves existing read authority and does not promise real-time staff updates.
