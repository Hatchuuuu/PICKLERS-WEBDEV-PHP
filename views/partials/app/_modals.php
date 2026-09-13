
  <!-- Confirm Booking Reservation Modal -->
  <div class="app-modal-overlay" id="confirmBookingModal">
    <div class="app-modal-box" style="max-width: 440px; border-radius: 24px; background: #0E1A2D; border: 1px solid rgba(255,255,255,0.12); box-shadow: 0 24px 50px rgba(0,0,0,0.6);">
      <div class="modal-head" style="padding: 18px 22px 14px; border-bottom: 1px solid rgba(255,255,255,0.08); display: flex; justify-content: space-between; align-items: center;">
        <div style="display: flex; align-items: center; gap: 10px;">
          <div style="width: 36px; height: 36px; border-radius: 10px; background: rgba(0,217,139,0.14); border: 1px solid rgba(0,217,139,0.3); display: flex; align-items: center; justify-content: center; color: #00D98B;">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg>
          </div>
          <div>
            <h3 class="modal-title" style="font-size: 17px; font-weight: 800; color: #FFFFFF; margin: 0;">Confirm Reservation</h3>
            <p style="font-size: 12px; color: #94A3B8; margin: 2px 0 0;">Review slot before proceeding to checkout</p>
          </div>
        </div>
        <button type="button" class="modal-close-btn" onclick="closeModal('confirmBookingModal')" aria-label="Close" style="width: 30px; height: 30px; min-width: 30px; min-height: 30px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.12); color: #FFFFFF; font-size: 14px; cursor: pointer;">✕</button>
      </div>

      <div class="modal-body" style="padding: 20px;">
        <!-- Clean Unified Professional Card (No Glass Box Artifacts) -->
        <div style="background: #132238; border: 1px solid rgba(255,255,255,0.1); border-radius: 18px; padding: 18px; margin-bottom: 20px;">
          <!-- Facility & Court Badge Row -->
          <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 14px; margin-bottom: 14px;">
            <div style="flex: 1; min-width: 0;">
              <div style="font-size: 10px; font-weight: 800; color: #94A3B8; text-transform: uppercase; letter-spacing: 0.5px;">FACILITY</div>
              <div id="confirmBookFacilityName" style="font-size: 15px; font-weight: 800; color: #FFFFFF; margin-top: 2px; line-height: 1.35; word-break: break-word;">Pickleball Facility</div>
            </div>
            <div id="confirmBookCourtName" style="font-size: 13px; font-weight: 800; color: #FFFFFF !important; background: none; border: none; padding: 0; margin: 0; white-space: nowrap; flex-shrink: 0; align-self: flex-start;">Court 1</div>
          </div>

          <!-- Interactive Date & Time Slot Selection (Consistent with QuickBook Modal) -->
          <div style="display: flex; flex-direction: column; gap: 10px;">
            <!-- Section: SELECT DATE -->
            <div class="qb-section-title" style="margin-top: 4px; margin-bottom: 6px;">
              <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg>
              <span>SELECT DATE</span>
            </div>

            <div class="qb-date-row" id="confirmDatePillsContainer" style="margin-bottom: 6px;">
              <?php
                $dtNow = new DateTime('now');
                for ($dIdx = 0; $dIdx < 14; $dIdx++):
                  $dObj = clone $dtNow;
                  if ($dIdx > 0) $dObj->modify("+{$dIdx} day");
                  $dFull = $dObj->format('D M j Y');
                  $dDay = strtoupper($dObj->format('D'));
                  $dNum = $dObj->format('j');
                  $mon = $dObj->format('M');
                  $dMon = ($mon === 'Sep') ? 'Sept' : $mon;
                  $dSub = $dMon . ' ' . $dNum;
                  $isActive = ($dIdx === 0);
              ?>
                <button type="button" class="qb-date-pill <?php echo $isActive ? 'active' : ''; ?>" onclick="selectConfirmBookingDate(this, '<?php echo $dFull; ?>')">
                  <span class="qb-date-day"><?php echo $dDay; ?></span>
                  <span class="qb-date-sub"><?php echo $dSub; ?></span>
                </button>
              <?php endfor; ?>
            </div>

            <!-- Section: TIME SLOTS -->
            <div class="qb-times-grid" style="margin-bottom: 6px;">
              <!-- START TIME Column -->
              <div class="qb-time-column" id="cbStartTimeCol" onwheel="handleConfirmTimeWheel(event, 'start')">
                <div class="qb-section-title" style="color: var(--pk-status-success); justify-content:center;">
                  <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                  <span>START TIME</span>
                </div>
                <div class="qb-time-slot-subtle" id="cbStartPrev" onclick="shiftConfirmStartTime(-1)">6:00 AM</div>
                <button type="button" class="qb-time-slot-active-start" id="cbStartActive" onclick="shiftConfirmStartTime(1)">7:00 AM</button>
                <div class="qb-time-slot-subtle" id="cbStartNext" onclick="shiftConfirmStartTime(1)">8:00 AM</div>
              </div>

              <!-- END TIME Column -->
              <div class="qb-time-column" id="cbEndTimeCol" onwheel="handleConfirmTimeWheel(event, 'end')">
                <div class="qb-section-title" style="color: var(--pk-status-error); justify-content:center;">
                  <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                  <span>END TIME</span>
                </div>
                <div class="qb-time-slot-subtle" id="cbEndPrev" onclick="shiftConfirmEndTime(-1)">11:00 AM</div>
                <button type="button" class="qb-time-slot-active-end" id="cbEndActive" onclick="shiftConfirmEndTime(1)">12:00 PM</button>
                <div class="qb-time-slot-subtle" id="cbEndNext" onclick="shiftConfirmEndTime(1)">1:00 PM</div>
              </div>
            </div>

            <!-- Summary Card -->
            <div class="qb-summary-card">
              <div style="display:flex; align-items:center; gap:12px;">
                <div style="width:34px; height:34px; border-radius:50%; background: var(--pk-status-success-bg); color: var(--pk-status-success); display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                  <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                </div>
                <div>
                  <div style="font-size:11px; font-weight:600;" class="modal-text-muted">Total Duration</div>
                  <div style="font-size:14px; font-weight:900; color:#FFFFFF; font-variant-numeric:tabular-nums;" id="cbDurationText">5 hours</div>
                </div>
              </div>
              <div style="text-align:right;">
                <div style="font-size:12px; font-weight:800; color: #FFFFFF !important; font-variant-numeric:tabular-nums;" id="cbSummaryDateText">Thu Sep 10 2026</div>
                <div style="font-size:14px; font-weight:900; color:#FFFFFF; font-variant-numeric:tabular-nums;" id="cbSummaryTimeRange">7:00 AM – 12:00 PM</div>
              </div>
            </div>
          </div>

          <div style="border-top: 1px solid rgba(255,255,255,0.08); margin: 14px 0;"></div>

          <!-- Payment Breakdown -->
          <div>
            <div style="font-size: 10px; font-weight: 800; color: #94A3B8; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 12px;">PAYMENT BREAKDOWN</div>
            
            <div style="display: flex; justify-content: space-between; align-items: center; font-size: 13px; color: #94A3B8; margin-bottom: 8px;">
              <span>Court Rental Fee</span>
              <span id="confirmBookCourtFee" style="font-size: 15px; font-weight: 900; color: #FFFFFF !important; font-variant-numeric: tabular-nums;">₱900</span>
            </div>

            <div style="display: flex; justify-content: space-between; align-items: center; font-size: 13px; color: #94A3B8; margin-bottom: 12px;">
              <span>Platform &amp; Service Fee (10%)</span>
              <span id="confirmBookPlatformFee" style="font-size: 15px; font-weight: 900; color: #FFFFFF !important; font-variant-numeric: tabular-nums;">₱90</span>
            </div>

            <div style="border-top: 1px solid rgba(255,255,255,0.08); padding-top: 10px; display: flex; justify-content: space-between; align-items: center;">
              <span style="font-size: 14px; font-weight: 800; color: #FFFFFF;">Total Amount Due</span>
              <span id="confirmBookTotalPrice" style="font-size: 20px; font-weight: 900; color: #FFFFFF !important; font-variant-numeric: tabular-nums;">₱990</span>
            </div>
            
            <div id="confirmBookPrice" style="display:none;"></div>
          </div>
          <div id="cbConflictNotice" style="display:none; margin-top: 10px;"></div>
        </div>
        </div>

        <!-- Modal Actions -->
        <div style="display: flex; gap: 10px;">
          <button type="button" class="btn-modal-cancel" onclick="closeModal('confirmBookingModal')" style="flex: 1; padding: 12px; font-size: 13px; font-weight: 700; border-radius: 12px; background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.12); color: #FFFFFF; cursor: pointer;">
            Cancel
          </button>
          <button type="button" id="btnConfirmProceedBooking" style="flex: 2; padding: 12px; font-size: 13.5px; font-weight: 800; border-radius: 12px; background: #00D98B; color: #FFFFFF !important; border: none; box-shadow: 0 4px 18px rgba(0, 217, 139, 0.35); cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 6px;">
            <span style="color: #FFFFFF !important;">Proceed to Payment</span>
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- QuickBookModal -->
  <div class="app-modal-overlay" id="quickBookModal">
    <div class="qb-modal-box">
      <div class="qb-drag-handle"></div>

      <div class="qb-header-row">
        <div>
          <h3 class="qb-title">QUICK BOOK</h3>
          <p class="qb-subtitle" id="qbFacilitySubtitle">South Metro Dinkers</p>
        </div>
        <button type="button" class="qb-close-btn" onclick="closeModal('quickBookModal')" aria-label="Close" style="width:32px;height:32px;min-width:32px;min-height:32px;max-width:32px;max-height:32px;border-radius:50%;padding:0;display:inline-flex;align-items:center;justify-content:center;aspect-ratio:1/1;flex:0 0 32px;flex-shrink:0;">
          <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:block;pointer-events:none;"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>

      <!-- Step 1: Config View (Date & Time Picker) -->
      <div id="qbConfigView">
        <!-- Section: SELECT DATE -->
        <div class="qb-section-title">
          <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg>
          <span>SELECT DATE</span>
        </div>

        <div class="qb-date-row" id="qbDatePillsContainer">
          <?php
            $dtNow = new DateTime('now');
            for ($dIdx = 0; $dIdx < 14; $dIdx++):
              $dObj = clone $dtNow;
              if ($dIdx > 0) $dObj->modify("+{$dIdx} day");
              $dFull = $dObj->format('D M j Y');
              $dDay = strtoupper($dObj->format('D'));
              $dNum = $dObj->format('j');
              $mon = $dObj->format('M');
              $dMon = ($mon === 'Sep') ? 'Sept' : $mon;
              $dSub = $dMon . ' ' . $dNum;
              $dLabel = ($dIdx === 0) ? 'Today' : (($dIdx === 1) ? 'Tomorrow' : $dObj->format('l'));
              $isActive = ($dIdx === 0);
          ?>
            <button type="button" class="qb-date-pill <?php echo $isActive ? 'active' : ''; ?>" onclick="selectQuickBookDate(this, '<?php echo $dFull; ?>', '<?php echo $dLabel; ?>')">
              <span class="qb-date-day"><?php echo $dDay; ?></span>
              <span class="qb-date-sub"><?php echo $dSub; ?></span>
            </button>
          <?php endfor; ?>
        </div>

        <!-- Section: TIME SLOTS -->
        <div class="qb-times-grid">
          <!-- START TIME Column -->
          <div class="qb-time-column" id="qbStartTimeCol" onwheel="handleTimeWheel(event, 'start')">
            <div class="qb-section-title" style="color: var(--pk-status-success); justify-content:center;">
              <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
              <span>START TIME</span>
            </div>
            <div class="qb-time-slot-subtle" id="qbStartPrev" onclick="shiftStartTime(-1)">6:00 AM</div>
            <button type="button" class="qb-time-slot-active-start" id="qbStartActive" onclick="shiftStartTime(1)">7:00 AM</button>
            <div class="qb-time-slot-subtle" id="qbStartNext" onclick="shiftStartTime(1)">8:00 AM</div>
          </div>

          <!-- END TIME Column -->
          <div class="qb-time-column" id="qbEndTimeCol" onwheel="handleTimeWheel(event, 'end')">
            <div class="qb-section-title" style="color: var(--pk-status-error); justify-content:center;">
              <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
              <span>END TIME</span>
            </div>
            <div class="qb-time-slot-subtle" id="qbEndPrev" onclick="shiftEndTime(-1)">11:00 AM</div>
            <button type="button" class="qb-time-slot-active-end" id="qbEndActive" onclick="shiftEndTime(1)">12:00 PM</button>
            <div class="qb-time-slot-subtle" id="qbEndNext" onclick="shiftEndTime(1)">1:00 PM</div>
          </div>
        </div>

        <!-- Summary Card -->
        <div class="qb-summary-card">
          <div style="display:flex; align-items:center; gap:12px;">
            <div style="width:34px; height:34px; border-radius:50%; background: var(--pk-status-success-bg); color: var(--pk-status-success); display:flex; align-items:center; justify-content:center;">
              <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </div>
            <div>
              <div style="font-size:11px; font-weight:600;" class="modal-text-muted">Total Duration</div>
              <div style="font-size:14px; font-weight:900; color:#FFFFFF; font-variant-numeric:tabular-nums;" id="qbDurationText">5 hours</div>
            </div>
          </div>
          <div style="text-align:right;">
            <div style="font-size:12px; font-weight:800; color: #FFFFFF !important; font-variant-numeric:tabular-nums;" id="qbSummaryDateText">Thu Sep 10 2026</div>
            <div style="font-size:14px; font-weight:900; color: #FFFFFF !important; font-variant-numeric:tabular-nums;" id="qbSummaryTimeRange">7:00 AM – 12:00 PM</div>
          </div>
        </div>

        <!-- Real-Time Scanner HUD (Shows during availability scan) -->
        <div id="qbScannerHud" class="qb-scanner-hud" style="display:none;">
          <div class="qb-hud-radar">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--pk-accent-sky)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" x2="16.65" y1="21" y2="16.65"/></svg>
          </div>
          <div class="qb-hud-info">
            <div class="qb-hud-title" id="qbHudStatusText">Checking court availability...</div>
            <div class="qb-hud-sub" id="qbHudSubText">Fetching schedule &amp; court slots</div>
          </div>
          <div class="qb-hud-time" id="qbHudTime">
            <svg class="qb-spin-icon" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
          </div>
        </div>

        <!-- Find Available Courts Button -->
        <button type="button" id="btnQuickBookFindCourts" class="btn-find-courts-gradient" onclick="proceedToPaymentFromQuickBook()">
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" x2="16.65" y1="21" y2="16.65"/></svg>
          <span>Find Available Courts ›</span>
        </button>
      </div>

      <!-- Step 2: Scanned Available Courts Results View -->
      <div id="qbResultsView" class="qb-results-view" style="display:none; animation: qbHudFadeIn 0.3s cubic-bezier(0.16, 1, 0.3, 1) forwards;">
        <div class="qb-scanner-hud success" style="margin-bottom:16px;">
          <div class="qb-hud-radar">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
          </div>
          <div class="qb-hud-info">
            <div class="qb-hud-title" style="font-size:14px; font-weight:700;" class="modal-text-primary" id="qbResultsSummaryTitle">Available Courts</div>
            <div class="qb-hud-sub" style="font-size:12px;" class="modal-text-muted" id="qbResultsSummarySub">Thu Sep 10 2026 • 7:00 AM – 12:00 PM (5 hrs)</div>
          </div>
        </div>

        <div class="qb-section-title" style="color: var(--pk-status-success); font-size:11px; margin-bottom:10px; display:flex; align-items:center; gap:6px;">
          <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
          <span>SELECT COURT TO BOOK</span>
        </div>

        <!-- List of Scanned Available Courts -->
        <div id="qbAvailableCourtsList" class="qb-results-courts-list"></div>

        <!-- Back to Time & Date Selector -->
        <button type="button" class="qb-back-config-btn" onclick="backToQuickBookConfig()">
          <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
          <span>Adjust Date or Time</span>
        </button>
      </div>

      <!-- Hidden Tracking Fields -->
      <input type="hidden" id="qbSelectedFacilityId" value="1">
      <input type="hidden" id="qbSelectedCourtName" value="Court 2">
      <input type="hidden" id="qbSelectedCourtSurface" value="Hard">
      <input type="hidden" id="qbSelectedCourtType" value="Indoor">
      <input type="hidden" id="qbSelectedDate" value="Thu Sep 10 2026">
      <input type="hidden" id="qbSelectedDateFull" value="Thu Sep 10 2026">
      <input type="hidden" id="qbSelectedStartIdx" value="1">
      <input type="hidden" id="qbSelectedEndIdx" value="6">
      <input type="hidden" id="qbBaseHourlyRate" value="180">
    </div>
  </div>

  <!-- Official Booking Receipt Modal -->
  <div class="app-modal-overlay" id="bookingReceiptModal" onclick="if(event.target===this) closeBookingReceiptAndRedirect()">
    <div class="app-modal-box receipt-modal-box" style="max-width:420px; width:100%; border-radius:24px; background:#0E1A2D; border:1px solid rgba(255,255,255,0.14); box-shadow:0 24px 60px rgba(0,0,0,0.7); padding:20px 22px; box-sizing:border-box;">
      <!-- Top Modal Header with Close Button -->
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
        <div style="display:flex; align-items:center; gap:6px;">
          <span style="font-size:10px; font-weight:800; background:rgba(0,217,139,0.12); color:#00D98B; border:1px solid rgba(0,217,139,0.3); padding:3px 10px; border-radius:6px; letter-spacing:0.5px;">✓ REQUEST SUBMITTED</span>
        </div>
        <button type="button" class="modal-close-btn" onclick="closeBookingReceiptAndRedirect()" aria-label="Close" style="width:30px; height:30px; min-width:30px; min-height:30px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.15); color:#FFFFFF; font-size:14px; cursor:pointer; transition:all 0.2s;">✕</button>
      </div>

      <!-- Success Icon & Status Hero -->
      <div style="text-align:center; margin-bottom:14px;">
        <div class="receipt-success-badge" style="width:44px; height:44px; border-radius:50%; background:rgba(0,217,139,0.14); border:1px solid rgba(0,217,139,0.35); display:inline-flex; align-items:center; justify-content:center; color:#00D98B; margin-bottom:8px; box-shadow:0 0 18px rgba(0,217,139,0.3);">
          <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
        </div>
        <h3 style="font-size:18px; font-weight:800; margin:0 0 3px; color:#FFFFFF; letter-spacing:-0.01em;">Reservation Request Sent</h3>
        <div style="font-size:11.5px; color:#94A3B8; margin-bottom:6px;">Awaiting the facility's confirmation — we'll notify you the moment it's approved.</div>
        <div style="font-size:24px; font-weight:900; color:#FFFFFF; text-shadow:0 0 16px rgba(255,255,255,0.2);" id="receiptAmountPaid">₱518.00</div>
      </div>

      <!-- Receipt Ticket Container -->
      <div class="receipt-ticket-card" style="background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.08); border-radius:16px; padding:14px 16px; margin-bottom:16px;">
        <!-- Booking Code & Badge -->
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; flex-wrap:wrap; gap:6px;">
          <div>
            <div class="receipt-label">Booking Reference</div>
            <div class="receipt-code-badge" id="receiptRefCode">#PKL-8F92A1</div>
          </div>
          <div style="background:rgba(255,184,0,0.14); color:#FFB800; font-size:10px; font-weight:700; padding:4px 10px; border-radius:6px; border:1px solid rgba(255,184,0,0.3); letter-spacing:0.3px;">
            ⏳ PENDING CONFIRMATION
          </div>
        </div>

        <div style="border-top:1px dashed rgba(255,255,255,0.14); margin:10px 0 12px;"></div>

        <!-- Receipt Details Grid -->
        <div class="receipt-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:12px 14px;">
          <div class="receipt-cell">
            <span class="receipt-label">Facility</span>
            <span class="receipt-val" id="receiptFacilityName">South Metro Dinkers</span>
          </div>
          <div class="receipt-cell">
            <span class="receipt-label">Reserved Court</span>
            <span class="receipt-val" id="receiptCourtName">Court 2</span>
          </div>
          <div class="receipt-cell">
            <span class="receipt-label">Date &amp; Time</span>
            <span class="receipt-val" id="receiptDateTime">Today • 8:00 AM – 9:00 AM</span>
          </div>
          <div class="receipt-cell">
            <span class="receipt-label">Duration</span>
            <span class="receipt-val" id="receiptDuration">1 Hour</span>
          </div>
          <div class="receipt-cell">
            <span class="receipt-label">Payment Channel</span>
            <span class="receipt-val" id="receiptPaymentMethod">GCash</span>
          </div>
          <div class="receipt-cell">
            <span class="receipt-label">Issued Date</span>
            <span class="receipt-val" id="receiptIssuedTime">Sep 7, 2026</span>
          </div>
        </div>
      </div>

      <!-- Action Button -->
      <button type="button" class="btn-checkout-primary" onclick="closeBookingReceiptAndRedirect()" style="width:100%; display:flex; align-items:center; justify-content:center; gap:8px; padding:12px 18px; font-size:14px; font-weight:800; border-radius:12px; background:#00D98B; color:#FFFFFF !important; border:none; box-shadow:0 6px 20px rgba(0,217,139,0.35); cursor:pointer; transition:all 0.2s;">
        <span style="color:#FFFFFF !important;">Go to My Bookings</span>
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
      </button>
    </div>
  </div>

  <!-- QR Ticket Modal (Section 12.9) -->
  <div class="app-modal-overlay" id="qrPassModal">
    <div class="app-modal-box" style="text-align:center; max-width:420px; border-radius:24px; background:#0E1A2D; border:1px solid rgba(255,255,255,0.12); box-shadow:0 24px 50px rgba(0,0,0,0.6);">
      <div class="modal-head" style="padding:18px 22px 12px; border-bottom:1px solid rgba(255,255,255,0.06); display:flex; justify-content:space-between; align-items:center;">
        <h3 class="modal-title" style="font-size:17px; font-weight:800; color:#FFFFFF; margin:0;">Venue Check-In Pass</h3>
        <button type="button" class="modal-close-btn" onclick="closeModal('qrPassModal')" style="width:32px; height:32px; min-width:32px; min-height:32px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.15); color:#FFFFFF; font-size:15px; cursor:pointer; flex-shrink:0;">&#10005;</button>
      </div>
      <div class="modal-body" style="text-align:center; padding:22px;">
        <div style="margin-bottom: 14px;">
          <span id="qrPassTypeBadge" style="display:inline-flex; align-items:center; gap:5px; font-size:10px; font-weight:800; padding:4px 12px; border-radius:6px; letter-spacing:0.5px; text-transform:uppercase; background:rgba(0,217,139,0.12); color:#00D98B; border:1px solid rgba(0,217,139,0.3);">
            ✓ VERIFIED ACCESS PASS
          </span>
        </div>
        <div style="background: #FFFFFF; padding:16px; border-radius:18px; display:inline-block; margin-bottom:16px; box-shadow:0 10px 30px rgba(0,0,0,0.4);">
          <!-- Dynamic High-Res QR Pass Image -->
          <img id="qrPassImg" src="" alt="Court Pass QR Code" style="width:170px; height:170px; display:block; border-radius:8px; object-fit:contain;">
        </div>

        <div id="qrPassCode" style="font-family:monospace; font-size:18px; font-weight:900; color:#FFFFFF; letter-spacing:2px; margin-bottom:6px;">
          #PKL-8F92A1
        </div>
        <div id="qrPassDetails" style="font-size:12.5px; color:#94A3B8; line-height:1.45;">
          Present this digital QR pass at the facility reception for instant express entry.
        </div>
      </div>
    </div>
  </div>

  <!-- Wallet Top-Up Modal -->
  <div class="app-modal-overlay" id="topUpModal" data-current-balance="<?php echo (float)($currentUser['wallet_balance'] ?? 0); ?>">
    <div class="app-modal-box topup-modal-box" style="max-width: 420px; max-height: 90vh; overflow-y: auto; border-radius: 22px; padding: 20px; box-sizing: border-box; margin: auto;">
      <!-- Header -->
      <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
        <div style="display: flex; align-items: center; gap: 12px;">
          <div style="width: 38px; height: 38px; border-radius: 10px; background: var(--pk-status-success-bg); display: flex; align-items: center; justify-content: center; color: var(--pk-status-success); flex-shrink: 0;">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4Z"/></svg>
          </div>
          <div>
            <h3 style="font-family: 'Montserrat', var(--font-heading), -apple-system, BlinkMacSystemFont, sans-serif; font-size: 18px; font-weight: 800; color: var(--pk-text-primary); margin: 0 0 2px; letter-spacing: -0.015em;">Top Up Credits</h3>
            <p style="font-size: 12px; color: var(--pk-text-muted); margin: 0;">Add funds to your wallet</p>
          </div>
        </div>
        <button type="button" class="modal-close-btn" onclick="closeModal('topUpModal')" aria-label="Close" style="width: 30px; height: 30px; min-width: 30px; min-height: 30px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; background: transparent; border: none; color: var(--pk-text-muted); cursor: pointer; padding: 0;">
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>

      <!-- Select Amount Section -->
      <div style="margin-bottom: 14px;">
        <div style="font-size: 13px; font-weight: 700; color: var(--pk-text-primary); margin-bottom: 8px;">Select Amount</div>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 8px;">
          <button type="button" class="topup-amount-btn" onclick="selectTopUpOption(500, this)">
            <span>₱500</span>
          </button>
          <button type="button" class="topup-amount-btn active" onclick="selectTopUpOption(1000, this)">
            <span>₱1,000</span>
          </button>
          <button type="button" class="topup-amount-btn" onclick="selectTopUpOption(2000, this)">
            <span>₱2,000</span>
          </button>
          <button type="button" class="topup-amount-btn" onclick="selectTopUpOption(5000, this)">
            <span>₱5,000</span>
          </button>
        </div>

        <!-- Custom Amount Input (Single Search-Bar Style) -->
        <div style="position: relative; width: 100%;">
          <input type="number" id="topUpAmountInput" class="topup-custom-bar-input" placeholder="Custom Amount" min="1" max="50000" step="1" oninput="handleTopUpCustomInput(this.value)" onfocus="selectCustomAmountFocus()">
        </div>
      </div>

      <!-- Payment Method Section (Side-by-Side 2 Column Grid) -->
      <div style="margin-bottom: 14px;">
        <div style="font-size: 13px; font-weight: 700; color: var(--pk-text-primary); margin-bottom: 8px;">Payment Method</div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
          <!-- GCash Option (Selected) -->
          <div class="topup-method-card active" id="methodCard_GCash" onclick="selectTopUpMethod('GCash')" style="margin-bottom: 0; padding: 10px 10px;">
            <div style="display: flex; align-items: center; gap: 10px;">
              <div style="width: 32px; height: 32px; border-radius: 8px; background: var(--pk-text-primary); display: flex; align-items: center; justify-content: center; flex-shrink: 0; box-shadow: 0 2px 6px rgba(0,0,0,0.15); padding: 4px; box-sizing: border-box;">
                <!-- Official GCash Vector SVG Logo -->
                <img src="assets/images/gcash.svg" alt="GCash Official Logo" style="width: 100%; height: 100%; object-fit: contain; display: block;">
              </div>
              <div>
                <div style="font-size: 13.5px; font-weight: 800; color: var(--pk-text-primary); line-height: 1.2;">GCash</div>
                <div style="font-size: 11px; color: var(--pk-text-muted); font-weight: 500;">E-Wallet</div>
              </div>
            </div>
          </div>

          <!-- Maya Option -->
          <div class="topup-method-card" id="methodCard_Maya" onclick="selectTopUpMethod('Maya')" style="margin-bottom: 0; padding: 10px 10px;">
            <div style="display: flex; align-items: center; gap: 10px;">
              <div style="width: 32px; height: 32px; border-radius: 8px; background: var(--pk-bg-card); display: flex; align-items: center; justify-content: center; flex-shrink: 0; border: 1px solid rgba(255,255,255,0.12); padding: 5px 3px; box-sizing: border-box; box-shadow: 0 2px 6px rgba(0,0,0,0.25);">
                <!-- Official Maya Vector SVG Logo -->
                <img src="assets/images/maya.svg" alt="Maya Official Logo" style="width: 100%; height: 100%; object-fit: contain; display: block;">
              </div>
              <div>
                <div style="font-size: 13.5px; font-weight: 800; color: var(--pk-text-primary); line-height: 1.2;">Maya</div>
                <div style="font-size: 11px; color: var(--pk-text-muted); font-weight: 500;">E-Wallet</div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- 10% Platform Fee Summary Breakdown (All Values White) -->
      <div style="background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.08); border-radius:12px; padding:10px 12px; margin-bottom:14px; font-size:12.5px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:5px;">
          <span style="color: var(--pk-text-muted);">Credits to Add</span>
          <span style="font-weight:700; color: var(--pk-text-primary);" id="topUpCreditSummary">₱1,000</span>
        </div>
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:5px;">
          <span style="color: var(--pk-text-muted);">Platform &amp; Service Fee (10%)</span>
          <span style="font-weight:700; color: var(--pk-text-primary);" id="topUpFeeSummary">₱100</span>
        </div>
        <div style="display:flex; justify-content:space-between; align-items:center; border-top:1px solid rgba(255,255,255,0.06); padding-top:5px; margin-top:5px;">
          <span style="font-weight:700; color: var(--pk-text-primary);">Total Payable</span>
          <span style="font-weight:900; color: var(--pk-text-primary); font-size:14px;" id="topUpTotalSummary">₱1,100</span>
        </div>
      </div>

      <!-- Hidden inputs for form data -->
      <input type="hidden" id="topUpSelectedAmount" value="1000">
      <input type="hidden" id="topUpSelectedMethod" value="GCash">

      <!-- Pay Button in Vibrant Green (#00D98B) with Pure White Text -->
      <button type="button" id="topUpSubmitBtn" class="topup-pay-blue-btn" onclick="submitTopUp()" style="background: var(--pk-status-success); color: var(--pk-bg-card) !important; font-weight: 900; padding: 13px 16px; font-size: 15px; border-radius: 14px; box-shadow: 0 4px 18px rgba(0, 217, 139, 0.35);">
        <span id="topUpPayBtnText" style="color: var(--pk-bg-card) !important;">Pay ₱1,100</span>
      </button>

    </div>
  </div>

  <!-- Logout Confirmation Modal (Section 6.4) -->
  <div class="app-modal-overlay" id="logoutModal">
    <div class="app-modal-box" style="text-align:center; max-width:400px;">
      <div class="modal-body" style="padding:32px 24px;">
        <div class="confirm-modal-icon red">
          <svg xmlns="http://www.w3.org/2000/svg" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/></svg>
        </div>
        <h3 class="confirm-modal-title">Sign Out?</h3>
        <p class="confirm-modal-desc">You will need to sign in again to book courts and access your wallet.</p>
        <div style="display:flex; gap:12px;">
          <button type="button" onclick="closeModal('logoutModal')" class="btn-modal-cancel">
            Cancel
          </button>
          <a href="auth.php?logout=1" class="btn-modal-danger">
            Sign Out
          </a>
        </div>
      </div>
    </div>
  </div>

  <!-- Filter Bottom Sheet Modal (§7.4 Flowchart) -->
  <div class="app-modal-overlay" id="filterModal">
    <div class="app-modal-box" style="max-width: 440px;">
      <div class="modal-head">
        <div style="display:flex; align-items:center; gap:8px;">
          <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--pk-status-success)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
          <h3 class="modal-title">Filter Facilities</h3>
        </div>
        <button type="button" class="modal-close-btn" onclick="closeModal('filterModal')">✕</button>
      </div>
      <div class="modal-body">
        <!-- Segmented Control: Court Type -->
        <div style="margin-bottom:20px;">
          <label class="form-label" style="display:flex; justify-content:space-between;">
            <span>Court Type</span>
            <span style="font-size:11px; color: var(--pk-text-muted);">All surfaces</span>
          </label>
          <div class="segmented-control">
            <button type="button" class="segmented-btn active" id="fTypeAll" onclick="selectFilterType('All')">All Venues</button>
            <button type="button" class="segmented-btn" id="fTypeIndoor" onclick="selectFilterType('Indoor')">Indoor Only</button>
            <button type="button" class="segmented-btn" id="fTypeOutdoor" onclick="selectFilterType('Outdoor')">Outdoor Only</button>
          </div>
        </div>

        <!-- Draggable Price Range Filter -->
        <div style="margin-bottom:24px; background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.07); border-radius:14px; padding:16px;">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
            <label class="form-label" style="margin-bottom:0; display:flex; align-items:center; gap:6px;">
              <span>💵 Maximum Price</span>
            </label>
            <span id="priceRangeValue" style="font-size:14px; font-weight:800; color: var(--pk-status-success); font-family:'Montserrat', var(--pk-font-price), sans-serif; background: var(--pk-status-success-bg); padding:3px 10px; border-radius:8px; border:1px solid var(--pk-status-success);">₱200 – ₱2,000+</span>
          </div>
          <div style="position:relative; padding:6px 0 2px;">
            <input type="range" class="price-range-slider" id="filterPriceRange" min="200" max="2000" step="50" value="2000" oninput="updatePriceFilterDisplay(this.value)">
            <div style="display:flex; justify-content:space-between; font-size:11px; color: var(--pk-text-muted); margin-top:8px; font-weight:600;">
              <span>₱200</span>
              <span>₱1,000</span>
              <span>₱2,000+</span>
            </div>
          </div>
        </div>

        <!-- Sort By Radio Tiles -->
        <div style="margin-bottom:24px;">
          <label class="form-label">Sort Results By</label>
          <div style="display:flex; flex-direction:column; gap:8px;">
            <label style="display:flex; align-items:center; justify-content:space-between; padding:10px 14px; background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.08); border-radius:12px; cursor:pointer; transition:all 0.2s;">
              <span style="font-size:13px; color: var(--pk-text-primary); font-weight:600;">✨ Recommended (Verified First)</span>
              <input type="radio" name="modalSortRadio" value="recommended" checked style="accent-color: var(--pk-status-success);">
            </label>
            <label style="display:flex; align-items:center; justify-content:space-between; padding:10px 14px; background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.08); border-radius:12px; cursor:pointer; transition:all 0.2s;">
              <span style="font-size:13px; color: var(--pk-text-primary); font-weight:600;">🏷️ Hourly Rate: Low to High</span>
              <input type="radio" name="modalSortRadio" value="price_asc" style="accent-color: var(--pk-status-success);">
            </label>
            <label style="display:flex; align-items:center; justify-content:space-between; padding:10px 14px; background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.08); border-radius:12px; cursor:pointer; transition:all 0.2s;">
              <span style="font-size:13px; color: var(--pk-text-primary); font-weight:600;">⭐ Facility Rating: High to Low</span>
              <input type="radio" name="modalSortRadio" value="rating_desc" style="accent-color: var(--pk-status-success);">
            </label>
          </div>
        </div>

        <!-- Action Buttons -->
        <div style="display:flex; gap:12px; margin-top:20px;">
          <button type="button" onclick="resetFilterModal()" style="flex:1; padding:12px; background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.1); border-radius:12px; color: var(--pk-text-primary); font-weight:700; font-size:13px; cursor:pointer;">
            Reset All
          </button>
          <button type="button" class="btn-auth-submit" style="flex:2; margin-top:0;" onclick="applyFiltersFromModal()">
            <span>Show Results</span>
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- Cancellation Modal with 24h Window Check (§12.4 Flowchart) -->
  <div class="app-modal-overlay" id="cancelBookingModal">
    <div class="app-modal-box" style="max-width:440px;">
      <div class="modal-body" style="padding:28px 24px; text-align:center;">
        <div class="confirm-modal-icon red">
          <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" x2="12" y1="9" y2="13"/><line x1="12" x2="12.01" y1="17" y2="17"/></svg>
        </div>
        <h3 class="confirm-modal-title">Cancel Booking?</h3>
        <p class="confirm-modal-desc" id="cancelModalSubtext">Are you sure you want to cancel this reservation?</p>

        <!-- 24-Hour Policy Notice Box -->
        <div id="cancelRefundNoticeBox" style="background: var(--pk-status-success-bg); border:1px solid var(--pk-status-success); border-radius:14px; padding:14px; margin-bottom:20px; text-align:left;">
          <div style="font-size:12px; font-weight:800; color: var(--pk-status-success); margin-bottom:4px; display:flex; align-items:center; gap:6px;">
            <span>🛡️</span>
            <span>100% PICKLE CREDITS REFUND ELIGIBLE</span>
          </div>
          <div style="font-size:12px; color:rgba(255,255,255,0.7); line-height:1.4;" id="cancelRefundDetailsText">
            Cancellation made at least 6 hours before match time. Court fee will be refunded back to your wallet.
          </div>
        </div>

        <input type="hidden" id="cancelTargetBookingId" value="">

        <div style="display:flex; gap:12px;">
          <button type="button" onclick="closeModal('cancelBookingModal')" class="btn-modal-cancel">
            Keep Booking
          </button>
          <button type="button" id="btnExecuteCancelBooking" onclick="executeBookingCancellation()" class="btn-modal-danger">
            Yes, Cancel
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- Navigation Overlay Modal (§12.8 Flowchart) -->
  <div class="app-modal-overlay" id="navigationModal">
    <div class="app-modal-box" style="max-width:440px;">
      <div class="modal-head">
        <div style="display:flex; align-items:center; gap:8px;">
          <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--pk-status-success)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg>
          <h3 class="modal-title">Venue Navigation</h3>
        </div>
        <button type="button" class="modal-close-btn" onclick="closeModal('navigationModal')">✕</button>
      </div>
      <div class="modal-body" style="text-align:center;">
        <div style="width:52px; height:52px; border-radius:16px; background: var(--pk-status-success-bg); color: var(--pk-status-success); display:inline-flex; align-items:center; justify-content:center; margin-bottom:14px;">
          <svg xmlns="http://www.w3.org/2000/svg" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
        </div>
        <h4 style="font-size:18px; font-weight:800; color: var(--pk-text-primary); margin:0 0 6px;" id="navFacilityName">South Metro Dinkers</h4>
        <p style="font-size:13px; color: var(--pk-text-muted); margin:0 0 20px;" id="navFacilityAddress">Alabang, Muntinlupa, Metro Manila</p>

        <div style="display:flex; flex-direction:column; gap:10px;">
          <a id="navGoogleMapsLink" href="#" target="_blank" rel="noopener noreferrer" style="display:flex; align-items:center; justify-content:center; gap:10px; padding:12px; background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.12); border-radius:12px; color: var(--pk-text-primary); text-decoration:none; font-size:13px; font-weight:700; transition:all 0.2s;">
            <span>🗺️ Open in Google Maps</span>
          </a>
          <a id="navWazeLink" href="#" target="_blank" rel="noopener noreferrer" style="display:flex; align-items:center; justify-content:center; gap:10px; padding:12px; background: var(--pk-status-success-bg); border:1px solid var(--pk-status-success); border-radius:12px; color: var(--pk-status-success); text-decoration:none; font-size:13px; font-weight:800; transition:all 0.2s;">
            <span>🚗 Open in Waze</span>
          </a>
        </div>
      </div>
    </div>
  </div>

  <!-- Delete Account Confirmation Modal (§13.11 Flowchart) -->
  <div class="app-modal-overlay" id="deleteAccountModal">
    <div class="app-modal-box" style="max-width:420px; text-align:center;">
      <div class="modal-body" style="padding:28px 24px;">
        <div class="confirm-modal-icon red">
          <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/></svg>
        </div>
        <h3 class="confirm-modal-title">Delete Your Account?</h3>
        <p class="confirm-modal-desc">This will permanently erase your player profile, booking history, wallet credits, and match stats. This action cannot be reversed.</p>
        <div style="margin-bottom:20px; text-align:left;">
          <label class="form-label">Type "DELETE" to confirm</label>
          <input type="text" id="deleteAccountConfirmInput" class="form-input" style="padding-left:14px;" placeholder="DELETE" autocomplete="off">
        </div>
        <div style="display:flex; gap:12px;">
          <button type="button" onclick="closeModal('deleteAccountModal')" class="btn-modal-cancel">
            Cancel
          </button>
          <button type="button" onclick="executeAccountDeletion()" class="btn-modal-danger">
            Permanently Delete
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- Edit Account Details Modal -->
  <div class="app-modal-overlay" id="editAccountModal">
    <div class="app-modal-box" style="max-width:440px;">
      <div class="modal-head">
        <h3 class="modal-title">Edit Account Details</h3>
        <button type="button" class="modal-close-btn" onclick="closeModal('editAccountModal')">✕</button>
      </div>
      <div class="modal-body">
        <div class="form-group">
          <label class="form-label">Full Name</label>
          <input type="text" id="modalProfileNameInput" class="form-input" style="padding-left:14px;" value="<?php echo htmlspecialchars($currentUser['name'] ?? ''); ?>" placeholder="Your full name">
        </div>
        <div class="form-group">
          <label class="form-label">Phone Number</label>
          <div style="position:relative; display:flex; align-items:center;">
            <input type="tel" id="modalProfilePhoneInput" class="form-input" style="padding-left:14px; padding-right:90px; width:100%; box-sizing:border-box;" value="<?php echo htmlspecialchars($currentUser['phone'] ?? ''); ?>" placeholder="+63 9xx xxx xxxx" maxlength="16" onkeydown="return isPhoneKey(event)" oninput="formatPHPhoneInput(this)">
            <button type="button" id="btnSendModalPhoneOtp" class="btn-inline-send-otp" onclick="sendModalPhoneOtp()" disabled style="position:absolute; right:7px; top:50%; transform:translateY(-50%); padding:4px 8px; border-radius:6px; font-size:10.5px; font-weight:700; border:1px solid rgba(255,255,255,0.1); background:transparent; color:rgba(255,255,255,0.3); cursor:not-allowed; transition:all 0.2s; white-space:nowrap; z-index:2; line-height:1.15;">
              Send OTP
            </button>
          </div>
        </div>
        <div id="modalPhoneOtpVerificationGroup" style="display:none; margin-bottom:16px;">
          <label class="form-label" style="font-size:12px; color:#00D98B; display:flex; justify-content:space-between; align-items:center;">
            <span>6-Digit Verification Code</span>
            <span id="modalOtpDemoHint" style="color:rgba(255,255,255,0.5); font-weight:600;"></span>
          </label>
          <div style="display:flex; gap:8px; align-items:center; margin-top:4px;">
            <input type="text" id="modalPhoneOtpCodeInput" class="form-input" style="padding-left:14px; letter-spacing:4px; font-weight:700; font-size:15px; flex:1;" placeholder="• • • • • •" maxlength="6" oninput="this.value=this.value.replace(/\D/g,'')">
            <button type="button" class="btn-auth-submit" id="btnVerifyModalOtp" style="width:auto; padding:10px 16px; margin:0; font-size:13px; font-weight:700; white-space:nowrap;" onclick="verifyModalPhoneOtp()">Verify</button>
          </div>
        </div>
        <button type="button" class="btn-auth-submit" id="btnModalSaveProfile" onclick="saveModalProfileChanges()">
          <span>Save Changes</span>
        </button>
      </div>
    </div>
  </div>

  <!-- Help & Support Modal -->
  <div class="app-modal-overlay" id="supportModal">
    <div class="app-modal-box" style="max-width:440px;">
      <div class="modal-head">
        <h3 class="modal-title">Help & Customer Support</h3>
        <button type="button" class="modal-close-btn" onclick="closeModal('supportModal')">✕</button>
      </div>
      <div class="modal-body" style="font-size:13px; color:rgba(255,255,255,0.75); line-height:1.6;">
        <p style="margin-top:0;">Need assistance with court bookings, Pickle Credits, or reporting an issue?</p>
        <div style="background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.08); border-radius:14px; padding:14px; margin-bottom:16px;">
          <div style="font-weight:700; color: var(--pk-text-primary); margin-bottom:4px;">📧 Support Email</div>
          <div style="color: var(--pk-status-success);">support@picklers.ph</div>
          <div style="font-weight:700; color: var(--pk-text-primary); margin:10px 0 4px;">💬 Hotlines</div>
          <div>+63 (02) 8888-PICK (7425)</div>
          <div style="font-size:11px; color: var(--pk-text-muted); margin-top:4px;">Available Monday to Sunday, 7:00 AM – 10:00 PM PHT</div>
        </div>
        <button type="button" class="btn-auth-submit" onclick="closeModal('supportModal')">
          <span>Close</span>
        </button>
      </div>
    </div>
  </div>

  <!-- Privacy Policy Modal -->
  <div class="app-modal-overlay" id="privacyModal">
    <div class="app-modal-box" style="max-width:460px;">
      <div class="modal-head">
        <h3 class="modal-title">Privacy Policy</h3>
        <button type="button" class="modal-close-btn" onclick="closeModal('privacyModal')">✕</button>
      </div>
      <div class="modal-body" style="font-size:12px; color:rgba(255,255,255,0.7); line-height:1.6; max-height:360px; overflow-y:auto;">
        <p><strong style="color: var(--pk-text-primary);">1. Data Collection:</strong> We collect your name, email, phone number, and booking logs to provide seamless court reservations.</p>
        <p><strong style="color: var(--pk-text-primary);">2. Location & Notifications:</strong> Location is used solely to discover pickleball courts nearby. Notification permissions are used for match updates and schedule alerts.</p>
        <p><strong style="color: var(--pk-text-primary);">3. Payment Security:</strong> Financial transactions are processed via secure GCash, Maya, and bank gateways with end-to-end tokenization. PICKLERS never stores CVV or bank credentials.</p>
        <p><strong style="color: var(--pk-text-primary);">4. Compliance:</strong> PICKLERS complies with the Philippine Data Privacy Act of 2012 (RA 10173).</p>
      </div>
      <div style="padding:14px 20px; border-top:1px solid rgba(255,255,255,0.08); text-align:right;">
        <button type="button" class="btn-view-courts" style="background: var(--pk-status-success); color: var(--pk-bg-card); font-weight:800; padding:8px 20px; font-size:13px;" onclick="closeModal('privacyModal')">Got It</button>
      </div>
    </div>
  </div>

  <!-- Terms of Service (EULA) Modal -->
  <div class="app-modal-overlay" id="termsModal">
    <div class="app-modal-box" style="max-width:460px;">
      <div class="modal-head">
        <h3 class="modal-title">Terms of Service (EULA)</h3>
        <button type="button" class="modal-close-btn" onclick="closeModal('termsModal')">✕</button>
      </div>
      <div class="modal-body" style="font-size:12px; color:rgba(255,255,255,0.7); line-height:1.6; max-height:360px; overflow-y:auto;">
        <p><strong style="color: var(--pk-text-primary);">1. Court Rules:</strong> All players agree to follow court etiquette, arrive on time, and respect host venue guidelines.</p>
        <p><strong style="color: var(--pk-text-primary);">2. Booking & Cancellation:</strong> Cancellations made at least 6 hours prior to game time are eligible for 100% Pickle Credits refund to your wallet.</p>
        <p><strong style="color: var(--pk-text-primary);">3. Sportsmanship:</strong> Respectful conduct is required across all community chat channels and open play games.</p>
      </div>
      <div style="padding:14px 20px; border-top:1px solid rgba(255,255,255,0.08); text-align:right;">
        <button type="button" class="btn-view-courts" style="background: var(--pk-status-success); color: var(--pk-bg-card); font-weight:800; padding:8px 20px; font-size:13px;" onclick="closeModal('termsModal')">I Agree</button>
      </div>
    </div>
  </div>

  <!-- Change Password Modal -->
  <div class="app-modal-overlay" id="passwordModal">
    <div class="app-modal-box" style="max-width:420px; border-radius:24px; background: #0E1D33; border: 1px solid rgba(255, 255, 255, 0.12); box-shadow: 0 20px 50px rgba(0,0,0,0.6);">
      <div class="modal-head" style="border-bottom: 1px solid rgba(255,255,255,0.08); padding: 20px 24px; display:flex; justify-content:space-between; align-items:center;">
        <div style="display:flex; align-items:center; gap:14px;">
          <div style="width:42px; height:42px; border-radius:14px; background: rgba(0, 217, 139, 0.12); border: 1px solid rgba(0, 217, 139, 0.3); display:flex; align-items:center; justify-content:center;">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#00D98B" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          </div>
          <div>
            <h3 class="modal-title" style="font-size:18px; font-weight:800; color: #FFFFFF; margin:0; letter-spacing:-0.02em;">Change Password</h3>
            <p style="font-size:12px; color: #94A3B8; margin:2px 0 0; font-weight:500;">Update your account security settings</p>
          </div>
        </div>
        <button type="button" class="modal-close-btn" onclick="closeModal('passwordModal')" style="width:34px; height:34px; min-width:34px; min-height:34px; border-radius:50%; background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.15); color:rgba(255,255,255,0.8); display:inline-flex; align-items:center; justify-content:center; padding:0; cursor:pointer; font-size:14px; font-weight:700; transition:all 0.2s ease;">✕</button>
      </div>
      <form id="changePasswordForm" autocomplete="off" onsubmit="event.preventDefault(); submitPasswordChange();">
        <div class="modal-body" style="padding: 24px; display:flex; flex-direction:column; gap:18px;">
          <!-- Hidden scoped username prevents browser credential managers from scanning up DOM to venue search -->
          <input type="text" name="username" value="<?php echo htmlspecialchars($currentUser['email'] ?? 'player@picklers.ph'); ?>" autocomplete="username" style="display:none;" aria-hidden="true" tabindex="-1">

          <!-- Current Password -->
          <div class="form-group" style="margin:0;">
            <label class="form-label" style="font-size:11px; font-weight:800; color: #94A3B8; text-transform:uppercase; letter-spacing:0.06em; margin-bottom:8px; display:block;">Current Password</label>
            <div style="position:relative; display:flex; align-items:center; border-radius:14px;">
              <input type="password" id="currentPassInput" name="current_password" class="form-input" style="padding-left:16px; padding-right:44px; background:rgba(255,255,255,0.05); border:1.5px solid rgba(255,255,255,0.12); border-radius:14px !important; outline:none !important; font-size:14px; color:#FFFFFF;" placeholder="Enter current password" autocomplete="current-password">
              <button type="button" onclick="togglePasswordVisibility('currentPassInput', this)" style="position:absolute; right:14px; background:none; border:none; color: #94A3B8; cursor:pointer; padding:4px; display:flex; align-items:center;" title="Toggle password visibility">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
              </button>
            </div>
          </div>

          <!-- New Password -->
          <div class="form-group" style="margin:0;">
            <label class="form-label" style="font-size:11px; font-weight:800; color: #94A3B8; text-transform:uppercase; letter-spacing:0.06em; margin-bottom:8px; display:block;">New Password</label>
            <div style="position:relative; display:flex; align-items:center; border-radius:14px;">
              <input type="password" id="newPassInput" name="new_password" class="form-input" style="padding-left:16px; padding-right:44px; background:rgba(255,255,255,0.05); border:1.5px solid rgba(255,255,255,0.12); border-radius:14px !important; outline:none !important; font-size:14px; color:#FFFFFF;" placeholder="Min. 6 characters" autocomplete="new-password">
              <button type="button" onclick="togglePasswordVisibility('newPassInput', this)" style="position:absolute; right:14px; background:none; border:none; color: #94A3B8; cursor:pointer; padding:4px; display:flex; align-items:center;" title="Toggle password visibility">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
              </button>
            </div>
          </div>

          <!-- Confirm New Password -->
          <div class="form-group" style="margin:0;">
            <label class="form-label" style="font-size:11px; font-weight:800; color: #94A3B8; text-transform:uppercase; letter-spacing:0.06em; margin-bottom:8px; display:block;">Confirm New Password</label>
            <div style="position:relative; display:flex; align-items:center; border-radius:14px;">
              <input type="password" id="confirmPassInput" name="confirm_password" class="form-input" style="padding-left:16px; padding-right:44px; background:rgba(255,255,255,0.05); border:1.5px solid rgba(255,255,255,0.12); border-radius:14px !important; outline:none !important; font-size:14px; color:#FFFFFF;" placeholder="Re-enter new password" autocomplete="new-password">
              <button type="button" onclick="togglePasswordVisibility('confirmPassInput', this)" style="position:absolute; right:14px; background:none; border:none; color: #94A3B8; cursor:pointer; padding:4px; display:flex; align-items:center;" title="Toggle password visibility">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
              </button>
            </div>
          </div>

          <!-- Security Hint -->
          <div style="background:rgba(0, 217, 139, 0.06); border:1px solid rgba(0, 217, 139, 0.18); border-radius:14px; padding:12px 16px; font-size:12.5px; color: #94A3B8; display:flex; align-items:center; gap:10px;">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#00D98B" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            <span>Password must be at least 6 characters.</span>
          </div>

          <button type="submit" class="btn-auth-submit" style="background: #00D98B; color: #0A121F !important; font-size:15px; font-weight:800; border-radius:14px; padding:14px; margin-top:6px; border:none; cursor:pointer; box-shadow: 0 4px 20px rgba(0,217,139,0.3); transition: all 0.2s ease;">
            <span style="color:#0A121F !important;">Update Password</span>
          </button>
        </div>
      </form>
    </div>
  </div>


  <!-- Notifications Modal -->
  <div class="app-modal-overlay notif-modal-overlay" id="notifModal">
    <div class="app-modal-box notif-modal-box">
      <div class="modal-head notif-modal-head">
        <div style="display: flex; align-items: center; gap: 12px;">
          <div class="notif-header-icon-box">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
          </div>
          <div>
            <h3 class="modal-title notif-title">Notifications</h3>
            <span class="notif-subtitle" id="notifUnreadBadgeText">
              <?php
                $totalNotifs = count($userNotifications ?? []);
                $unreadCount = $unreadNotifsCount ?? 0;
                echo $unreadCount > 0 ? "{$unreadCount} unread of {$totalNotifs}" : "{$totalNotifs} total";
              ?>
            </span>
          </div>
        </div>
        <div style="display: flex; align-items: center; gap: 10px;">
          <button type="button" class="notif-mark-read-btn" onclick="markAllNotificationsAsRead()">
            Mark read
          </button>
          <button type="button" class="modal-close-btn" onclick="closeModal('notifModal')" aria-label="Close" style="width: 32px; height: 32px; min-width: 32px; min-height: 32px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; background: rgba(255, 255, 255, 0.08); border: 1px solid rgba(255, 255, 255, 0.12); color: #FFFFFF; cursor: pointer; padding: 0;">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          </button>
        </div>
      </div>
      <div class="modal-body notif-modal-list" id="notifModalList">
        <?php if (!empty($userNotifications)): foreach ($userNotifications as $n):
          $isUnread = empty($n['is_read']);
          $notifId = htmlspecialchars((string)($n['id'] ?? ''));
        ?>
          <div class="notif-swipe-row" data-id="<?php echo $notifId; ?>">
            <div class="notif-swipe-action" onclick="dismissNotification(this.closest('.notif-swipe-row'), '<?php echo $notifId; ?>')">
              <div class="notif-swipe-action-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
              </div>
              <span class="notif-swipe-action-text">Delete</span>
            </div>
            <div class="notif-card-item <?php echo $isUnread ? 'unread' : ''; ?>" data-id="<?php echo $notifId; ?>">
              <div class="notif-card-icon">
                <?php if (stripos($n['title'] ?? '', 'Open Play') !== false || stripos($n['title'] ?? '', 'Match') !== false): ?>
                  <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg>
                <?php elseif (stripos($n['title'] ?? '', 'Top-Up') !== false || stripos($n['title'] ?? '', 'Credit') !== false || stripos($n['title'] ?? '', 'Wallet') !== false): ?>
                  <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                <?php elseif (stripos($n['title'] ?? '', 'Verified') !== false || stripos($n['title'] ?? '', 'Identity') !== false): ?>
                  <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg>
                <?php elseif (stripos($n['title'] ?? '', 'Booking') !== false || stripos($n['title'] ?? '', 'Court') !== false || stripos($n['title'] ?? '', 'Reservation') !== false): ?>
                  <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
                <?php else: ?>
                  <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
                <?php endif; ?>
              </div>
              <div class="notif-card-content">
                <div class="notif-card-head">
                  <div class="notif-item-title"><?php echo htmlspecialchars($n['title'] ?? ''); ?></div>
                  <div class="notif-item-meta">
                    <span class="notif-item-time"><?php echo htmlspecialchars($n['time'] ?? 'Just now'); ?></span>
                    <?php if ($isUnread): ?>
                      <span class="notif-card-unread-dot" title="Unread"></span>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="notif-item-body"><?php echo htmlspecialchars($n['body'] ?? ''); ?></div>
              </div>
            </div>
          </div>
        <?php endforeach; else: ?>
          <div style="text-align: center; padding: 48px 20px; color: var(--pk-text-muted);">
            <div style="font-size: 36px; margin-bottom: 10px;">🔔</div>
            <div style="font-size: 15px; font-weight: 700; color: var(--pk-text-primary); margin-bottom: 4px;">No notifications yet</div>
            <div style="font-size: 12px; color: rgba(255, 255, 255, 0.55);">When you book courts or join open play sessions, updates will show up here.</div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Sign Out Confirmation Modal -->
  <div class="app-modal-overlay" id="logoutModal">
    <div class="app-modal-box" style="max-width: 400px; border-radius: 24px; background: linear-gradient(165deg, rgba(17,31,58,0.98) 0%, rgba(10,22,40,0.99) 100%); border: 1px solid rgba(255,255,255,0.12); box-shadow: 0 24px 60px rgba(0,0,0,0.6);">
      <div style="padding: 26px 24px; text-align: center;">
        <div style="width: 56px; height: 56px; border-radius: 50%; background: rgba(239,68,68,0.15); border: 2px solid var(--pk-status-error); color: var(--pk-status-error); display: flex; align-items: center; justify-content: center; margin: 0 auto 16px; box-shadow: 0 0 20px rgba(239,68,68,0.3);">
          <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/></svg>
        </div>
        <h3 style="font-size: 20px; font-weight: 900; color: var(--pk-text-primary); margin: 0 0 8px;">Sign Out of Picklers?</h3>
        <p style="font-size: 13px; color: var(--pk-text-muted); margin: 0 0 24px; line-height: 1.5;">You will need to re-enter your credentials to access your court bookings and wallet balance.</p>

        <div style="display: flex; gap: 12px;">
          <button type="button" onclick="closeModal('logoutModal')" style="flex: 1; padding: 12px 18px; background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.15); border-radius: 12px; color: var(--pk-text-primary); font-size: 13px; font-weight: 700; cursor: pointer; transition: all 0.2s;">
            Stay Signed In
          </button>
          <a href="auth.php?action=logout" style="flex: 1; padding: 12px 18px; background: var(--pk-status-error); border: none; border-radius: 12px; color: var(--pk-text-primary); font-size: 13px; font-weight: 800; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; box-shadow: 0 4px 16px rgba(239,68,68,0.4); transition: all 0.2s;">
            Yes, Sign Out
          </a>
        </div>
      </div>
    </div>
  </div>

  <!-- Floating Toast Container -->
  <div class="toast-container" id="toastContainer"></div>
