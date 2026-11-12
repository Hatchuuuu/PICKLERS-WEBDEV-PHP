# Facility Hours Enforcement Implementation Plan

> **For agentic workers:** Use executing-plans for the approved bounded slice; preserve the existing dirty working tree and coordinate shared test DB/UI files with lead.

**Goal:** Prevent new bookings and Open Play publication/joins outside parseable configured facility hours, with truthful availability.

**Architecture:** Reuse Schedule time-range parsing and BookingRules facility/court boundary. Keep legacy behavior for missing/malformed hours. Do not cancel or invalidate existing paid reservations automatically. Quote is pricing only and currently receives no time/date.

**Scope:** Schedule.php pure containment helper; BookingRules.php optional time-aware blockReason; BookingRepository.php forwarding/create/availability plus adjacent-day collision windows; OpenPlayRepository.php join; OwnerPortalActions.php host; focused pure/unit/integration tests. UI agent coordinates app.js API-derived picker times. Configured hours expose 24 civil-day hourly slots; malformed/empty retain legacy 15-slot grid. Single spanning-midnight picker selection remains documented limitation even though server ranges and collision checks support it. No schema/provider change.

- [x] Save per-file baseline copies and grep every changed caller.
- [x] Add pure failing regressions: opening/closing inclusive slot containment, before/after, whole-range containment, overnight late/early and crossing midnight, same endpoints/full-day, malformed/empty legacy behavior.
- [x] Implement minimum Schedule helper with existing parser; normalize overnight candidate intervals with ±1440 offset comparison. 17-case pure self-check passes; red/green logs saved.
- [x] Extend shared blockReason with optional time, querying current facility hours under existing transaction facility shared lock. Keep no-time callers behavior identical.
- [x] Forward time at court create, Open Play join and host publication, including host recheck inside transaction. Leave approval unaffected so changed hours do not strand existing paid reservations.
- [x] Filter availability slot flags with same pure helper; preserve legacy 07:00–22:00 defaults for missing/malformed configuration. Configured hours produce full civil-day grid; API contract sent to UI writer (frontend integration owned separately).
- [x] Reuse adjacent-day booking window query for creation and availability, offset canonical dates onto selected day's timeline and parse nullable legacy intervals. Test both overlap directions, touching endpoints, cancelled exclusion and legacy nullable Open Play interval shape.
- [x] Add disposable integration tests for blocked mutation/no debit, same-boundary success, stale settings change, and Open Play host/join rejection. Exclusive DB slot used and released.
- [ ] Run focused pure tests, integration tests, frontend booking regressions and syntax checks. Obtain independent review; update graph once coordinated with lead.

Rollback: restore only assigned file changes using the saved var/essential-20261021 baseline copies; no database migration or historical booking rewrite.

Evidence: flow-api-red.log records8 expected failing regressions; flow-api-green.log records10tests84assert; flow-focused-suites.log records28tests229assert across ApiTest, FacilityHoursTest, DiscoverTest. Standalone facility-hours-pure-red/green.log records missing-method red then17-case green. Final frontend integration, independent hours review and combined graph update remain owned by lead/UI reviewer.
