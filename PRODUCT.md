# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Two primary user types, both real accounts on a real-money marketplace:

1. **Players** — pickleball players in the Philippines who search for facilities, book courts by the hour, join community "Open Play" pickup sessions, hold a Pickle Credits wallet, track bookings, message facilities, and join tournaments.
2. **Facility Owners/Operators** — people who run a physical pickleball court facility and want to list it on Picklers to receive real bookings, manage court availability and staff, host Open Play sessions and tournaments, track earnings/payouts, and message players. An owner must apply and be approved by a human Picklers admin before gaining Owner Portal access — this redesign target (`views/pages/owner-application.php`) is that application.

## Product Purpose

Picklers is a live, real-money marketplace connecting pickleball players with facility owners in the Philippines. It removes the friction of finding and booking an available court, joining pickup "Open Play" games, and organizing tournaments, while giving facility owners a dashboard for scheduling, payments/payouts, staff, and their public listing.

## Positioning

Real-time court booking plus open-play matchmaking plus full owner-side operations (staff, earnings, tournaments, messaging) in one platform built specifically for the Philippine pickleball scene — not a generic scheduling tool.

## Operating Context

- This surface is the Court Owner Onboarding application wizard: three steps (Facility Details & Location → Business & Contact → Permits & ID), submitted by an already-logged-in Player account requesting elevation to Owner.
- Submission is **not** instant. It creates a `pending_review` application row that a human Picklers admin must approve in the Admin console before `is_owner` is set and a real facility record is provisioned. This is a real identity/licensing verification gate for a marketplace where real money moves through GCash/Maya and an internal wallet — not a cosmetic formality.
- The wizard requires two real uploaded documents (Mayor's Permit/Business License, a government-issued photo ID) plus real business-identity fields (facility name, address, legal entity name, DTI/SEC registration number, owner name/email/phone).
- Earlier this same engagement, every one of those fields shipped with a specific fake value pre-filled as its default (e.g. "BGC Pickleball Arena", "Marcus Vance", "SEC-CS2026-98124"), which trivially satisfied HTML5 `required` without the applicant typing anything, and the success modal claimed instant "Verified & Role Elevated" approval regardless of what the server actually returned — including on a real rejection or a dead network connection. That has been fixed at the code level (fields now start genuinely empty, both documents are actually required, the server rejects incomplete/undocumented submissions instead of inventing placeholder data, and the success/error UI now reflects the real server response). This redesign must preserve every part of that fix — it must not reintroduce a pre-filled "example" value in place of a real one, and must not resurrect an unconditional success state.

## Capabilities and Constraints

- Standalone, self-contained PHP page: its own inline `<style>`/`<script>`, no shared app.js/owner.js bundle and no shared toast/notification system available here (verify before assuming one exists if the redesign restructures the file).
- Client-side 3-step wizard with per-step `required`-field validation before advancing, GPS auto-detect for the address field (browser Geolocation API), and drag-and-drop upload for the two documents.
- Server-side handler: `OwnerController::submitApplication()` — CSRF-protected, requires real authentication, blocks a second submission while one is already `pending_review`, and (as of this engagement) rejects the request outright with a real error message when any required field or document is missing, rather than substituting fabricated data.
- Must preserve exactly: the corrected required-field/document validation (including the explicit JS check for the two hidden file inputs, since a native browser validation tooltip cannot anchor to a `display:none` element), the corrected success/error handling (`data.success` is checked; a rejection or network failure shows a real inline error, never the success state), and the post-submit redirect target (`app.php?tab=settings`).
- No admin-review turnaround time is confirmed real yet (see Evidence on Hand) — do not state a specific SLA.

## Brand Commitments

Documented in `docs/PICKLERS_BRAND_GUIDE.md` and confirmed binding for this redesign (user chose "stay on-brand, elevate it" over a different visual language for this page):

- **Colors** — Mint Green `#55C39E` (primary brand color, primary CTA, active pills/tabs), Dark Navy `#0A121F` (primary app surface/background), Sky Blue `#69A2D0` (secondary accent, secondary CTA).
- **Typography** — "PICKLERS" wordmark: Montserrat ExtraBold (800/900), reserved exclusively for the brand name. Headings: Inter Variable, Bold/Semi-Bold. Body: Inter, Regular/Medium.
- Real logo assets exist: `public/assets/images/PICKLERS_OFFICIAL_LOGO.svg` and `PICKLERS_LOGO.png`.
- The rest of the product (player app, owner portal) already executes this system consistently in a dark-navy UI — this onboarding page should read as the same premium product, not a bolted-on generic form.

## Evidence on Hand

- Real, documented brand guide: `docs/PICKLERS_BRAND_GUIDE.md`.
- Real logo files: `public/assets/images/PICKLERS_OFFICIAL_LOGO.svg` (and PNG variant).
- The hero claim "over 15,000 active players in the Philippines" is confirmed real by the product owner — preserve it verbatim; do not edit, round, or remove it.
- No real facility-partner names, review counts, or admin-review turnaround time exist yet (confirmed by the product owner: "nothing real yet — keep generic"). Do not invent or imply any of these. A previously-added "usually within 1-2 business days" review-time claim was not confirmed real and must be genericized or removed.
- A real (non-placeholder) sample facility photo exists at `public/assets/images/facilities/overhead_dumaguete.jpg`, used elsewhere in the app as a fallback facility image.

## Product Principles

1. Never simulate legitimacy. Every verification step — documents, identity fields, admin review — must be real and enforced server-side. No cosmetic-only validation, no fabricated success state.
2. Real-time and real accounts, always. What the UI shows must reflect actual server state, never an optimistic fiction — the exact defect class repeatedly found and fixed elsewhere in this product this engagement (booking status, favorites, chat, payouts).
3. One brand across every surface. The onboarding wizard should feel like the same premium product as the rest of Picklers, executed at a higher level — not a separate, generic-looking form bolted onto it.
4. Don't fabricate specificity. No invented stats, partner names, or SLAs beyond what's confirmed real; genuinely unknown quantities stay generic rather than inventing a plausible-sounding number.

## Accessibility & Inclusion

No project-specific standard established yet.
