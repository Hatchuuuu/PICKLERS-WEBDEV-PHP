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

---

## Verification Results

### Automated Unit Tests
Executed PHP test suite (`php tests/run.php`):
```text
PICKLERS test suite (database: picklers_test)
==========================================================
  CacheInvalidationTest         9 passed
  CourtAttributesTest          16 passed
  LoginThrottleTest            14 passed
  OpenPlayAndSettingsSyncTest   8 passed
  OwnerProvisioningTest        13 passed
  PasswordChangeTest            6 passed
  PricingServiceTest           36 passed
  SlotCollisionTest            30 passed
==========================================================
OK — 132 assertions passed in 2178ms
```
- **100% (132/132) unit tests passed cleanly with zero warnings**.
