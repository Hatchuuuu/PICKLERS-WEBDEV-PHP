  <!-- ========================================================================
       MODALS
       ======================================================================== -->

  <!-- 1. QR Pass Scanner Modal -->
  <div class="app-modal-overlay" id="scannerModal">
    <div class="modal-box-card" style="text-align:center; max-width:480px; width:92%;">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
        <div style="display:flex; align-items:center; gap:8px;">
          <div style="width:32px; height:32px; border-radius:50%; background: var(--pk-status-success-bg); display:flex; align-items:center; justify-content:center; color: var(--pk-status-success);">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z"/><circle cx="12" cy="13" r="3"/></svg>
          </div>
          <h3 style="font-size:18px; font-weight:800; color: var(--pk-text-primary); margin:0;">Court Pass QR Scanner</h3>
        </div>
        <button type="button" onclick="closeModal('scannerModal')" style="background:none; border:none; color: var(--pk-text-muted); font-size:22px; cursor:pointer; padding:4px; line-height:1;">&times;</button>
      </div>

      <!-- Camera Control Bar -->
      <div style="display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:12px; background:rgba(15,23,42,0.6); padding:8px 12px; border-radius:10px; border:1px solid rgba(255,255,255,0.08);">
        <div style="display:flex; align-items:center; gap:6px; font-size:12px; color: var(--pk-text-muted);">
          <span id="qrCamStatusDot" style="width:8px; height:8px; border-radius:50%; background: var(--pk-brand-amber); display:inline-block;"></span>
          <span id="qrCamStatusText">Initializing camera...</span>
        </div>
        <div style="display:flex; align-items:center; gap:6px;">
          <select id="qrCameraSelect" style="background: var(--pk-bg-card); border:1px solid rgba(255,255,255,0.15); color: var(--pk-text-primary); font-size:11px; padding:4px 8px; border-radius:6px; max-width:140px; display:none;" onchange="switchQrCamera(this.value)">
          </select>
          <button type="button" id="btnToggleCamFacing" onclick="toggleCameraFacing()" style="background:rgba(255,255,255,0.1); border:1px solid rgba(255,255,255,0.15); color: var(--pk-text-primary); font-size:11px; padding:4px 10px; border-radius:6px; cursor:pointer; display:inline-flex; align-items:center; gap:4px;">
            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
            <span id="btnFacingLabel">Flip Cam</span>
          </button>
        </div>
      </div>

      <!-- Live Camera Viewport Container -->
      <div id="qrScannerContainer" style="width:100%; height:280px; background: var(--pk-bg-surface); border:2px dashed var(--pk-status-success); border-radius:16px; overflow:hidden; position:relative; display:flex; align-items:center; justify-content:center;">
        
        <!-- Live Video Element -->
        <video id="qrVideoFeed" autoplay playsinline muted style="width:100%; height:100%; object-fit:cover; display:none;"></video>
        <canvas id="qrCanvas" style="display:none;"></canvas>

        <!-- Viewfinder Overlay Box -->
        <div id="qrOverlayBox" style="position:absolute; width:200px; height:200px; border:2px solid var(--pk-status-success); border-radius:16px; box-shadow:0 0 0 9999px rgba(5,11,20,0.65); display:none; pointer-events:none;">
          <div style="position:absolute; top:0; left:0; width:20px; height:20px; border-top:4px solid var(--pk-status-success); border-left:4px solid var(--pk-status-success); border-top-left-radius:12px;"></div>
          <div style="position:absolute; top:0; right:0; width:20px; height:20px; border-top:4px solid var(--pk-status-success); border-right:4px solid var(--pk-status-success); border-top-right-radius:12px;"></div>
          <div style="position:absolute; bottom:0; left:0; width:20px; height:20px; border-bottom:4px solid var(--pk-status-success); border-left:4px solid var(--pk-status-success); border-bottom-left-radius:12px;"></div>
          <div style="position:absolute; bottom:0; right:0; width:20px; height:20px; border-bottom:4px solid var(--pk-status-success); border-right:4px solid var(--pk-status-success); border-bottom-right-radius:12px;"></div>
          <!-- Laser Scan Line -->
          <div class="qr-laser-line" style="position:absolute; width:100%; height:2px; background:linear-gradient(90deg, transparent, var(--pk-status-success), transparent); box-shadow:0 0 8px var(--pk-status-success); animation:qrScanLine 2s linear infinite;"></div>
        </div>

        <!-- Initial Placeholder Screen when camera starts -->
        <div id="qrCamFallbackScreen" style="display:flex; flex-direction:column; align-items:center; justify-content:center; padding:20px; gap:10px;">
          <div style="width:50px; height:50px; border-radius:50%; background: var(--pk-status-success-bg); border:1px solid var(--pk-status-success); display:flex; align-items:center; justify-content:center; color: var(--pk-status-success);">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z"/><circle cx="12" cy="13" r="3"/></svg>
          </div>
          <span id="qrFallbackText" style="font-size:13px; color: var(--pk-text-muted); text-align:center;">Requesting camera permissions...</span>
          <button type="button" id="btnStartCam" onclick="initQrCamera()" style="background: var(--pk-status-success); color: var(--pk-bg-card); border:none; font-weight:800; font-size:12px; padding:8px 16px; border-radius:8px; cursor:pointer; display:none;">Start Camera</button>
        </div>

        <!-- Scan Result Success Alert Banner -->
        <div id="qrScanSuccessOverlay" style="position:absolute; inset:0; background:rgba(5,11,20,0.95); display:none; flex-direction:column; align-items:center; justify-content:center; padding:20px; text-align:center; gap:8px; z-index:10;">
          <div style="width:48px; height:48px; border-radius:50%; background: var(--pk-status-success-bg); border:2px solid var(--pk-status-success); display:flex; align-items:center; justify-content:center; color: var(--pk-status-success); font-size:24px;">✓</div>
          <div style="font-size:16px; font-weight:800; color: var(--pk-text-primary);" id="qrSuccessTitle">Check-In Verified!</div>
          <div style="font-size:14px; color: var(--pk-status-success); font-weight:800;" id="qrSuccessPlayer">Marcus Vance</div>
          <div style="font-size:12px; color: var(--pk-text-muted);" id="qrSuccessDetails">Court 1 • Mon 2:00 PM</div>
          <button type="button" onclick="resetQrScanner()" style="margin-top:10px; background: var(--pk-status-success); border:none; color: var(--pk-bg-card); padding:8px 18px; border-radius:8px; font-size:12px; font-weight:800; cursor:pointer;">Scan Another Code</button>
        </div>

      </div>

      <!-- Manual Code Input / Fallback Simulation -->
      <div style="margin-top:14px; display:flex; gap:8px;">
        <input type="text" id="manualQrInput" placeholder="Or enter Pass Code (e.g. BK-2026-8819)" style="flex:1; background: var(--pk-bg-card); border:1px solid rgba(255,255,255,0.12); padding:10px 14px; border-radius:10px; color: var(--pk-text-primary); font-size:13px;">
        <button type="button" onclick="processManualQrInput()" style="background: var(--pk-status-success); color: var(--pk-bg-card); font-weight:800; border:none; padding:0 16px; border-radius:10px; cursor:pointer; font-size:13px;">Verify</button>
      </div>

    </div>
  </div>

  <!-- 2. Walk-In Guest Modal -->
  <div class="app-modal-overlay" id="walkInModal">
    <div class="modal-box-card">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;">
        <h3 style="font-size:18px; font-weight:800; color: var(--pk-text-primary);">Log Walk-In Player</h3>
        <button type="button" onclick="closeModal('walkInModal')" style="background:none; border:none; color: var(--pk-text-muted); font-size:20px; cursor:pointer;">&times;</button>
      </div>
      <div style="display:flex; flex-direction:column; gap:16px;">
        <div style="position: relative;">
          <label style="display:block; font-size:13px; font-weight:700; color: var(--pk-text-muted); margin-bottom:6px;">Guest Name</label>
          <input type="text" id="walkin_name" class="input-field" placeholder="e.g. Kuya Jobert & Team" style="background: var(--pk-bg-card); border:1px solid rgba(255,255,255,0.1); padding:10px 14px; border-radius:10px; width:100%; color: var(--pk-text-primary);" oninput="onWalkinNameInput(this.value)" onfocus="onWalkinNameInput(this.value)" autocomplete="off">
          <div id="walkinNameAutocomplete" class="autocomplete-dropdown-menu" style="display:none; position:absolute; top:100%; left:0; right:0; z-index:1000; background:#0F1A2D; border:1px solid rgba(255,255,255,0.15); border-radius:12px; margin-top:4px; max-height:220px; overflow-y:auto; box-shadow:0 10px 25px rgba(0,0,0,0.6);"></div>
        </div>
        <div>
          <label style="display:block; font-size:13px; font-weight:700; color: var(--pk-text-muted); margin-bottom:6px;">Assign Court</label>
          <select id="walkin_court" style="background: var(--pk-bg-card); border:1px solid rgba(255,255,255,0.1); padding:10px 14px; border-radius:10px; width:100%; color: var(--pk-text-primary);">
            <?php if (!empty($courts) && is_array($courts)): ?>
              <?php foreach ($courts as $crt): ?>
                <option value="<?= htmlspecialchars((string)$crt['name']) ?>"><?= htmlspecialchars((string)$crt['name']) ?> (₱<?= number_format((float)($crt['price'] ?? 450), 0) ?>/hr)</option>
              <?php endforeach; ?>
            <?php else: ?>
              <option value="Court 1">Court 1</option>
              <option value="Court 2">Court 2</option>
              <option value="Court 3">Court 3</option>
              <option value="Court 4">Court 4</option>
            <?php endif; ?>
          </select>
        </div>
        <div>
          <label style="display:block; font-size:13px; font-weight:700; color: var(--pk-text-muted); margin-bottom:6px;">Session Duration</label>
          <select id="walkin_duration" style="background: var(--pk-bg-card); border:1px solid rgba(255,255,255,0.1); padding:10px 14px; border-radius:10px; width:100%; color: var(--pk-text-primary);">
            <option value="60">60 Minutes (₱450)</option>
            <option value="90">90 Minutes (₱675)</option>
            <option value="120">120 Minutes (₱900)</option>
          </select>
        </div>
        <button type="button" class="btn-walkin-open" style="width:100%; justify-content:center; margin-top:8px;" onclick="confirmWalkIn()">Confirm & Mark Court Occupied</button>
      </div>
    </div>
  </div>

  <!-- 3. Decline Request Modal -->
  <div class="app-modal-overlay" id="declineModal">
    <div class="modal-box-card">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;">
        <h3 style="font-size:18px; font-weight:800; color: var(--pk-status-error);">Decline Booking Request</h3>
        <button type="button" onclick="closeModal('declineModal')" style="background:none; border:none; font-size:20px; cursor:pointer;" class="modal-text-muted">&times;</button>
      </div>
      <p style="font-size:13px; margin-bottom:14px;" class="modal-text-muted">Select the reason for declining. Booking hold will be released and funds refunded to the player.</p>
      <select id="declineReason" style="background: var(--pk-bg-card); border:1px solid rgba(255,255,255,0.1); padding:12px 14px; border-radius:10px; width:100%; color: var(--pk-text-primary); margin-bottom:16px;">
        <option>Court is reserved for coaching clinic / league</option>
        <option>Court is scheduled for maintenance & cleaning</option>
        <option>Slot conflict with approved walk-in guest</option>
        <option>Weather interruption (outdoor courts)</option>
      </select>
      <div style="display:flex; gap:10px;">
        <button type="button" class="btn-end-session" style="flex:1;" onclick="closeModal('declineModal')">Cancel</button>
        <button type="button" class="btn-req-decline" style="flex:1;" onclick="confirmDecline()">Confirm Decline</button>
      </div>
    </div>
  </div>

  <!-- 5. Request Payout Modal -->
  <div class="app-modal-overlay" id="payoutModal">
    <div class="modal-box-card">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;">
        <h3 style="font-size:18px; font-weight:800;" class="modal-text-primary">Request Earnings Payout</h3>
        <button type="button" onclick="closeModal('payoutModal')" style="background:none; border:none; font-size:20px; cursor:pointer;" class="modal-text-muted">&times;</button>
      </div>
      <div style="background: var(--pk-status-success-bg); border:1px solid var(--pk-status-success); border-radius:12px; padding:12px 16px; margin-bottom:18px; display:flex; justify-content:space-between; align-items:center;">
        <span style="font-size:13px;" class="modal-text-muted">Available Balance</span>
        <span style="font-size:18px; font-weight:900; color: var(--pk-status-success);" id="payoutAvailableBalance">₱<?= number_format((float)($earnings['available'] ?? 0), 2) ?></span>
      </div>
      <form id="payoutForm" autocomplete="off" onsubmit="event.preventDefault(); dispatchPayout();">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? ''); ?>">
        <div style="display:flex; flex-direction:column; gap:14px;">
          <div>
            <label style="display:block; font-size:13px; font-weight:700; margin-bottom:6px;" class="modal-text-muted">Disbursement Method</label>
            <select id="payoutMethod" style="background: var(--pk-bg-card); border:1px solid rgba(255,255,255,0.1); padding:10px 14px; border-radius:10px; width:100%; color: var(--pk-text-primary);">
              <option>GCash (Instant Disbursement)</option>
              <option>Bank Deposit — BDO Unibank</option>
              <option>Bank Deposit — BPI</option>
              <option>Bank Deposit — UnionBank</option>
            </select>
          </div>
          <div>
            <label style="display:block; font-size:13px; font-weight:700; margin-bottom:6px;" class="modal-text-muted">Account Name</label>
            <!-- No stand-in name here on purpose: this used to default to
                 "Marcus Vance" (a demo value), which an owner could easily
                 submit unnoticed — sending a real payout to the wrong name. -->
            <input type="text" id="payoutAccountName" value="<?= htmlspecialchars((string)($currentUser['name'] ?? '')) ?>" placeholder="Name on the receiving account" required style="background: var(--pk-bg-card); border:1px solid rgba(255,255,255,0.1); padding:10px 14px; border-radius:10px; width:100%; color: var(--pk-text-primary);">
          </div>
          <div>
            <label style="display:block; font-size:13px; font-weight:700; margin-bottom:6px;" class="modal-text-muted">Account / Mobile Number</label>
            <input type="text" id="payoutAccountNumber" value="" placeholder="e.g. 0917 888 2026" required style="background: var(--pk-bg-card); border:1px solid rgba(255,255,255,0.1); padding:10px 14px; border-radius:10px; width:100%; color: var(--pk-text-primary);">
          </div>
          <div>
            <label style="display:block; font-size:13px; font-weight:700; margin-bottom:6px;" class="modal-text-muted">Amount (PHP)</label>
            <input type="number" id="payoutAmount" value="" placeholder="100.00" min="100" max="1000000" step="0.01" required style="background: var(--pk-bg-card); border:1px solid rgba(255,255,255,0.1); padding:10px 14px; border-radius:10px; width:100%; color: var(--pk-text-primary);">
          </div>
          <button type="submit" class="btn-walkin-open" style="width:100%; justify-content:center; margin-top:6px;">Submit Withdrawal Request</button>
        </div>
      </form>
    </div>
  </div>


  <!-- 6b. Edit Court Modal -->
  <div class="app-modal-overlay" id="editCourtModal" onclick="if(event.target === this) closeModal('editCourtModal')">
    <div class="modal-box-card edit-court-modal-card">
      <form id="editCourtForm" autocomplete="off" onsubmit="submitEditCourtForm(event)">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? ''); ?>">
        <div style="display: flex; flex-direction: column; gap: 14px;">
          <!-- Field 1: Court Name Input (Pill) -->
          <div>
            <input type="text" id="editCourtNameInput" name="name" class="edit-court-pill-input" value="Court 1" placeholder="Court 1" readonly style="cursor: not-allowed; opacity: 0.85;" required>
          </div>

          <!-- Field 2: Hourly Rate Input (Pill) -->
          <div class="edit-court-pill-rate-wrap">
            <span class="edit-court-peso-sign" style="color: var(--pk-text-muted);">₱</span>
            <input type="number" id="editCourtRateInput" name="rate" class="edit-court-rate-input" value="450" min="50" max="10000" step="10" required>
            <span class="edit-court-hr-suffix" style="color: var(--pk-text-muted);">/hr</span>
          </div>



          <!-- Action Buttons Row -->
          <div class="edit-court-actions-row">
            <button type="submit" class="btn-edit-court-save">Save</button>
            <button type="button" class="btn-edit-court-cancel" onclick="closeModal('editCourtModal')">Cancel</button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <!-- 6c. Create Tournament Modal
       Roster building, seeding and every bracket action live on the bracket
       console (/app/owner/tournaments/{id}); this modal only opens the event. -->
  <!-- 6. Create Tournament Modal -->
  <!-- 6. Create Tournament Modal -->
  <div class="app-modal-overlay" id="createTournamentModal">
    <div class="modal-box-card" style="max-width: 520px; width: 92%; padding: 22px 24px; background: #0b1426; border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 18px; box-shadow: 0 24px 60px rgba(0, 0, 0, 0.8); scrollbar-width: none; -ms-overflow-style: none;">
      <!-- Modal Header -->
      <div class="tb-modal-header" style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 14px; width: 100%;">
        <div style="display: flex; align-items: center; gap: 12px; flex: 1; padding-right: 12px;">
          <div class="tb-modal-title-icon" style="width: 38px; height: 38px; border-radius: 10px; background: rgba(255, 184, 0, 0.12); border: 1px solid rgba(255, 184, 0, 0.25); color: #FFB800; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg>
          </div>
          <div>
            <h3 style="font-family: 'Montserrat', var(--font-heading), sans-serif; font-size: 18px; font-weight: 800; color: #FFFFFF; margin: 0 0 2px; letter-spacing: -0.01em;">Create Tournament</h3>
            <p style="font-size: 11.5px; font-weight: 500; color: #94A3B8; margin: 0;">Host a league, championship cup or prize ladder at your facility</p>
          </div>
        </div>
        <button type="button" class="tb-modal-close-btn" onclick="closeModal('createTournamentModal')" aria-label="Close" style="background: rgba(255, 255, 255, 0.06); border: 1px solid rgba(255, 255, 255, 0.12); color: #94A3B8; width: 30px; height: 30px; min-width: 30px; min-height: 30px; max-width: 30px; max-height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 18px; line-height: 1; padding: 0; flex-shrink: 0; aspect-ratio: 1 / 1; cursor: pointer; transition: all 0.2s;" onmouseover="this.style.color='#FFFFFF'; this.style.background='rgba(255,255,255,0.16)';" onmouseout="this.style.color='#94A3B8'; this.style.background='rgba(255,255,255,0.06)'">&times;</button>
      </div>

      <form id="createTournamentForm" autocomplete="off" onsubmit="submitCreateTournamentForm(event)">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? ''); ?>">
        
        <div style="display: flex; flex-direction: column; gap: 10px;">

          <!-- Section 1: Tournament Identity & Category -->
          <div class="tb-section-card" style="background: #0f182a; border: 1px solid rgba(255, 255, 255, 0.06); border-radius: 12px; padding: 12px;">
            <div class="tb-section-title" style="display: flex; align-items: center; gap: 6px; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: #FFB800; margin-bottom: 8px;">
              <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
              <span>Tournament Details</span>
            </div>

            <!-- 1. Name -->
            <div style="margin-bottom: 8px;">
              <label class="tb-label" for="tournTitleInput">Tournament Name</label>
              <div class="tb-input-icon-wrap" style="position: relative;">
                <div class="tb-input-icon-prefix" style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #64748B; pointer-events: none;">
                  <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                </div>
                <input type="text" id="tournTitleInput" class="tb-input tb-input-with-icon" style="padding-left: 32px;" required maxlength="120" placeholder="e.g. Pro Circuit Manila 2026">
              </div>
            </div>

            <!-- 2. Category & Bracket Format -->
            <div class="tb-field-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
              <div>
                <label class="tb-label" for="tournCategoryInput">Category</label>
                <div class="tb-select-wrap">
                  <select id="tournCategoryInput" class="tb-select" onchange="onTournCategoryChange()">
                    <?php foreach (\Picklers\Services\TournamentService::CATEGORIES as $cat): ?>
                      <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
              <div>
                <label class="tb-label" for="tournFormatInput">Bracket Format</label>
                <div class="tb-select-wrap">
                  <select id="tournFormatInput" class="tb-select">
                    <option value="single_elimination">Single Elimination</option>
                    <option value="double_elimination">Double Elimination</option>
                    <option value="round_robin">Round Robin</option>
                  </select>
                </div>
              </div>
            </div>
          </div>

          <!-- Date -->
          <div class="tb-section-card" style="background: #0f182a; border: 1px solid rgba(255, 255, 255, 0.06); border-radius: 12px; padding: 12px;">
            <label class="tb-label" for="tournDateInput">Date</label>
            <input type="date" id="tournDateInput" class="tb-input" required>
          </div>

          <!-- Section 3: Capacity & Pairing Mode -->
          <div class="tb-section-card" style="background: #0f182a; border: 1px solid rgba(255, 255, 255, 0.06); border-radius: 12px; padding: 12px;">
            <div class="tb-section-title" style="display: flex; align-items: center; gap: 6px; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: #FFB800; margin-bottom: 8px;">
              <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
              <span>Capacity & Teammates</span>
            </div>

            <!-- Max Teams -->
            <div style="margin-bottom: 8px;">
              <label class="tb-label" for="tournMaxTeamsInput">Max Teams Capacity <span style="font-weight:600; opacity:.7;">(2–32 teams)</span></label>
              <input type="number" id="tournMaxTeamsInput" class="tb-input" min="2" max="32" step="1" value="16">
            </div>

            <!-- Pairing Mode -->
            <div id="tournPairingWrap">
              <label class="tb-label">Teammate Pairing Mode</label>
              <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                <label class="tt-pair-option">
                  <input type="radio" name="tournPairingMode" value="fixed" checked onchange="onTournPairingChange()">
                  <div>
                    <strong>Fixed Teammate</strong>
                    <em>Set 2-player team</em>
                  </div>
                </label>
                <label class="tt-pair-option tt-pair-option--mix">
                  <input type="radio" name="tournPairingMode" value="mix" onchange="onTournPairingChange()">
                  <div>
                    <strong>Mix / Partner Draw</strong>
                    <em>Random partner draw</em>
                  </div>
                </label>
              </div>
            </div>
          </div>

          <!-- Section 4: Add Tournament Players (Roster Draft) -->
          <div class="tb-section-card" style="background: #0f182a; border: 1px solid rgba(255, 255, 255, 0.06); border-radius: 12px; padding: 12px;">
            <div class="tb-section-title" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
              <div style="display:flex; align-items:center; gap:6px; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: #FFB800;">
                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
                <span>Add Tournament Players</span>
              </div>
              <span style="font-size: 11px; font-weight: 600; text-transform:none; color: #FFFFFF;">Search registered players</span>
            </div>

            <div style="display: flex; flex-direction: column; gap: 8px;">
              <div class="tb-search-wrap" style="position: relative;">
                <input type="text" id="tournEntrantP1" class="tb-input" autocomplete="off"
                       placeholder="Type player name to search…"
                       oninput="onTournEntrantSearch(1)" onfocus="onTournEntrantSearch(1)">
                <div class="tb-suggestions" id="tournEntrantSuggest1" role="listbox"></div>
              </div>

              <div class="tb-search-wrap" id="tournEntrantP2Wrap" style="position: relative;">
                <input type="text" id="tournEntrantP2" class="tb-input" autocomplete="off"
                       placeholder="Partner name…"
                       oninput="onTournEntrantSearch(2)" onfocus="onTournEntrantSearch(2)">
                <div class="tb-suggestions" id="tournEntrantSuggest2" role="listbox"></div>
              </div>

              <button type="button" class="tb-btn" style="width:100%; padding: 9px; font-weight: 800; font-size: 12px; background: #131e33; border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 10px; color: #FFFFFF;" id="tournAddEntrantBtn"
                      onclick="addTournEntrantDraft()">+ Add to Roster</button>
            </div>
          </div>

          <!-- Roster Chips Pool -->
          <div style="background: rgba(0, 0, 0, 0.25); border: 1px dashed rgba(255, 255, 255, 0.14); border-radius: 14px; padding: 14px; margin-bottom: 4px;">
            <div style="display:flex; justify-content:space-between; align-items:center; font-size:11.5px; font-weight:800; color: #94A3B8; margin-bottom:8px;">
              <span id="tournRosterLabel">Registered Teams</span>
              <span id="tournRosterCount" style="color: #FFB800; font-weight: 900;">0</span>
            </div>
            <div id="tournRosterChips" class="tb-pool"></div>
            <p class="tb-hint" style="margin-top:8px; font-size: 11px; color: #64748B;">Optional — you can build or edit the roster later from the bracket console.</p>
          </div>

          <!-- Alert box -->
          <div class="tb-alert" id="createTournAlert" role="alert"></div>

          <!-- Submit Button -->
          <button type="submit" class="tb-btn tb-btn-primary-glow" style="width:100%; padding:14px; font-size:14px; font-weight:800; border-radius:12px; background: linear-gradient(135deg, #FFB800 0%, #E6A100 100%); color: #0b1324; border: none; cursor: pointer; box-shadow: 0 4px 14px rgba(255, 184, 0, 0.35); margin-top: 4px;">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="display: inline; vertical-align: middle; margin-right: 6px;"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            <span>Create Tournament</span>
          </button>
        </div>
      </form>
    </div>
  </div>
  <!-- 7. Host Open Play Modal -->
  <div class="app-modal-overlay" id="hostOpenPlayModal">
    <div class="modal-box-card" style="max-width: 490px; width: 92%; padding: 26px 28px; border-radius: 20px;">
      <!-- Header -->
      <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 18px;">
        <div style="display: flex; align-items: center; gap: 12px;">
          <div style="width: 38px; height: 38px; border-radius: 12px; background: rgba(255, 184, 0, 0.1); border: 1px solid rgba(255, 184, 0, 0.25); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="var(--pk-brand-amber)" stroke="none"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg>
          </div>
          <div>
            <h3 style="font-family: 'Montserrat', var(--font-heading), sans-serif; font-size: 18px; font-weight: 800; color: var(--pk-text-primary); margin: 0 0 2px;">Host Open Play</h3>
            <p style="font-size: 12px; color: var(--pk-text-muted); margin: 0;">Schedule a joinable match at your court</p>
          </div>
        </div>
        <button type="button" onclick="closeModal('hostOpenPlayModal')" style="background: rgba(255, 255, 255, 0.06); border: 1px solid rgba(255, 255, 255, 0.12); color: #94A3B8; width: 30px; height: 30px; min-width: 30px; min-height: 30px; max-width: 30px; max-height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 18px; line-height: 1; padding: 0; flex-shrink: 0; aspect-ratio: 1 / 1; cursor: pointer; transition: all 0.2s;" onmouseover="this.style.color='#FFFFFF'; this.style.background='rgba(255,255,255,0.16)';" onmouseout="this.style.color='#94A3B8'; this.style.background='rgba(255,255,255,0.06)'" aria-label="Close modal">&times;</button>
      </div>

      <form id="hostOpenPlayForm" autocomplete="off" onsubmit="submitHostOpenPlayForm(event)">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? ''); ?>">
        <div style="display: flex; flex-direction: column; gap: 14px;">
          
          <!-- Target Court Card -->
          <div style="background: var(--pk-bg-surface); border: 1px solid rgba(255, 184, 0, 0.25); border-radius: 12px; padding: 12px 14px; display: flex; justify-content: space-between; align-items: center;">
            <div style="display: flex; align-items: center; gap: 10px;">
              <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--pk-brand-amber)" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
              <div>
                <div id="modalTargetCourtName" style="font-size: 13.5px; font-weight: 800; color: var(--pk-brand-amber);">Court 2</div>
                <div id="modalTargetCourtSpecs" style="display: none;"></div>
              </div>
            </div>
            <span style="font-size: 10px; font-weight: 800; color: var(--pk-brand-amber); background: rgba(255, 184, 0, 0.12); border: 1px solid rgba(255, 184, 0, 0.3); border-radius: 9999px; padding: 3px 9px; letter-spacing: 0.05em; white-space: nowrap;">TARGET COURT</span>
          </div>

          <!-- Section: SESSION DATE -->
          <div>
            <div style="font-size: 10.5px; font-weight: 800; color: var(--pk-text-muted); letter-spacing: 0.06em; text-transform: uppercase; margin-bottom: 6px;">SESSION DATE</div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; background: var(--pk-bg-card); border-radius: 9999px; padding: 3px; border: 1px solid rgba(255, 255, 255, 0.08); margin-bottom: 8px;">
              <button type="button" id="btnDateSpecific" onclick="toggleOpenPlayDateMode('specific')" style="border-radius: 9999px; padding: 7px 10px; font-size: 12px; font-weight: 800; background: var(--pk-brand-amber); color: var(--pk-bg-card); border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 6px; transition: all 0.2s;">
                <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" x2="16" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg>
                <span>Specific Date</span>
              </button>
              <button type="button" id="btnDateEveryday" onclick="toggleOpenPlayDateMode('everyday')" style="border-radius: 9999px; padding: 7px 10px; font-size: 12px; font-weight: 600; background: transparent; color: var(--pk-text-muted); border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 6px; transition: all 0.2s;">
                <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 21h5v-5"/></svg>
                <span>Everyday 🔄</span>
              </button>
            </div>
            <!-- Date Input Row -->
            <div id="openPlayDatePickerWrap" style="position: relative;">
              <select id="openPlayDateInput" style="background: var(--pk-bg-card); border: 1px solid rgba(255, 255, 255, 0.1); padding: 9px 34px 9px 34px; border-radius: 10px; width: 100%; box-sizing: border-box; color: var(--pk-text-primary); font-size: 13px; font-weight: 700; appearance: none; -webkit-appearance: none; cursor: pointer;">
                <?php
                  $baseDate = new DateTime('2026-09-07');
                  for ($i = 0; $i < 30; $i++) {
                      $d = clone $baseDate;
                      if ($i > 0) $d->modify("+$i days");
                      $formatted = $d->format('D, M j, Y');
                      $extra = '';
                      if ($i === 0) $extra = ' (Today)';
                      else if ($i === 1) $extra = ' (Tomorrow)';
                      $selected = ($i === 0) ? ' selected' : '';
                      echo '<option value="' . htmlspecialchars($formatted) . '"' . $selected . ' style="background: #0F172A; color: #FFFFFF;">' . htmlspecialchars($formatted . $extra) . '</option>';
                  }
                ?>
              </select>
              <svg style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); pointer-events: none;" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--pk-brand-amber)" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" x2="16" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg>
              <svg style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); pointer-events: none;" xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="var(--pk-text-muted)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
            </div>
          </div>

          <!-- Section: TIME SLOT RANGE -->
          <div>
            <div style="font-size: 10.5px; font-weight: 800; color: var(--pk-text-muted); letter-spacing: 0.06em; text-transform: uppercase; margin-bottom: 6px;">TIME SLOT RANGE</div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
              <div>
                <label style="display: block; font-size: 11px; font-weight: 600; color: var(--pk-text-muted); margin-bottom: 4px;">Start Time</label>
                <div style="position: relative;">
                  <select id="openPlayStartTime" style="background: var(--pk-bg-card); border: 1px solid rgba(255, 255, 255, 0.1); padding: 9px 28px 9px 32px; border-radius: 10px; width: 100%; box-sizing: border-box; color: var(--pk-text-primary); font-size: 12.5px; font-weight: 700; appearance: none; -webkit-appearance: none; cursor: pointer;">
                    <option value="6:00 AM" selected>6:00 AM</option>
                    <option value="7:00 AM">7:00 AM</option>
                    <option value="8:00 AM">8:00 AM</option>
                    <option value="9:00 AM">9:00 AM</option>
                    <option value="4:00 PM">4:00 PM</option>
                    <option value="5:00 PM">5:00 PM</option>
                    <option value="6:00 PM">6:00 PM</option>
                    <option value="7:00 PM">7:00 PM</option>
                    <option value="8:00 PM">8:00 PM</option>
                  </select>
                  <svg style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); pointer-events: none;" xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="var(--pk-brand-amber)" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                  <svg style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); pointer-events: none;" xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="var(--pk-text-muted)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                </div>
              </div>

              <div>
                <label style="display: block; font-size: 11px; font-weight: 600; color: var(--pk-text-muted); margin-bottom: 4px;">End Time</label>
                <div style="position: relative;">
                  <select id="openPlayEndTime" style="background: var(--pk-bg-card); border: 1px solid rgba(255, 255, 255, 0.1); padding: 9px 28px 9px 32px; border-radius: 10px; width: 100%; box-sizing: border-box; color: var(--pk-text-primary); font-size: 12.5px; font-weight: 700; appearance: none; -webkit-appearance: none; cursor: pointer;">
                    <option value="10:00 PM">10:00 PM</option>
                    <option value="11:00 PM" selected>11:00 PM</option>
                    <option value="12:00 AM">12:00 AM</option>
                    <option value="8:00 AM">8:00 AM</option>
                    <option value="10:00 AM">10:00 AM</option>
                    <option value="12:00 PM">12:00 PM</option>
                  </select>
                  <svg style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); pointer-events: none;" xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="var(--pk-brand-amber)" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                  <svg style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); pointer-events: none;" xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="var(--pk-text-muted)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                </div>
              </div>
            </div>
          </div>

          <!-- Section: PLAYER CAPACITY & LIMIT -->
          <div>
            <div style="font-size: 10.5px; font-weight: 800; color: var(--pk-text-muted); letter-spacing: 0.06em; text-transform: uppercase; margin-bottom: 6px;">PLAYER CAPACITY & LIMIT</div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; background: var(--pk-bg-card); border-radius: 9999px; padding: 3px; border: 1px solid rgba(255, 255, 255, 0.08); margin-bottom: 8px;">
              <button type="button" id="btnCapMax" onclick="toggleOpenPlayCapMode('capped')" style="border-radius: 9999px; padding: 7px 10px; font-size: 12px; font-weight: 800; background: var(--pk-brand-amber); color: var(--pk-bg-card); border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 6px; transition: all 0.2s;">
                <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                <span>Max Player Cap</span>
              </button>
              <button type="button" id="btnCapUnlimited" onclick="toggleOpenPlayCapMode('unlimited')" style="border-radius: 9999px; padding: 7px 10px; font-size: 12px; font-weight: 600; background: transparent; color: var(--pk-text-muted); border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 6px; transition: all 0.2s;">
                <span>Unlimited Players ∞</span>
              </button>
            </div>
            <!-- Capacity Input with Suffix -->
            <div id="openPlayCapWrap" style="position: relative;">
              <input type="number" id="openPlayCap" value="20" min="4" max="64" style="background: var(--pk-bg-card); border: 1px solid rgba(255, 255, 255, 0.1); padding: 9px 100px 9px 14px; border-radius: 10px; width: 100%; box-sizing: border-box; color: var(--pk-text-primary); font-size: 13px; font-weight: 700;">
              <span style="position: absolute; right: 14px; top: 50%; transform: translateY(-50%); font-size: 11.5px; font-weight: 600; color: var(--pk-text-muted); pointer-events: none;">Max Players</span>
            </div>
          </div>

          <!-- Section: PRICE (₱ / PLAYER) -->
          <div>
            <div style="font-size: 10.5px; font-weight: 800; color: var(--pk-text-muted); letter-spacing: 0.06em; text-transform: uppercase; margin-bottom: 6px;">PRICE (₱ / PLAYER)</div>
            <div style="position: relative;">
              <input type="number" id="openPlayFee" value="250" min="0" max="5000" step="10" style="background: var(--pk-bg-card); border: 1px solid rgba(255, 255, 255, 0.1); padding: 9px 14px 9px 34px; border-radius: 10px; width: 100%; box-sizing: border-box; color: var(--pk-text-primary); font-size: 13px; font-weight: 700;">
              <span style="position: absolute; left: 14px; top: 50%; transform: translateY(-50%); font-size: 13.5px; font-weight: 800; color: var(--pk-brand-amber); pointer-events: none;">₱</span>
            </div>
          </div>

          <!-- Footer Action Buttons -->
          <div style="display: flex; justify-content: flex-end; align-items: center; gap: 14px; margin-top: 6px;">
            <button type="button" onclick="closeModal('hostOpenPlayModal')" style="background: none; border: none; color: var(--pk-text-muted); font-size: 13px; font-weight: 600; cursor: pointer; padding: 6px 12px; transition: color 0.2s;" onmouseover="this.style.color='var(--pk-text-primary)'" onmouseout="this.style.color='var(--pk-text-muted)'">Cancel</button>
            <button type="submit" style="background: var(--pk-brand-amber); border: none; border-radius: 10px; color: var(--pk-bg-card); font-size: 13px; font-weight: 800; padding: 10px 20px; display: flex; align-items: center; gap: 8px; cursor: pointer; transition: background 0.2s; box-shadow: 0 4px 14px rgba(255, 184, 0, 0.25);" onmouseover="this.style.background='var(--pk-brand-amber-hover)'" onmouseout="this.style.background='var(--pk-brand-amber)'">
              <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="var(--pk-bg-card)" stroke="none"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg>
              <span>Host Open Play</span>
            </button>
          </div>

        </div>
      </form>
    </div>
  </div>



  <!-- 9. End Court Session Confirmation Modal -->
  <div class="app-modal-overlay" id="endCourtSessionModal">
    <div class="modal-box-card" style="max-width:420px; text-align:center;">
      <div style="padding:12px 6px;">
        <div class="confirm-modal-icon red">
          <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
        </div>
        <h3 class="confirm-modal-title">End Session Early?</h3>
        <p class="confirm-modal-desc">
          Are you sure you want to end the active session on <strong id="endCourtSessionTargetName">Court 1</strong>? The court timer will stop and status will reset to Available.
        </p>
        <input type="hidden" id="endCourtSessionTargetId" value="">
        <div style="display:flex; gap:12px;">
          <button type="button" onclick="closeModal('endCourtSessionModal')" class="btn-modal-cancel">
            Keep Playing
          </button>
          <button type="button" onclick="executeEndCourtSession()" class="btn-modal-danger">
            Yes, End Session
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- 10. Revoke Staff Member Confirmation Modal -->
  <div class="app-modal-overlay" id="revokeStaffModal">
    <div class="modal-box-card" style="max-width:420px; text-align:center;">
      <div style="padding:12px 6px;">
        <div class="confirm-modal-icon red">
          <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="18" x2="23" y1="8" y2="13"/><line x1="23" x2="18" y1="8" y2="13"/></svg>
        </div>
        <h3 class="confirm-modal-title">Revoke Staff Access?</h3>
        <p class="confirm-modal-desc">
          Are you sure you want to revoke administrative permissions for <strong id="revokeStaffTargetName">Staff Member</strong>? They will immediately lose access to this venue portal.
        </p>
        <div style="display:flex; gap:12px;">
          <button type="button" onclick="closeModal('revokeStaffModal')" class="btn-modal-cancel">
            Cancel
          </button>
          <button type="button" onclick="executeRevokeStaff()" class="btn-modal-danger">
            Yes, Revoke Access
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- 11. Disable / Remove Court Confirmation Modal -->
  <div class="app-modal-overlay" id="disableCourtModal">
    <div class="modal-box-card" style="max-width:420px; text-align:center;">
      <div style="padding:12px 6px;">
        <div class="confirm-modal-icon red" style="background:rgba(245,158,11,0.15); color: var(--pk-brand-amber); border-color:rgba(245,158,11,0.25);">
          <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18.36 6.64a9 9 0 1 1-12.73 0"/><line x1="12" x2="12" y1="2" y2="12"/></svg>
        </div>
        <h3 class="confirm-modal-title">Disable Court?</h3>
        <p class="confirm-modal-desc">
          Are you sure you want to take <strong id="disableCourtTargetName">Court 1</strong> offline? Players will not be able to make new reservations on this court until re-enabled.
        </p>
        <div style="display:flex; gap:12px;">
          <button type="button" onclick="closeModal('disableCourtModal')" class="btn-modal-cancel">
            Keep Active
          </button>
          <button type="button" onclick="executeDisableCourt()" class="btn-modal-danger" style="background: var(--pk-brand-amber); color: var(--pk-bg-card) !important; box-shadow:0 4px 14px rgba(245,158,11,0.35);">
            Yes, Take Offline
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- 11b. Delete Court Confirmation Modal -->
  <div class="app-modal-overlay" id="deleteCourtModal">
    <div class="modal-box-card" style="max-width:420px; text-align:center;">
      <div style="padding:12px 6px;">
        <div class="confirm-modal-icon red">
          <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
        </div>
        <h3 class="confirm-modal-title">Delete Court Permanently?</h3>
        <p class="confirm-modal-desc">
          Are you sure you want to permanently delete <strong id="deleteCourtTargetName">Court 1</strong>? This action will remove the court from your facility inventory.
        </p>

        <div style="margin: 14px 0 16px; text-align: left;">
          <label style="display: block; font-size: 11.5px; font-weight: 700; color: var(--pk-text-muted); margin-bottom: 6px; text-align: center;">
            Type <strong style="color: #EF4444; font-weight: 800;">DELETE</strong> below to confirm:
          </label>
          <input type="text" id="deleteCourtConfirmInput" class="tb-input" placeholder="DELETE" autocomplete="off"
                 oninput="onDeleteCourtConfirmInput(this.value)"
                 style="text-align: center; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; border: 1px solid rgba(239, 68, 68, 0.4); background: rgba(15, 23, 42, 0.6); padding: 10px; width: 100%; border-radius: 10px; color: #FFFFFF;">
        </div>

        <div style="display:flex; gap:12px;">
          <button type="button" onclick="closeModal('deleteCourtModal')" class="btn-modal-cancel">
            Cancel
          </button>
          <button type="button" id="btnConfirmDeleteCourt" onclick="executeDeleteCourt()" class="btn-modal-danger" disabled style="opacity: 0.4; cursor: not-allowed;">
            Yes, Delete Court
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- 11.5. Delete Tournament Confirmation Modal -->
  <div class="app-modal-overlay" id="deleteTournamentModal">
    <div class="modal-box-card" style="max-width:420px; text-align:center;">
      <div style="padding:12px 6px;">
        <div class="confirm-modal-icon red">
          <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
        </div>
        <h3 class="confirm-modal-title">Delete Tournament Permanently?</h3>
        <p class="confirm-modal-desc">
          Are you sure you want to permanently delete <strong id="deleteTournamentTargetName">Tournament</strong>? Its roster, bracket draw, and all reported match results will be removed permanently.
        </p>

        <div style="margin: 14px 0 16px; text-align: left;">
          <label style="display: block; font-size: 11.5px; font-weight: 700; color: var(--pk-text-muted); margin-bottom: 6px; text-align: center;">
            Type <strong style="color: #EF4444; font-weight: 800;">DELETE</strong> below to confirm:
          </label>
          <input type="text" id="deleteTournamentConfirmInput" class="tb-input" placeholder="DELETE" autocomplete="off"
                 oninput="onDeleteTournamentConfirmInput(this.value)"
                 style="text-align: center; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; border: 1px solid rgba(239, 68, 68, 0.4); background: rgba(15, 23, 42, 0.6); padding: 10px; width: 100%; border-radius: 10px; color: #FFFFFF;">
        </div>

        <div style="display:flex; gap:12px;">
          <button type="button" onclick="closeModal('deleteTournamentModal')" class="btn-modal-cancel">
            Cancel
          </button>
          <button type="button" id="btnConfirmDeleteTournament" onclick="executeDeleteTournament()" class="btn-modal-danger" disabled style="opacity: 0.4; cursor: not-allowed;">
            Yes, Delete Tournament
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- 12. Cancel Open Play Confirmation Modal -->
  <div class="app-modal-overlay" id="cancelOpenPlayModal">
    <div class="modal-box-card" style="max-width:420px; text-align:center;">
      <div style="padding:12px 6px;">
        <div class="confirm-modal-icon red">
          <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" x2="9" y1="9" y2="15"/><line x1="9" x2="15" y1="9" y2="15"/></svg>
        </div>
        <h3 class="confirm-modal-title">Cancel Open Play?</h3>
        <p class="confirm-modal-desc">
          Are you sure you want to cancel <strong id="cancelOpenPlayTargetTitle">Open Play Session</strong>? All registered players will receive a 100% automatic refund.
        </p>

        <div style="margin: 14px 0 16px; text-align: left;">
          <label style="display: block; font-size: 11.5px; font-weight: 700; color: var(--pk-text-muted); margin-bottom: 6px; text-align: center;">
            Type <strong style="color: #EF4444; font-weight: 800;">CANCEL</strong> below to confirm:
          </label>
          <input type="text" id="cancelOpenPlayConfirmInput" class="tb-input" placeholder="CANCEL" autocomplete="off"
                 oninput="onCancelOpenPlayConfirmInput(this.value)"
                 style="text-align: center; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; border: 1px solid rgba(239, 68, 68, 0.4); background: rgba(15, 23, 42, 0.6); padding: 10px; width: 100%; border-radius: 10px; color: #FFFFFF;">
        </div>

        <div style="display:flex; gap:12px;">
          <button type="button" onclick="closeModal('cancelOpenPlayModal')" class="btn-modal-cancel">
            Keep Session
          </button>
          <button type="button" id="btnConfirmCancelOpenPlay" onclick="executeCancelOpenPlay()" class="btn-modal-danger" disabled style="opacity: 0.4; cursor: not-allowed;">
            Yes, Cancel &amp; Refund
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- 13. Owner Sign Out Confirmation Modal -->
  <div class="app-modal-overlay" id="ownerLogoutModal">
    <div class="modal-box-card" style="max-width:400px; text-align:center;">
      <div style="padding:12px 6px;">
        <div class="confirm-modal-icon red">
          <svg xmlns="http://www.w3.org/2000/svg" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/></svg>
        </div>
        <h3 class="confirm-modal-title">Sign Out of Owner Portal?</h3>
        <p class="confirm-modal-desc">You will need to sign in again to manage your courts, staff, and view earnings.</p>
        <div style="display:flex; gap:12px;">
          <button type="button" onclick="closeModal('ownerLogoutModal')" class="btn-modal-cancel">
            Cancel
          </button>
          <a href="<?= \Picklers\Helpers\Url::to('auth?logout=1') ?>" class="btn-modal-danger">
            Sign Out
          </a>
        </div>
      </div>
    </div>
  </div>

  <!-- 14. Notifications Modal (Matches Player App Modal) -->
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
          <button type="button" class="modal-close-btn" onclick="closeModal('notifModal')" style="font-size: 18px; line-height: 1; background:none; border:none; color: var(--pk-text-muted); cursor:pointer;">✕</button>
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
                <?php elseif (stripos($n['title'] ?? '', 'Top-Up') !== false || stripos($n['title'] ?? '', 'Credit') !== false || stripos($n['title'] ?? '', 'Wallet') !== false || stripos($n['title'] ?? '', 'Payout') !== false): ?>
                  <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                <?php elseif (stripos($n['title'] ?? '', 'Verified') !== false || stripos($n['title'] ?? '', 'Identity') !== false || stripos($n['title'] ?? '', 'Audit') !== false): ?>
                  <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg>
                <?php elseif (stripos($n['title'] ?? '', 'Booking') !== false || stripos($n['title'] ?? '', 'Court') !== false || stripos($n['title'] ?? '', 'Request') !== false || stripos($n['title'] ?? '', 'Reservation') !== false): ?>
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
            <div style="font-size: 12px; color: rgba(255, 255, 255, 0.55);">When players book courts or scan QR passes, updates will show up here.</div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- 12. Daily Revenue & Past 30 Days Income Breakdown Modal -->
  <div class="app-modal-overlay" id="dailyRevenueModal" style="display:none; z-index:2000;">
    <div class="modal-box-card" style="max-width:760px; width:95%; max-height:90vh; display:flex; flex-direction:column; padding:24px;">
      
      <!-- Modal Top Header -->
      <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:18px; border-bottom:1px solid rgba(255,255,255,0.08); padding-bottom:14px;">
        <div style="display:flex; align-items:center; gap:12px;">
          <div style="width:38px; height:38px; border-radius:12px; background:rgba(0,217,139,0.14); border:1px solid rgba(0,217,139,0.3); display:flex; align-items:center; justify-content:center; color: #00D98B; flex-shrink:0;">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h7a5.5 5.5 0 0 1 0 11H6z"/><line x1="3" y1="7" x2="18" y2="7"/><line x1="3" y1="11" x2="18" y2="11"/><line x1="6" y1="3" x2="6" y2="21"/></svg>
          </div>
          <div>
            <h3 style="font-size:20px; font-weight:900; color: #FFFFFF; margin:0; font-family:'Montserrat', sans-serif; letter-spacing:-0.01em;">Daily Income Breakdown</h3>
            <p style="font-size:12.5px; color: #94A3B8; margin:2px 0 0; font-weight:500;">Past 30 Days daily court gross earnings • Month of September 2026</p>
          </div>
        </div>
        <button type="button" class="btn-close-modal" onclick="closeModal('dailyRevenueModal')" title="Close" style="background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.1); color: #94A3B8; width:32px; height:32px; border-radius:50%; display:flex; align-items:center; justify-content:center; cursor:pointer; font-size:18px; transition:all 0.2s; flex-shrink:0;">&times;</button>
      </div>

      <!-- Quick KPI Summary Strip -->
      <div class="daily-rev-summary-grid">
        <div class="daily-rev-summary-box">
          <span class="daily-rev-summary-label">September Total</span>
          <span class="daily-rev-summary-val" style="color: #00D98B;">₱48,200</span>
          <span style="font-size:11px; color: #94A3B8;">Gross booking revenue</span>
        </div>
        <div class="daily-rev-summary-box" style="border-color:rgba(0,229,255,0.3) !important; background:linear-gradient(135deg, rgba(0,229,255,0.08), rgba(11,24,43,0.95)) !important;">
          <span class="daily-rev-summary-label" style="color: #00E5FF;">Yesterday (Sep 7)</span>
          <span class="daily-rev-summary-val" style="color: #00E5FF;">₱2,800</span>
          <span style="font-size:11px; color: #00E5FF;">7 sessions booked</span>
        </div>
        <div class="daily-rev-summary-box" style="border-color:rgba(255,184,0,0.3) !important; background:linear-gradient(135deg, rgba(255,184,0,0.08), rgba(11,24,43,0.95)) !important;">
          <span class="daily-rev-summary-label" style="color: #FFB800;">Peak Day (Sep 6)</span>
          <span class="daily-rev-summary-val" style="color: #FFB800;">₱5,400</span>
          <span style="font-size:11px; color: #94A3B8;">Saturday tournament</span>
        </div>
        <div class="daily-rev-summary-box">
          <span class="daily-rev-summary-label">Daily Average</span>
          <span class="daily-rev-summary-val" style="color: #FFFFFF;">₱1,606</span>
          <span style="font-size:11px; color: #94A3B8;">Per operational day</span>
        </div>
      </div>

      <!-- Interactive Filter Pills & Search -->
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; gap:10px; flex-wrap:wrap;">
        <div style="display:flex; gap:8px; flex-wrap:wrap;" id="dailyRevFilterBtns">
          <button type="button" class="daily-rev-filter-btn active" onclick="filterDailyRev('all', this)">All 30 Days</button>
          <button type="button" class="daily-rev-filter-btn" onclick="filterDailyRev('september', this)">September (MTD)</button>
          <button type="button" class="daily-rev-filter-btn" onclick="filterDailyRev('weekends', this)">Weekends Only</button>
          <button type="button" class="daily-rev-filter-btn" onclick="filterDailyRev('high', this)">High Volume (₱3k+)</button>
        </div>
        <div style="position:relative; min-width:180px; flex:1; max-width:220px;">
          <input type="text" id="dailyRevSearchInput" onkeyup="searchDailyRevTable()" placeholder="Search date or day..." style="background: rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.12); border-radius:9999px; padding:7px 14px 7px 34px; font-size:12px; color: #FFFFFF; width:100%; box-sizing:border-box; font-family:'Montserrat', sans-serif;">
          <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#94A3B8" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="position:absolute; left:12px; top:50%; transform:translateY(-50%); pointer-events:none;"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        </div>
      </div>

      <!-- Scrollable Daily Breakdown Table -->
      <div class="daily-rev-table-container">
        <table class="daily-rev-table" id="dailyRevenueTable">
          <thead>
            <tr>
              <th>Date &amp; Day</th>
              <th>Sessions</th>
              <th>Top Activity / Court</th>
              <th>Volume Bar</th>
              <th style="text-align:right;">Daily Income</th>
              <th style="text-align:center;">Status</th>
            </tr>
          </thead>
          <tbody>
            <?php
              $dailyRecords = [
                ['date' => 'Sep 7, 2026', 'dow' => 'Sunday', 'is_yesterday' => true, 'is_peak' => false, 'is_weekend' => true, 'month' => 'september', 'sessions' => 7, 'players' => 24, 'court' => 'Court 1 & 3', 'revenue' => 2800],
                ['date' => 'Sep 6, 2026', 'dow' => 'Saturday', 'is_yesterday' => false, 'is_peak' => true, 'is_weekend' => true, 'month' => 'september', 'sessions' => 15, 'players' => 48, 'court' => 'Championship Open Play', 'revenue' => 5400],
                ['date' => 'Sep 5, 2026', 'dow' => 'Friday', 'is_yesterday' => false, 'is_peak' => false, 'is_weekend' => false, 'month' => 'september', 'sessions' => 11, 'players' => 36, 'court' => 'Court 2 & 4', 'revenue' => 4200],
                ['date' => 'Sep 4, 2026', 'dow' => 'Thursday', 'is_yesterday' => false, 'is_peak' => false, 'is_weekend' => false, 'month' => 'september', 'sessions' => 6, 'players' => 20, 'court' => 'Court 1', 'revenue' => 2300],
                ['date' => 'Sep 3, 2026', 'dow' => 'Wednesday', 'is_yesterday' => false, 'is_peak' => false, 'is_weekend' => false, 'month' => 'september', 'sessions' => 5, 'players' => 18, 'court' => 'Court 5', 'revenue' => 2000],
                ['date' => 'Sep 2, 2026', 'dow' => 'Tuesday', 'is_yesterday' => false, 'is_peak' => false, 'is_weekend' => false, 'month' => 'september', 'sessions' => 4, 'players' => 16, 'court' => 'Court 2', 'revenue' => 1800],
                ['date' => 'Sep 1, 2026', 'dow' => 'Monday', 'is_yesterday' => false, 'is_peak' => false, 'is_weekend' => false, 'month' => 'september', 'sessions' => 4, 'players' => 14, 'court' => 'Court 3', 'revenue' => 1600],
                ['date' => 'Aug 31, 2026', 'dow' => 'Sunday', 'is_yesterday' => false, 'is_peak' => false, 'is_weekend' => true, 'month' => 'august', 'sessions' => 9, 'players' => 32, 'court' => 'Court 1 & 2', 'revenue' => 3600],
                ['date' => 'Aug 30, 2026', 'dow' => 'Saturday', 'is_yesterday' => false, 'is_peak' => false, 'is_weekend' => true, 'month' => 'august', 'sessions' => 10, 'players' => 36, 'court' => 'Open Play Special', 'revenue' => 4100],
                ['date' => 'Aug 29, 2026', 'dow' => 'Friday', 'is_yesterday' => false, 'is_peak' => false, 'is_weekend' => false, 'month' => 'august', 'sessions' => 8, 'players' => 26, 'court' => 'Court 5', 'revenue' => 2900],
                ['date' => 'Aug 28, 2026', 'dow' => 'Thursday', 'is_yesterday' => false, 'is_peak' => false, 'is_weekend' => false, 'month' => 'august', 'sessions' => 4, 'players' => 16, 'court' => 'Court 3', 'revenue' => 1700],
                ['date' => 'Aug 27, 2026', 'dow' => 'Wednesday', 'is_yesterday' => false, 'is_peak' => false, 'is_weekend' => false, 'month' => 'august', 'sessions' => 4, 'players' => 14, 'court' => 'Court 1', 'revenue' => 1500],
                ['date' => 'Aug 26, 2026', 'dow' => 'Tuesday', 'is_yesterday' => false, 'is_peak' => false, 'is_weekend' => false, 'month' => 'august', 'sessions' => 3, 'players' => 12, 'court' => 'Court 2', 'revenue' => 1400],
                ['date' => 'Aug 25, 2026', 'dow' => 'Monday', 'is_yesterday' => false, 'is_peak' => false, 'is_weekend' => false, 'month' => 'august', 'sessions' => 4, 'players' => 14, 'court' => 'Court 4', 'revenue' => 1600],
                ['date' => 'Aug 24, 2026', 'dow' => 'Sunday', 'is_yesterday' => false, 'is_peak' => false, 'is_weekend' => true, 'month' => 'august', 'sessions' => 8, 'players' => 28, 'court' => 'Court 1 & 3', 'revenue' => 3400],
                ['date' => 'Aug 23, 2026', 'dow' => 'Saturday', 'is_yesterday' => false, 'is_peak' => false, 'is_weekend' => true, 'month' => 'august', 'sessions' => 9, 'players' => 34, 'court' => 'Court 1 & 2', 'revenue' => 3800],
                ['date' => 'Aug 22, 2026', 'dow' => 'Friday', 'is_yesterday' => false, 'is_peak' => false, 'is_weekend' => false, 'month' => 'august', 'sessions' => 6, 'players' => 22, 'court' => 'Court 2', 'revenue' => 2500],
                ['date' => 'Aug 21, 2026', 'dow' => 'Thursday', 'is_yesterday' => false, 'is_peak' => false, 'is_weekend' => false, 'month' => 'august', 'sessions' => 4, 'players' => 16, 'court' => 'Court 5', 'revenue' => 1800],
                ['date' => 'Aug 20, 2026', 'dow' => 'Wednesday', 'is_yesterday' => false, 'is_peak' => false, 'is_weekend' => false, 'month' => 'august', 'sessions' => 3, 'players' => 12, 'court' => 'Court 3', 'revenue' => 1300],
                ['date' => 'Aug 19, 2026', 'dow' => 'Tuesday', 'is_yesterday' => false, 'is_peak' => false, 'is_weekend' => false, 'month' => 'august', 'sessions' => 3, 'players' => 10, 'court' => 'Court 1', 'revenue' => 1200],
                ['date' => 'Aug 18, 2026', 'dow' => 'Monday', 'is_yesterday' => false, 'is_peak' => false, 'is_weekend' => false, 'month' => 'august', 'sessions' => 3, 'players' => 10, 'court' => 'Court 4', 'revenue' => 1100],
                ['date' => 'Aug 17, 2026', 'dow' => 'Sunday', 'is_yesterday' => false, 'is_peak' => false, 'is_weekend' => true, 'month' => 'august', 'sessions' => 7, 'players' => 24, 'court' => 'Court 1 & 2', 'revenue' => 2700],
                ['date' => 'Aug 16, 2026', 'dow' => 'Saturday', 'is_yesterday' => false, 'is_peak' => false, 'is_weekend' => true, 'month' => 'august', 'sessions' => 8, 'players' => 30, 'court' => 'Open Play Session', 'revenue' => 3200],
                ['date' => 'Aug 15, 2026', 'dow' => 'Friday', 'is_yesterday' => false, 'is_peak' => false, 'is_weekend' => false, 'month' => 'august', 'sessions' => 5, 'players' => 18, 'court' => 'Court 3', 'revenue' => 2100],
                ['date' => 'Aug 14, 2026', 'dow' => 'Thursday', 'is_yesterday' => false, 'is_peak' => false, 'is_weekend' => false, 'month' => 'august', 'sessions' => 4, 'players' => 14, 'court' => 'Court 2', 'revenue' => 1500],
                ['date' => 'Aug 13, 2026', 'dow' => 'Wednesday', 'is_yesterday' => false, 'is_peak' => false, 'is_weekend' => false, 'month' => 'august', 'sessions' => 3, 'players' => 12, 'court' => 'Court 1', 'revenue' => 1200],
                ['date' => 'Aug 12, 2026', 'dow' => 'Tuesday', 'is_yesterday' => false, 'is_peak' => false, 'is_weekend' => false, 'month' => 'august', 'sessions' => 2, 'players' => 8, 'court' => 'Court 4', 'revenue' => 900],
                ['date' => 'Aug 11, 2026', 'dow' => 'Monday', 'is_yesterday' => false, 'is_peak' => false, 'is_weekend' => false, 'month' => 'august', 'sessions' => 2, 'players' => 8, 'court' => 'Court 5', 'revenue' => 800],
                ['date' => 'Aug 10, 2026', 'dow' => 'Sunday', 'is_yesterday' => false, 'is_peak' => false, 'is_weekend' => true, 'month' => 'august', 'sessions' => 6, 'players' => 20, 'court' => 'Court 1 & 2', 'revenue' => 2400],
                ['date' => 'Aug 9, 2026', 'dow' => 'Saturday', 'is_yesterday' => false, 'is_peak' => false, 'is_weekend' => true, 'month' => 'august', 'sessions' => 7, 'players' => 26, 'court' => 'Court 3', 'revenue' => 2900]
              ];
              $peakVal = 5400;
              foreach ($dailyRecords as $row):
                $pct = min(100, round(($row['revenue'] / $peakVal) * 100));
                $rowClass = '';
                if ($row['is_yesterday']) $rowClass = 'row-yesterday';
                elseif ($row['is_peak']) $rowClass = 'row-peak';
            ?>
              <tr class="<?php echo $rowClass; ?>" data-month="<?php echo $row['month']; ?>" data-weekend="<?php echo $row['is_weekend'] ? '1' : '0'; ?>" data-rev="<?php echo $row['revenue']; ?>">
                <td>
                  <div style="font-weight:700; color: var(--pk-text-primary); display:flex; align-items:center; gap:6px;">
                    <?php echo htmlspecialchars($row['date']); ?>
                    <?php if ($row['is_yesterday']): ?>
                      <span style="background:rgba(0,229,255,0.18); border:1px solid rgba(0,229,255,0.35); color: var(--pk-status-info); font-size:10px; font-weight:800; padding:1px 6px; border-radius:4px; text-transform:uppercase;">Yesterday</span>
                    <?php elseif ($row['is_peak']): ?>
                      <span style="background:rgba(255,184,0,0.18); border:1px solid rgba(255,184,0,0.35); color: var(--pk-brand-amber); font-size:10px; font-weight:800; padding:1px 6px; border-radius:4px; text-transform:uppercase;">Peak Day</span>
                    <?php endif; ?>
                  </div>
                  <div style="font-size:11px; color: var(--pk-text-muted); margin-top:2px;"><?php echo htmlspecialchars($row['dow']); ?></div>
                </td>
                <td>
                  <div style="font-weight:600; color: var(--pk-text-primary);"><?php echo $row['sessions']; ?> slots</div>
                  <div style="font-size:11px; color: var(--pk-text-muted);"><?php echo $row['players']; ?> players</div>
                </td>
                <td style="color: var(--pk-text-muted); font-size:12px;">
                  <?php echo htmlspecialchars($row['court']); ?>
                </td>
                <td>
                  <div class="daily-bar-wrap">
                    <div class="daily-bar-fill <?php echo $row['is_peak'] ? 'peak' : ''; ?>" style="width: <?php echo $pct; ?>%;"></div>
                  </div>
                  <span style="font-size:11px; color: var(--pk-text-muted);"><?php echo $pct; ?>%</span>
                </td>
                <td style="text-align:right; font-weight:800; font-family:'Montserrat', sans-serif; font-size:14px; <?php echo $row['is_peak'] ? 'color: var(--pk-status-info);' : ($row['is_yesterday'] ? 'color: var(--pk-status-info);' : 'color: var(--pk-status-success);'); ?>">
                  ₱<?php echo number_format($row['revenue']); ?>
                </td>
                <td style="text-align:center;">
                  <span style="background: var(--pk-status-success-bg); color: var(--pk-status-success); font-size:11px; font-weight:700; padding:3px 8px; border-radius:6px; border:1px solid var(--pk-status-success);">Settled</span>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- Footer Actions -->
      <div style="margin-top:16px; display:flex; justify-content:space-between; align-items:center;">
        <span style="font-size:12px; color: var(--pk-text-muted);">Showing 30 operational days breakdown</span>
        <button type="button" class="btn-walkin-open" style="padding:8px 18px; font-size:13px;" onclick="closeModal('dailyRevenueModal')">Close Breakdown</button>
      </div>

    </div>
  </div>

  <!-- Add Staff Member Modal -->
  <div class="app-modal-overlay" id="addStaffModal">
    <div class="modal-box-card" style="max-width: 480px; width: 92%; padding: 26px 28px; border-radius: 20px; overflow: visible;">
      <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 18px;">
        <div style="display: flex; align-items: center; gap: 12px;">
          <div style="width: 38px; height: 38px; border-radius: 12px; background: rgba(0, 217, 139, 0.12); border: 1px solid rgba(0, 217, 139, 0.3); display: flex; align-items: center; justify-content: center; color: #00D98B; flex-shrink: 0;">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
          </div>
          <div>
            <h3 style="font-family: 'Montserrat', var(--font-heading), sans-serif; font-size: 18px; font-weight: 800; color: var(--pk-text-primary); margin: 0 0 2px;">Add Staff</h3>
            <p style="font-size: 12px; color: var(--pk-text-muted); margin: 0;">Assign staff roles and facility access</p>
          </div>
        </div>
        <button type="button" onclick="closeModal('addStaffModal')" style="background: none; border: none; color: var(--pk-text-muted); font-size: 20px; cursor: pointer;">&times;</button>
      </div>

      <form id="addStaffForm" autocomplete="off" onsubmit="submitAddStaffForm(event)">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? ''); ?>">
        <div style="display: flex; flex-direction: column; gap: 14px;">
          <div style="position: relative;">
            <label style="display: flex; justify-content: space-between; align-items: center; font-size: 12px; font-weight: 700; color: var(--pk-text-muted); margin-bottom: 6px;">
              <span>Staff Full Name</span>
              <span style="font-size: 11px; font-weight: 500; color: #FFFFFF;">Search registered players</span>
            </label>
            <input type="text" id="staffNameInput" required placeholder="Type player name to search (e.g. D)..." autocomplete="off" oninput="onStaffNameInput(this.value)" onfocus="onStaffNameInput(this.value)" style="background: var(--pk-bg-card); border: 1px solid rgba(0, 229, 255, 0.3); padding: 10px 14px; border-radius: 10px; width: 100%; color: var(--pk-text-primary); font-size: 13px;">
            <div id="staffNameAutocomplete" class="autocomplete-dropdown-menu" style="display: none; position: absolute; top: 100%; left: 0; right: 0; z-index: 9999; background: #0B1728; border: 1px solid rgba(0, 229, 255, 0.35); border-radius: 12px; max-height: 220px; overflow-y: auto; margin-top: 6px; box-shadow: 0 12px 28px rgba(0,0,0,0.65);"></div>
          </div>
          <div>
            <label style="display: block; font-size: 12px; font-weight: 700; color: var(--pk-text-muted); margin-bottom: 6px;">Email Address</label>
            <input type="email" id="staffEmailInput" required placeholder="e.g. maria@bgcpickle.ph" style="background: var(--pk-bg-card); border: 1px solid rgba(255, 255, 255, 0.1); padding: 10px 14px; border-radius: 10px; width: 100%; color: var(--pk-text-primary); font-size: 13px;">
          </div>
          </div>
          <button type="submit" class="btn-walkin-open" style="width: 100%; justify-content: center; margin-top: 8px; font-weight: 800;">
            <span>Grant Staff Access</span>
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Add / List Court Modal -->
  <div class="app-modal-overlay" id="addCourtModal">
    <div class="modal-box-card" style="max-width: 500px; width: 92%; padding: 26px 28px; border-radius: 20px;">
      <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 18px;">
        <div style="display: flex; align-items: center; gap: 12px;">
          <div style="width: 38px; height: 38px; border-radius: 12px; background: rgba(0, 217, 139, 0.12); border: 1px solid rgba(0, 217, 139, 0.3); display: flex; align-items: center; justify-content: center; color: #00D98B; flex-shrink: 0;">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2.5"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="8" x2="21" y2="8"/><line x1="3" y1="16" x2="21" y2="16"/><line x1="12" y1="3" x2="12" y2="8"/><line x1="12" y1="16" x2="12" y2="21"/></svg>
          </div>
          <div>
            <h3 style="font-family: 'Montserrat', var(--font-heading), sans-serif; font-size: 18px; font-weight: 800; color: var(--pk-text-primary); margin: 0 0 2px;">List New Court</h3>
            <p style="font-size: 12px; color: var(--pk-text-muted); margin: 0;">Add a physical pickleball court to your venue inventory</p>
          </div>
        </div>
        <button type="button" onclick="closeModal('addCourtModal')" style="background: none; border: none; color: var(--pk-text-muted); font-size: 20px; cursor: pointer;">&times;</button>
      </div>

      <form id="addCourtForm" autocomplete="off" onsubmit="submitAddCourtForm(event)">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? ''); ?>">
        <div style="display: flex; flex-direction: column; gap: 14px;">
          <div>
            <label style="display: block; font-size: 12px; font-weight: 700; color: var(--pk-text-muted); margin-bottom: 6px;">Court Title</label>
            <input type="text" id="addCourtName" required placeholder="Court 1" readonly style="background: var(--pk-bg-card); border: 1px solid rgba(255, 255, 255, 0.1); padding: 10px 14px; border-radius: 10px; width: 100%; color: var(--pk-text-primary); font-size: 13px; cursor: not-allowed; opacity: 0.85;">
            <span style="font-size: 11px; color: var(--pk-text-muted); margin-top: 4px; display: block;">Standard court naming (e.g. Court 1, Court 2, Court 3)</span>
          </div>
          <div>
            <label style="display: block; font-size: 12px; font-weight: 700; color: var(--pk-text-muted); margin-bottom: 6px;">Hourly Rate (₱)</label>
            <input type="number" id="addCourtRate" required value="450" min="100" max="5000" style="background: var(--pk-bg-card); border: 1px solid rgba(255, 255, 255, 0.1); padding: 10px 14px; border-radius: 10px; width: 100%; color: var(--pk-text-primary); font-size: 13px;">
          </div>
          <button type="submit" class="btn-walkin-open" style="width: 100%; justify-content: center; margin-top: 8px; font-weight: 800;">
            <span>Save & List Court</span>
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- 7. Open Play Roster & Players Modal -->
  <div class="app-modal-overlay" id="openPlayRosterModal" style="display: none; position: fixed; inset: 0; background: rgba(5, 11, 20, 0.82); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); z-index: 9999; align-items: center; justify-content: center; padding: 16px;">
    <div class="modal-box-card" style="max-width: 520px; width: 100%; padding: 0; border-radius: 24px; background: rgba(11, 19, 34, 0.96); border: 1px solid rgba(255, 255, 255, 0.12); overflow: hidden; box-shadow: 0 30px 70px rgba(0, 0, 0, 0.7), 0 0 40px rgba(0, 217, 139, 0.12); display: flex; flex-direction: column;">
      
      <!-- Modal Header -->
      <div style="padding: 20px 24px; background: linear-gradient(180deg, rgba(15, 26, 46, 0.9), rgba(11, 19, 34, 0.9)); border-bottom: 1px solid rgba(255, 255, 255, 0.08); display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 10;">
        <div style="display: flex; align-items: center; gap: 14px;">
          <div style="width: 44px; height: 44px; border-radius: 14px; background: linear-gradient(135deg, rgba(0, 217, 139, 0.2), rgba(6, 182, 212, 0.2)); border: 1px solid rgba(0, 217, 139, 0.4); display: flex; align-items: center; justify-content: center; color: #00D98B; flex-shrink: 0; box-shadow: 0 4px 14px rgba(0, 217, 139, 0.25);">
            <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
              <circle cx="9" cy="7" r="4"/>
              <path d="M22 21v-2a4 4 0 0 0-3-3.87"/>
              <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
            </svg>
          </div>
          <div>
            <h3 id="openPlayRosterTitle" style="font-family: 'Montserrat', sans-serif; font-size: 18px; font-weight: 800; color: #FFFFFF; margin: 0 0 2px; letter-spacing: -0.01em;">Joined Players</h3>
            <p id="openPlayRosterSubtitle" style="font-size: 12px; color: #94A3B8; margin: 0; font-weight: 600;">Court 2 • Open Play Session</p>
          </div>
        </div>
        <button type="button" onclick="closeModal('openPlayRosterModal')" style="background: rgba(255, 255, 255, 0.06); border: 1px solid rgba(255, 255, 255, 0.12); color: #94A3B8; width: 34px; height: 34px; min-width: 34px; min-height: 34px; max-width: 34px; max-height: 34px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 18px; line-height: 1; padding: 0; flex-shrink: 0; aspect-ratio: 1 / 1; cursor: pointer; transition: all 0.2s;" onmouseover="this.style.color='#FFFFFF'; this.style.background='rgba(255,255,255,0.16)';" onmouseout="this.style.color='#94A3B8'; this.style.background='rgba(255,255,255,0.06)';" aria-label="Close modal">&times;</button>
      </div>

      <!-- Roster Players List Container -->
      <div id="openPlayRosterList" style="max-height: 440px; overflow-y: auto; padding: 20px; display: flex; flex-direction: column; gap: 12px;">
        <!-- Dynamic Roster Content rendered by JS -->
      </div>

      <!-- Footer Bar -->
      <div style="padding: 16px 24px; background: rgba(12, 22, 38, 0.95); border-top: 1px solid rgba(255, 255, 255, 0.06); display: flex; align-items: center; justify-content: space-between;">
        <div style="display: inline-flex; align-items: center; gap: 8px; background: rgba(255, 184, 0, 0.12); border: 1px solid rgba(255, 184, 0, 0.3); padding: 5px 14px; border-radius: 9999px;">
          <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#FFB800" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
          <span id="openPlayRosterCountBadge" style="font-size: 12.5px; color: #FFB800; font-weight: 800;">0 Players Joined</span>
        </div>
        <button type="button" onclick="closeModal('openPlayRosterModal')" style="background: rgba(255, 255, 255, 0.08); border: 1px solid rgba(255, 255, 255, 0.16); color: #FFFFFF; font-size: 13px; font-weight: 800; padding: 8px 22px; border-radius: 12px; cursor: pointer; transition: all 0.2s; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);" onmouseover="this.style.background='rgba(255, 255, 255, 0.16)'; this.style.borderColor='rgba(255, 255, 255, 0.3)';" onmouseout="this.style.background='rgba(255, 255, 255, 0.08)'; this.style.borderColor='rgba(255, 255, 255, 0.16)';">Close</button>
      </div>

    </div>
  </div>

  <!-- Toast Notification Container -->
  <div class="app-toast" id="appToast">
    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--pk-status-success)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
    <span id="toastMsg">Action completed successfully.</span>
  </div>
