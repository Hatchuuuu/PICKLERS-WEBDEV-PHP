<?php
declare(strict_types=1);
/**
 * Owner Portal - Facility Settings View
 * @var array $currentFacility
 * @var array $staffMembers
 */
?>
<!-- Topbar Header -->
<div class="owner-topbar" style="margin-bottom:28px;">
  <div class="owner-topbar-title-wrap">
    <h1 class="owner-page-title">Facility Settings</h1>
    <p class="owner-page-subtitle">Configure facility details, operating hours, and payouts.</p>
  </div>
</div>

<form id="ownerSettingsForm" onsubmit="saveFacilitySettings(event)" style="margin-bottom:40px;">
  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? \Picklers\Middleware\CsrfMiddleware::getToken()); ?>">
  <div class="owner-settings-grid">
    
    <!-- LEFT COLUMN: Identity & Operating Schedule -->
    <div class="owner-settings-col">
      
      <!-- SECTION 1: FACILITY PROFILE & BRANDING -->
      <div class="settings-section-block">
        <div class="settings-section-header">
          <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#10B981" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
          <span>FACILITY PROFILE &amp; BRANDING</span>
        </div>
        
        <div class="settings-card">
          <!-- Logo Uploader Row -->
          <div class="logo-upload-card-row">
            <div class="brand-logo-preview-wrap">
              <div class="brand-logo-preview" id="brandLogoPreview">
                <span><?php echo htmlspecialchars(strtoupper(substr($currentFacility['name'] ?? 'INC', 0, 3))); ?></span>
              </div>
              <button type="button" class="btn-logo-camera-overlay" onclick="document.getElementById('logoFileInput').click()" title="Change Brand Logo">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z"/><circle cx="12" cy="13" r="3"/></svg>
              </button>
            </div>
            <input type="file" id="logoFileInput" accept="image/*" style="display:none;" onchange="handleLogoUpload(this)">
            
            <div style="flex:1;">
              <div style="font-size:15px; font-weight:800; color:#FFFFFF;">
                <span>Facility Brand Logo</span>
              </div>
              <div style="font-size:12px; color:var(--pk-text-muted, #94A3B8); margin-top:2px;">Displayed on court booking cards, player receipts &amp; open play match feeds.</div>
            </div>
          </div>

          <!-- Facility Name Field -->
          <div class="form-group-field">
            <label class="settings-input-label">Facility Name</label>
            <div class="input-with-icon-wrap">
              <svg class="input-prefix-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#94A3B8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z"/><path d="M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2"/><path d="M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2"/><path d="M10 6h4"/><path d="M10 10h4"/><path d="M10 14h4"/><path d="M10 18h4"/></svg>
              <input type="text" id="facilityNameInput" class="settings-input-box with-icon" value="<?php echo htmlspecialchars($currentFacility['name'] ?? 'Incredoball Sports Center'); ?>" placeholder="Enter official facility name">
            </div>
          </div>

          <!-- Location with GPS auto-locate button -->
          <div class="form-group-field">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
              <label class="settings-input-label" style="margin-bottom:0;">Facility Location</label>
              <button type="button" class="btn-auto-gps" onclick="autoLocateGps()">
                <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg>
                <span>Auto-Locate GPS</span>
              </button>
            </div>
            <div class="location-input-wrap">
              <svg class="location-pin-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10B981" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
              <input type="text" id="facilityLocationInput" class="settings-input-box with-icon" value="<?php echo htmlspecialchars($currentFacility['location'] ?? 'Barangay Daro, Dumaguete City'); ?>" placeholder="Enter location address or coordinates">
            </div>
          </div>



        </div>
      </div>

      <!-- SECTION 2: OPERATING HOURS & SCHEDULE -->
      <div class="settings-section-block">
        <div class="settings-section-header">
          <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#F59E0B" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
          <span>OPERATING HOURS &amp; SCHEDULE</span>
        </div>
        
        <div class="settings-card">
          <div class="operating-hours-header-row">
            <div style="display:flex; align-items:flex-start; gap:14px;">
              <div class="icon-circle-badge amber" style="margin-top:2px;">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#F59E0B" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/></svg>
              </div>
              <div>
                <span style="font-size:15px; font-weight:800; color:#FFFFFF;">Open 24 Hours</span>
                <div style="font-size:11px; color:var(--pk-text-muted, #94A3B8); margin-top:1px;">Enable round-the-clock court booking availability for players</div>
              </div>
            </div>
            <?php
              // update_facility_settings() saves 'hours' as either the literal
              // string 'Open 24 Hours' or "{open} – {close}" (see
              // saveFacilitySettings() in owner.js) — this reads it back so the
              // form shows what was actually saved instead of always resetting
              // to the hardcoded 06:00 AM/10:00 PM defaults on every page load.
              $savedHours = trim((string)($currentFacility['hours'] ?? ''));
              $isOpen24 = strcasecmp($savedHours, 'Open 24 Hours') === 0;
              $openTimeVal = '06:00 AM';
              $closeTimeVal = '10:00 PM';
              if (!$isOpen24 && $savedHours !== '') {
                  $hourParts = preg_split('/\s*[–-]\s*/u', $savedHours);
                  if (count($hourParts) === 2) {
                      $openTimeVal = trim($hourParts[0]);
                      $closeTimeVal = trim($hourParts[1]);
                  }
              }
            ?>
            <label class="toggle-availability" style="margin-top:2px;">
              <input type="checkbox" id="toggleOpen24" onchange="toggleHoursVisibility()" <?= $isOpen24 ? 'checked' : '' ?>>
              <span class="toggle-slider"></span>
            </label>
          </div>

          <div class="hours-grid-inputs" id="hoursGridInputs">
            <div>
              <label class="settings-input-label">Opening Time</label>
              <div class="time-select-card">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10B981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                <input type="text" id="openingTimeInput" class="time-input-box" value="<?= htmlspecialchars($openTimeVal) ?>" placeholder="06:00 AM">
              </div>
            </div>
            <div>
              <label class="settings-input-label">Closing Time</label>
              <div class="time-select-card">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#F43F5E" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                <input type="text" id="closingTimeInput" class="time-input-box" value="<?= htmlspecialchars($closeTimeVal) ?>" placeholder="10:00 PM">
              </div>
            </div>
          </div>
        </div>
      </div>
      <?php if ($isOpen24): ?>
      <script>
        document.addEventListener('DOMContentLoaded', function () {
          if (typeof toggleHoursVisibility === 'function') toggleHoursVisibility();
        });
      </script>
      <?php endif; ?>

    </div>

    <!-- RIGHT COLUMN: Payment Methods & System Preferences -->
    <div class="owner-settings-col">
      
      <!-- SECTION 3: PAYMENT METHODS & PAYOUTS -->
      <div class="settings-section-block">
        <div class="settings-section-header">
          <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#00E5FF" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
          <span>PAYMENT &amp; PAYOUT DISBURSEMENT CHANNELS</span>
        </div>
        
        <div class="settings-card" style="display:flex; flex-direction:column; gap:24px;">
          
          <!-- 1. GCash Payouts -->
          <div id="gcashPayoutSection" class="payout-channel-card">
            <div class="payout-card-header">
              <div style="display:flex; align-items:flex-start; gap:14px;">
                <div class="gcash-logo-badge">
                  <img src="assets/images/gcash.svg" alt="GCash" style="width:100%; height:100%; object-fit:contain; display:block;">
                </div>
                <div>
                  <div style="font-size:15px; font-weight:800; color:#FFFFFF; line-height:1.2;">
                    GCash Payouts
                  </div>
                  <div style="margin-top:4px;">
                    <span id="gcashStatusBadge" class="payout-status-badge verified">
                      <span id="gcashStatusText">Linked &amp; Verified ✓</span>
                    </span>
                  </div>
                  <div style="font-size:11px; color:var(--pk-text-muted, #94A3B8); margin-top:3px;">Primary e-wallet disbursement channel</div>
                </div>
              </div>
              <label class="toggle-availability">
                <input type="checkbox" id="gcashToggle" <?= (($currentFacility['gcash_enabled'] ?? 1) ? 'checked' : '') ?>>
                <span class="toggle-slider"></span>
              </label>
            </div>

            <!-- GCash Number Input & Inline Send/Verify Action -->
            <div class="form-group-field" style="margin-top:14px;">
              <label class="settings-input-label" style="margin-bottom:6px;">GCash Mobile Number</label>
              <div class="payout-input-inline-wrap" id="gcashInputWrap">
                <input type="tel" id="gcashNumberInput" class="payout-inline-input" value="<?= htmlspecialchars((string)($currentFacility['gcash_number'] ?? '')) ?>" maxlength="11" placeholder="09XXXXXXXXX" oninput="onPayoutNumberChange('gcash')">
                <input type="text" id="gcashOtpCodeInput" class="payout-inline-input otp-code" maxlength="6" value="" placeholder="Enter 6-digit OTP" style="display:none;">
                <button type="button" class="btn-payout-inline-action" id="btnGcashOtpAction" onclick="handlePayoutInlineOtp('gcash')">
                  <span>Send</span>
                </button>
              </div>
            </div>
          </div>

          <hr style="border:none; border-top:1px solid rgba(255,255,255,0.08); margin:6px 0;">

          <!-- 2. Maya Payouts -->
          <div id="mayaPayoutSection" class="payout-channel-card">
            <div class="payout-card-header">
              <div style="display:flex; align-items:flex-start; gap:14px;">
                <div class="maya-logo-badge">
                  <img src="assets/images/maya.svg" alt="Maya" style="width:100%; height:100%; object-fit:contain; display:block;">
                </div>
                <div>
                  <div style="font-size:15px; font-weight:800; color:#FFFFFF; line-height:1.2;">
                    Maya Payouts
                  </div>
                  <div style="margin-top:4px;">
                    <span id="mayaStatusBadge" class="payout-status-badge verified">
                      <span id="mayaStatusText">Linked &amp; Verified ✓</span>
                    </span>
                  </div>
                  <div style="font-size:11px; color:var(--pk-text-muted, #94A3B8); margin-top:3px;">Direct wallet &amp; merchant payouts</div>
                </div>
              </div>
              <label class="toggle-availability">
                <input type="checkbox" id="mayaToggle" <?= (($currentFacility['maya_enabled'] ?? 1) ? 'checked' : '') ?>>
                <span class="toggle-slider"></span>
              </label>
            </div>

            <!-- Maya Number Input & Inline Send/Verify Action -->
            <div class="form-group-field" style="margin-top:14px;">
              <label class="settings-input-label" style="margin-bottom:6px;">Maya Mobile / Account Number</label>
              <div class="payout-input-inline-wrap" id="mayaInputWrap">
                <input type="tel" id="mayaNumberInput" class="payout-inline-input" value="<?= htmlspecialchars((string)($currentFacility['maya_number'] ?? '')) ?>" maxlength="11" placeholder="09XXXXXXXXX" oninput="onPayoutNumberChange('maya')">
                <input type="text" id="mayaOtpCodeInput" class="payout-inline-input otp-code" maxlength="6" value="" placeholder="Enter 6-digit OTP" style="display:none;">
                <button type="button" class="btn-payout-inline-action" id="btnMayaOtpAction" onclick="handlePayoutInlineOtp('maya')">
                  <span>Send</span>
                </button>
              </div>
            </div>
          </div>

          <hr style="border:none; border-top:1px solid rgba(255,255,255,0.08); margin:6px 0;">

          <!-- 3. Cash on Site -->
          <div style="display:flex; justify-content:space-between; align-items:center;">
            <div style="display:flex; align-items:center; gap:14px;">
              <div class="icon-circle-badge green">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#10B981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4Z"/></svg>
              </div>
              <div>
                <span style="font-size:15px; font-weight:800; color:#FFFFFF;">Cash on Site</span>
                <div style="font-size:12px; color:var(--pk-text-muted, #94A3B8); margin-top:2px;">Accept cash payments at court front desk</div>
              </div>
            </div>
            <label class="toggle-availability">
              <input type="checkbox" id="cashOnSiteToggle" <?= (($currentFacility['cash_on_site'] ?? 1) ? 'checked' : '') ?>>
              <span class="toggle-slider"></span>
            </label>
          </div>

        </div>
      </div>

      <!-- SECTION 4: SYSTEM PREFERENCES & ALERTS -->
      <div class="settings-section-block">
        <div class="settings-section-header">
          <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#94A3B8" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.38a2 2 0 0 0-.73-2.73l-.15-.1a2 2 0 0 1-1-1.72v-.51a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>
          <span>SYSTEM PREFERENCES &amp; ALERTS</span>
        </div>
        
        <div class="settings-card" style="display:flex; flex-direction:column; gap:16px;">
          <!-- Dark Mode -->
          <div style="display:flex; justify-content:space-between; align-items:center;">
            <div style="display:flex; align-items:center; gap:14px;">
              <div class="icon-circle-badge">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#94A3B8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></svg>
              </div>
              <div>
                <span style="font-size:15px; font-weight:800; color:#FFFFFF;">Dark Mode</span>
                <div style="font-size:11px; color:var(--pk-text-muted, #94A3B8); margin-top:1px;">High-contrast dark theme portal interface</div>
              </div>
            </div>
            <label class="toggle-availability">
              <input type="checkbox" id="ownerThemeToggleCheckbox" checked onchange="toggleTheme(this.checked)">
              <span class="toggle-slider"></span>
            </label>
          </div>

          <hr style="border:none; border-top:1px solid rgba(255,255,255,0.06); margin:0;">

          <!-- Sound Alerts -->
          <div style="display:flex; justify-content:space-between; align-items:center;">
            <div style="display:flex; align-items:center; gap:14px;">
              <div class="icon-circle-badge cyan">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#00E5FF" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/></svg>
              </div>
              <div>
                <span style="font-size:15px; font-weight:800; color:#FFFFFF;">Booking Sound Chimes</span>
                <div style="font-size:11px; color:var(--pk-text-muted, #94A3B8); margin-top:1px;">Audio sound effect when players scan QR code</div>
              </div>
            </div>
            <label class="toggle-availability">
              <input type="checkbox" id="soundAlertsToggle" checked onchange="showToast('Booking sound alert toggled.')">
              <span class="toggle-slider"></span>
            </label>
          </div>

          <hr style="border:none; border-top:1px solid rgba(255,255,255,0.06); margin:0;">

          <!-- Instant SMS Payout Alerts -->
          <div style="display:flex; justify-content:space-between; align-items:center;">
            <div style="display:flex; align-items:center; gap:14px;">
              <div class="icon-circle-badge green">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#10B981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
              </div>
              <div>
                <span style="font-size:15px; font-weight:800; color:#FFFFFF;">Payout SMS Alerts</span>
                <div style="font-size:11px; color:var(--pk-text-muted, #94A3B8); margin-top:1px;">Instant SMS notification on completed disbursements</div>
              </div>
            </div>
            <label class="toggle-availability">
              <input type="checkbox" id="smsPayoutAlertsToggle" checked onchange="showToast('SMS Payout notifications saved.')">
              <span class="toggle-slider"></span>
            </label>
          </div>

        </div>
      </div>

    </div>

  </div>

  <!-- Bottom Form Save Bar (Fixed for Mobile / Bottom of Form) -->
  <div class="settings-form-bottom-bar">
    <div style="display:flex; align-items:center; gap:10px;">
      <span style="font-size:13px; font-weight:700; color:#94A3B8;">Ensure all facility information and payout details are up to date.</span>
    </div>
    <button type="submit" class="btn-save-settings" id="btnSaveFacilitySettings" style="width:auto; min-width:200px; margin-top:0;">
      <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="margin-right:6px;"><polyline points="20 6 9 17 4 12"/></svg>
      <span>Save Changes</span>
    </button>
  </div>
</form>

