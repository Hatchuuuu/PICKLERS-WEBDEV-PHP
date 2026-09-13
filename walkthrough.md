# Court Real-Time Occupancy & Availability Walkthrough

## Summary of Changes

### 1. Global Timezone Alignment (`Asia/Manila`)
- **Problem**: Default system/PHP timezone evaluated as UTC (03:24 AM instead of 11:24 AM local time), causing live booking minutes (`nowMin`) to mismatch with local booking time slots (11:00 AM – 12:00 PM).
- **Solution**: Set `date_default_timezone_set('Asia/Manila');` globally in [`src/Core/Autoloader.php`](file:///c:/xampp/htdocs/PICKLERS%20WEBDEV%20PROJECT/src/Core/Autoloader.php) so that all PHP time functions (`time()`, `date()`) evaluate in local time (`Asia/Manila`).

### 2. Live Countdown Timer Active Session Card
- When the booking time arrives (e.g. 11:00 AM – 12:00 PM, at current local time 11:24 AM):
  - Court 1 automatically flips to **`Occupied`** by Ignacio Reyes.
  - Dynamically calculates exact remaining time (e.g. `35:36` / `59:00` / ~36 mins left until 12:00 PM).
  - Ticks down live second-by-second on the Owner Dashboard with progress bar and "End Session Early" button.

### 3. Court Schedule Dropdown (`Schedule ▾` / Dropdown Button)
- Added a `Schedule ▾` dropdown button to every court header on the Owner Dashboard (`_tab-dashboard.php`) and My Courts (`_tab-courts.php`).
- Clicking `Schedule ▾` toggles a popover listing:
  - **Upcoming Bookings**: e.g., `Ignacio Reyes (11:00 AM – 12:00 PM)`
  - **Completed Today**: e.g., `Dante Reyes (7:00 AM – 8:00 AM)`

### 4. Owner Application Status Pill Styling
- **Updates**: Styled the `Status: Pending Review` badge in [`views/pages/owner-application.php`](file:///c:/xampp/htdocs/PICKLERS%20WEBDEV%20PROJECT/views/pages/owner-application.php) to feature:
  - Clean `1px solid #FFFFFF` border
  - `#FFFFFF` white text
  - Completely transparent background (`background: transparent`), removing the background glass/tint
### 5. Facility Operating Hours Line Styling
- **Updates**: Aligned the facility operating hours line (`6am - 10pm` / `24/hrs`) in [`views/partials/app/_tab-play.php`](file:///c:/xampp/htdocs/PICKLERS%20WEBDEV%20PROJECT/views/partials/app/_tab-play.php) and [`public/assets/js/app.js`](file:///c:/xampp/htdocs/PICKLERS%20WEBDEV%20PROJECT/public/assets/js/app.js) with the transit details (`🛵 4 min · 🚗 8 min`) style:
  - Text color updated to `#94A3B8` (muted slate blue)
  - Font weight set to normal (`400`)
### 6. Real-Time Closed Operating Hours Court Disabling
- **Updates**: Implemented operating hours validation (`isFacilityOpen`) in [`public/assets/js/app.js`](file:///c:/xampp/htdocs/PICKLERS%20WEBDEV%20PROJECT/public/assets/js/app.js) and [`src/Core/Database.php`](file:///c:/xampp/htdocs/PICKLERS%20WEBDEV%20PROJECT/src/Core/Database.php):
  - When current time surpasses facility closing time (e.g. past 10:00 PM for `6am - 10pm` or `06:00 AM – 10:00 PM` facilities):
    - All court cards become visually grayed out (`opacity: 0.55`, gray background, `cursor: not-allowed`).
    - The "Book Now" buttons are replaced with a disabled **"Closed"** button (`background: #334155`, `color: #94A3B8`).
    - Status dots switch to a grayed indicator (`#64748B`).
    - Clicking any court card or Quick Book displays a warning toast explaining the facility operating hours.
    - Added real-time 30-second live check interval to update court availability as time advances.
### 7. Removal of "Verify Identity" Card
- **Updates**: Removed the "Verify Identity" card item from [`views/partials/app/_tab-settings.php`](file:///c:/xampp/htdocs/PICKLERS%20WEBDEV%20PROJECT/views/partials/app/_tab-settings.php) as requested.

### 8. Booking Card Layout & Typography Overhaul
- **Updates**: Redesigned booking history receipt cards in [`views/partials/app/_tab-bookings.php`](file:///c:/xampp/htdocs/PICKLERS%20WEBDEV%20PROJECT/views/partials/app/_tab-bookings.php):
  - Cleaned up repetitive title prefix (changed `Book - Court 3` to clean `Court 3`).
  - Added sleek pill type tags (`COURT RESERVATION` / `HOSTED OPEN PLAY`).
  - Introduced a subtle glass divider line (`rgba(255,255,255,0.07)`).
  - Structured location (`📍 Incredoball Sports Center`) and schedule (`📅 Date` • `🕒 Time`) into a clean two-line metadata layout with status pill badges (`COMPLETED`, `CONFIRMED`, `PENDING`, `CANCELLED`).

### 9. Recurring Open Play Session Rollover & Dynamic Scheduling
- **Problem**: When local time surpassed an Everyday Open Play session's end time (e.g. past 11:00 PM for `8:00 AM – 11:00 PM` sessions), `isMatchExpired()` evaluated the match as expired and removed it from Explore. Consequently, Everyday matches vanished late at night until midnight.
- **Solution**:
  - Implemented `getMatchTargetDate()` and `getMatchDisplayDate()` in [`src/Core/Database.php`](file:///c:/xampp/htdocs/PICKLERS%20WEBDEV%20PROJECT/src/Core/Database.php).
  - Everyday / Daily recurring matches never expire permanently. When today's end time passes (e.g. at 11:00 PM), `getMatchTargetDate()` automatically rolls the session over to **Tomorrow** (`date('Y-m-d', strtotime('+1 day'))`).
  - Formats date labels in Explore to display `Everyday • Tomorrow` with a highlighted green `<strong style="color: #00D98B;">Tomorrow</strong>` badge.
  - Scopes player counts (`countActiveMatchBookings`), duplicate join checks, capacity gating, and owner roster views (`getOpenPlayRoster`) specifically to the active target date, resetting the spots counter to `0` of `N` spots filled for tomorrow's session.
### 10. Wallet & Credits Real-Time Transaction Ledger Integration
- **Problem**: In the Wallet tab (`app.php?tab=wallet`), accounts with initial credit balances (e.g. `₱2,500` for `dar@gmail.com`) displayed `"No Transactions Yet"` because seed/initial user balances lacked corresponding records in the `wallet_transactions` table/JSON.
- **Solution**:
  - Enhanced `getWalletTransactions()` in [`src/Core/Database.php`](file:///c:/xampp/htdocs/PICKLERS%20WEBDEV%20PROJECT/src/Core/Database.php):
    - Automatically synthesizes and records an initial deposit transaction (e.g. `+₱2,500` `GCash Top-Up`) for accounts with positive credit balances lacking top-up entries.
    - Compiles all user court reservations and Open Play join transactions into the unified transaction ledger.
    - Orders all transactions chronologically (`created_at DESC`).
  - Refined transaction item rendering in [`views/partials/app/_tab-wallet.php`](file:///c:/xampp/htdocs/PICKLERS%20WEBDEV%20PROJECT/views/partials/app/_tab-wallet.php):
    - Displays clean facility names for court bookings and open plays (e.g. `Booked • A-Courts`, `Booked • Incredoball`, `Open Play • Cebu IT Park`).
    - Extracts canonical booking codes (e.g. `#PKL-1139DA`, `#PKL-AD5B07`).
    - Works seamlessly across all filter pills (`All`, `Top-Ups`, `Bookings`, `Refunds`).

### 11. Bookings Sub-Navigation Stacked Count & White Text Styling
- **Updates**: Redesigned sub-navigation tab filter buttons (`Upcoming`, `Completed`, `Refunds`, `Cancelled`) in [`views/partials/app/_tab-bookings.php`](file:///c:/xampp/htdocs/PICKLERS%20WEBDEV%20PROJECT/views/partials/app/_tab-bookings.php) and [`public/assets/css/app.css`](file:///c:/xampp/htdocs/PICKLERS%20WEBDEV%20PROJECT/public/assets/css/app.css):
  - Stacked badge notification counts vertically above text labels (e.g. `4` over `Upcoming`).
  - Standardized label typography to bold white text (`#FFFFFF`) across active and inactive states.
  - Retained glowing emerald green indicator bar (`#00D98B`) and highlighted badge for active tabs.

### 12. Removal of Redundant "Player App" Switcher Item
- **Updates**: Removed the redundant "Player App [ACTIVE]" row item from the `PORTAL & CONSOLE SWITCHER` section in [`views/partials/app/_tab-settings.php`](file:///c:/xampp/htdocs/PICKLERS%20WEBDEV%20PROJECT/views/partials/app/_tab-settings.php) since the user is already inside the active Player App session.

### 13. Avatar Camera Badge Icon Color Update
- **Updates**: Styled `.settings-avatar-camera-btn` in [`public/assets/css/app.css`](file:///c:/xampp/htdocs/PICKLERS%20WEBDEV%20PROJECT/public/assets/css/app.css) with a professional dark navy background (`#11233D`), an emerald accent ring (`#00D98B`), crisp white camera icon (`#FFFFFF`), and a glowing emerald hover effect (`#00D98B`).

### 14. Admin Partner Application Inspector & Document Lightbox
- **Updates**: Built a comprehensive Application Inspector Modal and Image Lightbox in [`views/pages/admin.php`](file:///c:/xampp/htdocs/PICKLERS%20WEBDEV%20PROJECT/views/pages/admin.php):
  - **Documents & Details Inspection**: Admin can click any application row or the `🔍 View Details & Photos` button to view complete applicant information (Legal Name, Business Email, Contact Phone, Registered Entity Name, DTI/SEC Reg No., Facility Specs, Operating Hours, Map Coordinates).
  - **Verification Photos & Document Viewer**: Displays live image previews for Mayor's Permit / Business License (`permit_file`) and Government Issued ID (`gov_id_file`).
  - **Full-Resolution Image Lightbox**: Admins can click any permit or ID image preview to zoom into a high-resolution, backdrop-blurred lightbox viewer (`#appImageLightboxModal`) for thorough verification.
  - **Direct Actions**: Admins can approve or reject pending applications directly within the inspector modal.

### 15. Dynamic Host Open Play Session Date Picker Fix
- **Problem**: The Host Open Play modal date picker (`#openPlayDateInput`) in the Court Owner Portal (`owner.php?tab=courts`) was hardcoded to start from `2026-09-07` (`Mon, Sep 7, 2026`), causing past dates to be presented when hosting new sessions.
- **Solution**:
  - Replaced hardcoded base date in [`views/partials/owner/_modals.php`](file:///c:/xampp/htdocs/PICKLERS%20WEBDEV%20PROJECT/views/partials/owner/_modals.php) with `new DateTime('now')`.
  - Added `populateHostOpenPlayDates()` in [`public/assets/js/owner.js`](file:///c:/xampp/htdocs/PICKLERS%20WEBDEV%20PROJECT/public/assets/js/owner.js) to dynamically populate date select options on modal launch starting from Today (e.g. `Sun, Sep 13, 2026 (Today)`), followed by Tomorrow (`Mon, Sep 14, 2026`), and subsequent 30 future dates.
  - Updated JS submit fallbacks to use dynamic today date string.

### 16. Removal of Host Line on Open Play Match Cards
- **Updates**: Removed the `👤 Host: {Name}` row element from Open Play match cards in [`views/partials/app/_tab-explore.php`](file:///c:/xampp/htdocs/PICKLERS%20WEBDEV%20PROJECT/views/partials/app/_tab-explore.php) and [`views/pages/home.php`](file:///c:/xampp/htdocs/PICKLERS%20WEBDEV%20PROJECT/views/pages/home.php).
- **Layout**: Cleaned up the match details section so price tags (`₱180 your share`) align seamlessly on the right side of the card without clutter.

### 17. Full Formatted Date Display for Open Play Sessions
- **Problem**: Open Play cards displayed relative strings like `Everyday • Tomorrow` instead of the actual scheduled session date.
- **Solution**:
  - Updated `Database::getMatchDisplayDate()` in [`src/Core/Database.php`](file:///c:/xampp/htdocs/PICKLERS%20WEBDEV%20PROJECT/src/Core/Database.php) to evaluate target dates and return full, standard formatted date strings (e.g. `September 14, 2026`).
  - Simplified date rendering in [`views/partials/app/_tab-explore.php`](file:///c:/xampp/htdocs/PICKLERS%20WEBDEV%20PROJECT/views/partials/app/_tab-explore.php) and [`views/pages/home.php`](file:///c:/xampp/htdocs/PICKLERS%20WEBDEV%20PROJECT/views/pages/home.php) to display `📅 September 14, 2026` cleanly across all matches.

---

## Verification Results

### Automated Unit Tests
Executed PHP test suite (`php tests/run.php`):
```text
PICKLERS test suite (database: picklers_test)
==========================================================
  CacheInvalidationTest         9 passed
  CourtAttributesTest          16 passed
  EndCourtSessionEarlyTest     13 passed
  FacilityFavoritesTest         8 passed
  FacilityOperatingHoursTest    5 passed
  FacilityPayoutSettingsTest    9 passed
  LoginThrottleTest            14 passed
  OpenPlayAndSettingsSyncTest   8 passed
  OpenPlayRolloverTest         11 passed
  OwnerProvisioningTest        18 passed
  PasswordChangeTest            6 passed
  PricingServiceTest           36 passed
  SessionEndedAlertTest         9 passed
  SlotCollisionTest            30 passed
==========================================================
OK — 192 assertions passed in 722ms
```
- **100% (192/192) unit tests passed cleanly with zero warnings**.

---

## 18. Open Play Card Price Layout & Vertical Gap Fix

- **Flex Layout Integration**: Wrapped `openplay-details-list` and `openplay-price-wrap` inside a side-by-side flex container (`display: flex; justify-content: space-between; align-items: flex-end; margin-top: 14px;`) across both [`_tab-explore.php`](file:///c:/xampp/htdocs/PICKLERS%20WEBDEV%20PROJECT/views/partials/app/_tab-explore.php#L70-L99) and [`home.php`](file:///c:/xampp/htdocs/PICKLERS%20WEBDEV%20PROJECT/views/pages/home.php#L323-L344).
- **Closed Vertical Gap**: Moved the `₱180 your share` price tag up onto the right side of the details list, eliminating the empty line gap left behind after removing the `Host:` label.
- **Verification**: Verified PHP syntax with `php -l` and confirmed all 192 unit tests pass.

---

## 19. Booking Sub-Tab Badge Number Repositioning

- **Parenthetical Inline Formatting**: Formatted tab label counts cleanly as `Upcoming (0)` in [`_tab-bookings.php`](file:///c:/xampp/htdocs/PICKLERS%20WEBDEV%20PROJECT/views/partials/app/_tab-bookings.php#L105-L130).
- **Removed Circle Glass Outline**: Stripped away background circles, borders, and glass shadows from `.booking-badge-count` in [`app.css`](file:///c:/xampp/htdocs/PICKLERS%20WEBDEV%20PROJECT/public/assets/css/app.css#L6185-L6270), rendering clean text numbers alongside tab labels.
- **Verification**: Passed all 192 unit tests and 0 PHP syntax errors.

---

## 20. Facility Card Rating & Transit Line Alignment

- **Side-by-Side Flex Layout**: Updated facility cards in [`_tab-play.php`](file:///c:/xampp/htdocs/PICKLERS%20WEBDEV%20PROJECT/views/partials/app/_tab-play.php#L108-L118), [`app.js`](file:///c:/xampp/htdocs/PICKLERS%20WEBDEV%20PROJECT/public/assets/js/app.js#L708-L718), and [`home.php`](file:///c:/xampp/htdocs/PICKLERS%20WEBDEV%20PROJECT/views/pages/home.php#L266-L276) to combine transit info (`🛵 6 min · 🚗 12 min`) and rating/reviews (`★ 4.8 (98 reviews)`) into a single row (`display: flex; justify-content: space-between; align-items: center;`).
- **Aligned to Right End**: Positioned the rating and review counts on the right side end of the transit line.
- **Verification**: Verified syntax with `php -l` and ran full test suite with 192/192 assertions passing.

---

## 21. Court Card Schedule Dropdown Layout & Alignment

- **Removed Schedule Glass Box**: Updated the `Schedule ▾` dropdown button inline styles in [`_tab-dashboard.php`](file:///c:/xampp/htdocs/PICKLERS%20WEBDEV%20PROJECT/views/partials/owner/_tab-dashboard.php#L174-L215) from translucent box background/border (`background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.12)`) to clean borderless text (`background:transparent; border:none;`).
- **Right-Side End Alignment**: Positioned `Schedule ▾` at the right-side end of `court-card-header-row` (`display:flex; justify-content:space-between; align-items:center;`), matching the alignment of `Players ▾` on Open Play cards.
- **Removed Green Status Dot**: Removed `<span class="court-status-dot-green">` from court card headers in [`_tab-dashboard.php`](file:///c:/xampp/htdocs/PICKLERS%20WEBDEV%20PROJECT/views/partials/owner/_tab-dashboard.php#L205-L215) and set `display: none !important;` on `.court-status-dot-green` in [`owner.css`](file:///c:/xampp/htdocs/PICKLERS%20WEBDEV%20PROJECT/public/assets/css/owner.css#L1473-L1477).
- **Verification**: Verified PHP syntax with `php -l` and confirmed all 192 unit tests pass.

---

## 22. Consistent Horizontal Court Title Alignment Across Cards

- **Unified Header Baseline**: Updated `court-card-header-row` in [`_tab-dashboard.php`](file:///c:/xampp/htdocs/PICKLERS%20WEBDEV%20PROJECT/views/partials/owner/_tab-dashboard.php#L174-L205) to ensure every court card (Court 1, Court 2, Court 3) renders a right-aligned action trigger (`Schedule ▾` or `Players ▾`) with zero extra vertical padding (`padding: 0; line-height: 1; min-height: 20px;`).
- **Eliminated Vertical Displacement**: Removed vertical text shift so court titles (`Court 3`, `Court 1`, `Court 2`) sit on the exact same horizontal top alignment line.
- **Verification**: Verified PHP syntax with `php -l` and confirmed all 192 unit tests pass.

---

## 23. Court Card Header Vertical Position Adjustment

- **Adjusted Header UP**: Reduced top padding of `.live-court-card-v2` in [`owner.css`](file:///c:/xampp/htdocs/PICKLERS%20WEBDEV%20PROJECT/public/assets/css/owner.css#L1427-L1438) from `16px` to `12px` (`padding: 12px 16px 16px;`).
- **Higher Vertical Position**: Shifted the entire header row (`Court 3` title and `Schedule ▾` trigger) UP closer to the top border of the court card.
- **Verification**: Passed all 192 unit tests with 0 PHP syntax errors.
