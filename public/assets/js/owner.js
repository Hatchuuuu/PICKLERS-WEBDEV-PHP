// Toggle KPI metrics performance bar
function toggleRevenueStats() {
  const tray = document.getElementById('metricsTray');
  if (!tray) return;
  const isCollapsed = tray.classList.toggle('collapsed');
  const eyeBtn = document.getElementById('btnEyeToggle');
  const eyeSvg = document.getElementById('eyeIconSvg');
  if (eyeSvg) {
    if (isCollapsed) {
      eyeSvg.innerHTML = '<path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><line x1="2" y1="2" x2="22" y2="22"/>';
      if (eyeBtn) eyeBtn.style.color = '#64748B';
    } else {
      eyeSvg.innerHTML = '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>';
      if (eyeBtn) eyeBtn.style.color = '#00D98B';
    }
  }
}

function toggleCourtDropdown(id) {
  const el = document.getElementById(id);
  if (!el) return;
  const isHidden = el.style.display === 'none' || !el.style.display;
  document.querySelectorAll('.court-schedule-dropdown').forEach(d => d.style.display = 'none');
  el.style.display = isHidden ? 'block' : 'none';
}
document.addEventListener('click', function (e) {
  if (!e.target.closest('.court-schedule-dropdown') && !e.target.closest('button')) {
    document.querySelectorAll('.court-schedule-dropdown').forEach(d => d.style.display = 'none');
  }
});

function getNextAvailableCourtNumber() {
  const existingNums = new Set();
  const cards = document.querySelectorAll('.court-item-card, .live-court-card-v2, [data-court-name]');
  cards.forEach((card) => {
    const titleEl = card.querySelector('.court-item-title, .court-name-text');
    const text = titleEl ? titleEl.textContent : (card.getAttribute('data-court-name') || card.textContent || '');
    const match = text.match(/Court\s*(\d+)/i) || text.match(/(\d+)/);
    if (match && match[1]) {
      existingNums.add(parseInt(match[1], 10));
    }
  });

  let num = 1;
  while (existingNums.has(num)) {
    num++;
  }
  return num;
}
window.getNextAvailableCourtNumber = getNextAvailableCourtNumber;

// Modal Helpers
// Dialog mechanics live in ux-core.js (Escape, focus trap, backdrop dismissal,
// reference-counted scroll lock, exit animation).
function openModal(id, options) {
  if (window.UX) { window.UX.openDialog(id, options); }
  else {
    const el = document.getElementById(id);
    if (el) { el.style.display = 'flex'; document.body.style.overflow = 'hidden'; }
  }
  if (id === 'addCourtModal') {
    const nextNum = getNextAvailableCourtNumber();
    const nameInput = document.getElementById('addCourtName');
    if (nameInput) {
      nameInput.value = `Court ${nextNum}`;
      nameInput.readOnly = true;
    }
  }
  if (id === 'scannerModal') {
    initQrCamera();
  }
}
function closeModal(id) {
  if (window.UX) { window.UX.closeDialog(id); }
  else {
    const el = document.getElementById(id);
    if (el) { el.style.display = 'none'; document.body.style.overflow = ''; }
  }
  if (id === 'scannerModal') {
    stopQrCamera();
  }
}

// Mobile Sidebar Off-Canvas Drawer Toggle
function toggleOwnerSidebar() {
  const sb = document.querySelector('.owner-sidebar');
  if (sb) {
    sb.classList.toggle('active');
  }
}

// Toast Helper
let ownerToastTimer = null;
function showToast(msg) {
  // Strip manual checkmark prefixes if present to prevent double checkmark icons
  const cleanMsg = (msg || '').replace(/^[✓✔☑\s]+/, '');
  if (window.UX) window.UX.announce(cleanMsg);
  const toast = document.getElementById('appToast');
  if (!toast) return;
  const msgEl = document.getElementById('toastMsg');
  if (msgEl) msgEl.innerText = cleanMsg;
  if (ownerToastTimer) clearTimeout(ownerToastTimer);
  toast.style.display = 'flex';
  ownerToastTimer = setTimeout(() => { toast.style.display = 'none'; }, 3000);
}

// Helper: CSRF Token Getter
function getCsrfToken() {
  return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

// Live Courts Actions with Confirmation
let pendingEndSessionCourtId = null;
let pendingEndSessionCourtName = '';

function promptEndCourtSession(courtId, courtName) {
  pendingEndSessionCourtId = courtId;
  pendingEndSessionCourtName = courtName;
  const targetName = document.getElementById('endCourtSessionTargetName');
  if (targetName) targetName.textContent = courtName;
  const targetId = document.getElementById('endCourtSessionTargetId');
  if (targetId) targetId.value = courtId;
  openModal('endCourtSessionModal');
}

function executeEndCourtSession() {
  const courtId = pendingEndSessionCourtId || document.getElementById('endCourtSessionTargetId')?.value;
  const courtName = pendingEndSessionCourtName || 'Court';
  closeModal('endCourtSessionModal');

  if (courtId && typeof activeCourtTimers !== 'undefined') {
    activeCourtTimers.delete(courtId);
  }

  const card = document.getElementById('card_' + courtId);
  if (card) {
    card.classList.remove('alarm-active');
    const playerSub = card.querySelector('.court-player-sub');
    const timerGlow = card.querySelector('.court-digital-timer-glow');
    const track = card.querySelector('.court-timer-progress-track');
    const btn = card.querySelector('.btn-end-session-red-dark');
    const dot = card.querySelector('.court-status-dot-cyan');
    if (dot) dot.className = 'court-status-dot-green';
    if (playerSub) playerSub.remove();
    if (timerGlow) timerGlow.remove();
    if (track) track.remove();
    if (btn) {
      btn.outerHTML = '<div class="court-waiting-pill-wrap"><span class="court-waiting-pill">Waiting for players</span></div>';
    }
    const statusPill = card.querySelector('.court-status-pill');
    if (statusPill) {
      statusPill.className = 'court-status-pill status-available';
      statusPill.innerHTML = '<span class="status-indicator-dot"></span> Available';
    }
  }

  if (courtId) {
    fetch('owner.php?action=toggle_court_status', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'X-CSRF-Token': getCsrfToken()
      },
      body: new URLSearchParams({
        action: 'toggle_court_status',
        csrf_token: getCsrfToken(),
        court_id: courtId,
        name: courtName,
        active: '1'
      })
    }).catch(err => console.error('Error resetting court status:', err));
  }
  showToast('✓ ' + courtName + ' session ended. Court cleared and reset to available.', 'success');
}

function endCourtSession(courtId, courtName) {
  promptEndCourtSession(courtId, courtName);
}

function openWalkInForCourt(courtName) {
  const select = document.getElementById('walkin_court');
  if (select) select.value = courtName;
  openModal('walkInModal');
}

function confirmWalkIn() {
  const name = document.getElementById('walkin_name')?.value || 'Walk-In Guest';
  const court = document.getElementById('walkin_court')?.value || 'Court 1';
  closeModal('walkInModal');
  showToast('Assigned ' + court + ' to ' + name + ' for walk-in session.');
}

// Booking Requests Actions
let activeDeclineId = null;
let acceptBookingInFlight = false;
let declineBookingInFlight = false;

function updateRequestsBreathingState(count) {
  const desktopPill = document.getElementById('requestsPillBadge');
  const mobilePill = document.getElementById('pillRequests');
  if (count > 0) {
    if (desktopPill) desktopPill.classList.add('requests-breathing-red');
    if (mobilePill) mobilePill.classList.add('requests-breathing-red');
  } else {
    if (desktopPill) desktopPill.classList.remove('requests-breathing-red');
    if (mobilePill) mobilePill.classList.remove('requests-breathing-red');
  }
}

function removeRequestCard(reqId) {
  const card = document.getElementById('req_card_' + reqId);
  if (card) card.remove();
  const badge = document.getElementById('requestCountBadge');
  let currentCount = 0;
  if (badge) {
    currentCount = Math.max(0, parseInt(badge.innerText || '1') - 1);
    badge.innerText = currentCount;
  }
  const pillBadge = document.getElementById('pillRequestBadge');
  if (pillBadge) pillBadge.innerText = currentCount;
  updateRequestsBreathingState(currentCount);
}

function acceptBooking(reqId, playerName) {
  if (acceptBookingInFlight) return;
  acceptBookingInFlight = true;

  const card = document.getElementById('req_card_' + reqId);
  const acceptBtn = card ? card.querySelector('.btn-req-accept-v2') : null;
  const declineBtn = card ? card.querySelector('.btn-req-decline-v2') : null;
  if (acceptBtn) acceptBtn.disabled = true;
  if (declineBtn) declineBtn.disabled = true;

  fetch('api.php?action=approve_booking', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': getCsrfToken()
    },
    body: JSON.stringify({
      action: 'approve_booking',
      csrf_token: getCsrfToken(),
      booking_id: reqId
    })
  })
    .then(r => r.json())
    .then(res => {
      acceptBookingInFlight = false;
      if (res.success) {
        removeRequestCard(reqId);
        showToast('Booking accepted! Confirmed notification sent to ' + playerName + '.');
      } else {
        if (acceptBtn) acceptBtn.disabled = false;
        if (declineBtn) declineBtn.disabled = false;
        showToast(res.message || 'Failed to accept booking. Please try again.');
      }
    })
    .catch(err => {
      acceptBookingInFlight = false;
      if (acceptBtn) acceptBtn.disabled = false;
      if (declineBtn) declineBtn.disabled = false;
      showToast('Network error occurred while accepting booking. Please try again.');
    });
}

function openDeclineModal(reqId, playerName) {
  activeDeclineId = reqId;
  openModal('declineModal');
}

function confirmDecline() {
  if (!activeDeclineId || declineBookingInFlight) return;
  declineBookingInFlight = true;

  const declineId = activeDeclineId;
  const confirmBtn = document.querySelector('#declineModal .btn-req-decline');
  if (confirmBtn) confirmBtn.disabled = true;

  fetch('api.php?action=decline_booking', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': getCsrfToken()
    },
    body: JSON.stringify({
      action: 'decline_booking',
      csrf_token: getCsrfToken(),
      booking_id: declineId
    })
  })
    .then(r => r.json())
    .then(res => {
      declineBookingInFlight = false;
      if (confirmBtn) confirmBtn.disabled = false;
      if (res.success) {
        removeRequestCard(declineId);
        activeDeclineId = null;
        closeModal('declineModal');
        showToast('Reservation declined. Player hold refunded to wallet.');
      } else {
        showToast(res.message || 'Failed to decline booking. Please try again.');
      }
    })
    .catch(err => {
      declineBookingInFlight = false;
      if (confirmBtn) confirmBtn.disabled = false;
      showToast('Network error occurred while declining booking. Please try again.');
    });
}

// Segmented View Switcher (Live Courts vs Requests Queue on Mobile)
function switchDashboardView(view) {
  const pillLive = document.getElementById('pillLiveCourts');
  const pillReq = document.getElementById('pillRequests');
  const secLive = document.getElementById('secLiveCourts');
  const secReq = document.getElementById('secRequestsQueue');

  if (pillLive) {
    pillLive.classList.toggle('active', view === 'live');
  }
  if (pillReq) {
    pillReq.classList.toggle('active', view === 'requests');
  }

  if (window.innerWidth < 1024) {
    if (secLive) secLive.style.display = (view === 'live') ? 'block' : 'none';
    if (secReq) secReq.style.display = (view === 'requests') ? 'block' : 'none';
  }
}

// Window resize handler to restore desktop vs mobile layout
window.addEventListener('resize', () => {
  const secLive = document.getElementById('secLiveCourts');
  const secReq = document.getElementById('secRequestsQueue');
  if (window.innerWidth >= 1024) {
    if (secLive) secLive.style.display = 'block';
    if (secReq) secReq.style.display = 'block';
  } else {
    const isRequestsActive = document.getElementById('pillRequests')?.classList.contains('active');
    if (secLive) secLive.style.display = isRequestsActive ? 'none' : 'block';
    if (secReq) secReq.style.display = isRequestsActive ? 'block' : 'none';
  }
});

// =========================================================================
// HARDWARE QR CAMERA SCANNER ENGINE (Laptop Front Cam + Mobile Phone Cams)
// =========================================================================
let qrStream = null;
let qrAnimFrameId = null;
let currentFacingMode = 'user'; // default for laptop, switched to 'environment' for mobile
let isScanningActive = false;

function isMobileDevice() {
  return /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent) || (window.innerWidth <= 768);
}

function getInitialFacingMode() {
  return isMobileDevice() ? 'environment' : 'user';
}

async function initQrCamera(deviceId = null) {
  stopQrCamera();

  const statusText = document.getElementById('qrCamStatusText');
  const statusDot = document.getElementById('qrCamStatusDot');
  const video = document.getElementById('qrVideoFeed');
  const fallbackScreen = document.getElementById('qrCamFallbackScreen');
  const fallbackText = document.getElementById('qrFallbackText');
  const btnStartCam = document.getElementById('btnStartCam');
  const overlayBox = document.getElementById('qrOverlayBox');
  const successOverlay = document.getElementById('qrScanSuccessOverlay');

  if (successOverlay) successOverlay.style.display = 'none';

  if (!currentFacingMode) {
    currentFacingMode = getInitialFacingMode();
  }

  if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
    if (statusText) statusText.innerText = 'Camera API not supported.';
    if (statusDot) statusDot.style.background = '#FF4D4D';
    if (fallbackText) fallbackText.innerText = 'Camera access is not supported by your browser.';
    return;
  }

  if (statusText) statusText.innerText = 'Accessing camera...';
  if (statusDot) statusDot.style.background = '#FFB800';
  if (btnStartCam) btnStartCam.style.display = 'none';

  const constraints = {
    video: deviceId ? { deviceId: { exact: deviceId } } : {
      facingMode: { ideal: currentFacingMode },
      width: { ideal: 1280 },
      height: { ideal: 720 }
    }
  };

  try {
    try {
      qrStream = await navigator.mediaDevices.getUserMedia(constraints);
    } catch (err) {
      console.warn('FacingMode constraint fallback:', err);
      qrStream = await navigator.mediaDevices.getUserMedia({ video: true });
    }

    if (!video) return;
    video.srcObject = qrStream;
    await video.play();

    video.style.display = 'block';
    if (overlayBox) overlayBox.style.display = 'block';
    if (fallbackScreen) fallbackScreen.style.display = 'none';

    const track = qrStream.getVideoTracks()[0];
    const settings = track.getSettings ? track.getSettings() : {};
    const facing = settings.facingMode || currentFacingMode;
    const isFront = facing === 'user';

    if (statusText) statusText.innerText = `Camera Active (${isFront ? 'Front Cam' : 'Back Cam'})`;
    if (statusDot) statusDot.style.background = '#00D98B';

    const facingLabel = document.getElementById('btnFacingLabel');
    if (facingLabel) facingLabel.innerText = isFront ? 'Use Back Cam' : 'Use Front Cam';

    populateCameraDevices();

    isScanningActive = true;
    startScanningLoop();

  } catch (error) {
    console.error('Camera access error:', error);
    if (statusText) statusText.innerText = 'Camera blocked or unavailable.';
    if (statusDot) statusDot.style.background = '#FF4D4D';
    if (video) video.style.display = 'none';
    if (overlayBox) overlayBox.style.display = 'none';
    if (fallbackScreen) fallbackScreen.style.display = 'flex';
    if (fallbackText) fallbackText.innerText = 'Camera access was blocked. Allow camera permissions in your browser or enter Pass Code manually below.';
    if (btnStartCam) btnStartCam.style.display = 'inline-block';
  }
}

function stopQrCamera() {
  isScanningActive = false;
  if (qrAnimFrameId) {
    cancelAnimationFrame(qrAnimFrameId);
    qrAnimFrameId = null;
  }
  if (qrStream) {
    qrStream.getTracks().forEach(track => track.stop());
    qrStream = null;
  }
  const video = document.getElementById('qrVideoFeed');
  if (video) {
    video.pause();
    video.srcObject = null;
    video.style.display = 'none';
  }
  const overlayBox = document.getElementById('qrOverlayBox');
  if (overlayBox) overlayBox.style.display = 'none';
}

function toggleCameraFacing() {
  currentFacingMode = (currentFacingMode === 'user') ? 'environment' : 'user';
  initQrCamera();
}

function switchQrCamera(deviceId) {
  if (deviceId) {
    initQrCamera(deviceId);
  }
}

async function populateCameraDevices() {
  try {
    if (!navigator.mediaDevices || !navigator.mediaDevices.enumerateDevices) return;
    const devices = await navigator.mediaDevices.enumerateDevices();
    const videoDevices = devices.filter(d => d.kind === 'videoinput');
    const select = document.getElementById('qrCameraSelect');
    if (select && videoDevices.length > 1) {
      select.innerHTML = '';
      videoDevices.forEach((dev, idx) => {
        const opt = document.createElement('option');
        opt.value = dev.deviceId;
        opt.text = dev.label || `Camera ${idx + 1}`;
        select.appendChild(opt);
      });
      select.style.display = 'inline-block';
    }
  } catch (e) {
    console.warn('Could not enumerate camera list:', e);
  }
}

async function startScanningLoop() {
  const video = document.getElementById('qrVideoFeed');
  const canvas = document.getElementById('qrCanvas');
  if (!video || !isScanningActive) return;

  const ctx = canvas ? canvas.getContext('2d', { willReadFrequently: true }) : null;
  let barcodeDetector = null;

  if ('BarcodeDetector' in window) {
    try {
      barcodeDetector = new BarcodeDetector({ formats: ['qr_code'] });
    } catch (e) {
      barcodeDetector = null;
    }
  }

  async function tick() {
    if (!isScanningActive || !video || video.readyState !== video.HAVE_ENOUGH_DATA) {
      if (isScanningActive) qrAnimFrameId = requestAnimationFrame(tick);
      return;
    }

    let detectedCode = null;

    // 1. Native BarcodeDetector API
    if (barcodeDetector) {
      try {
        const barcodes = await barcodeDetector.detect(video);
        if (barcodes && barcodes.length > 0) {
          detectedCode = barcodes[0].rawValue;
        }
      } catch (err) {
        // fallback
      }
    }

    // 2. jsQR fallback
    if (!detectedCode && typeof jsQR === 'function' && canvas && ctx) {
      canvas.width = video.videoWidth;
      canvas.height = video.videoHeight;
      ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
      const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
      const code = jsQR(imageData.data, imageData.width, imageData.height, {
        inversionAttempts: "dontInvert",
      });
      if (code && code.data) {
        detectedCode = code.data;
      }
    }

    if (detectedCode) {
      onQrCodeDetected(detectedCode);
      return;
    }

    if (isScanningActive) {
      qrAnimFrameId = requestAnimationFrame(tick);
    }
  }

  tick();
}

function onQrCodeDetected(qrData) {
  stopQrCamera();
  playSuccessChime();

  let player = 'Player Pass Verified';
  let court = 'Court 1';

  if (qrData.startsWith('PICKLERS:')) {
    const parts = qrData.split(':');
    if (parts[3]) court = parts[3];
    if (parts[2]) player = parts[2] + ' (' + (parts[1] || 'Pass') + ')';
    else player = `Booking #${parts[1] || 'PASS'}`;
  } else if (qrData.startsWith('BK-') || qrData.length > 3) {
    player = `Check-In Pass: ${qrData}`;
  }

  const successOverlay = document.getElementById('qrScanSuccessOverlay');
  const titleEl = document.getElementById('qrSuccessTitle');
  const playerEl = document.getElementById('qrSuccessPlayer');
  const detailsEl = document.getElementById('qrSuccessDetails');

  if (titleEl) titleEl.innerText = 'Pass Verified & Checked In!';
  if (playerEl) playerEl.innerText = player;
  if (detailsEl) detailsEl.innerText = `${court} • Scanned at ${new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}`;

  if (successOverlay) successOverlay.style.display = 'flex';

  showToast(`QR Code Scanned! Verified pass for ${player}`);
}

function resetQrScanner() {
  const successOverlay = document.getElementById('qrScanSuccessOverlay');
  if (successOverlay) successOverlay.style.display = 'none';
  initQrCamera();
}

function processManualQrInput() {
  const input = document.getElementById('manualQrInput');
  const val = input ? input.value.trim() : '';
  if (!val) {
    showToast('Please enter a pass code or booking reference.');
    return;
  }
  onQrCodeDetected(val);
  if (input) input.value = '';
}

function simulateScanSuccess() {
  stopQrCamera();
  onQrCodeDetected('PICKLERS:BK-2026-9901:Marcus Vance:Court 1');
}

function playSuccessChime() {
  try {
    const AudioCtx = window.AudioContext || window.webkitAudioContext;
    if (!AudioCtx) return;
    const ctx = new AudioCtx();
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    osc.type = 'sine';
    osc.frequency.setValueAtTime(587.33, ctx.currentTime);
    osc.frequency.setValueAtTime(880, ctx.currentTime + 0.08);
    gain.gain.setValueAtTime(0.15, ctx.currentTime);
    gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.3);
    osc.connect(gain);
    gain.connect(ctx.destination);
    osc.start();
    osc.stop(ctx.currentTime + 0.3);
  } catch (e) {
    // AudioContext not permitted
  }
}

// Open Play Tab Switching
function switchOpenPlayTab(tab) {
  document.getElementById('btnSegActive').classList.toggle('active', tab === 'active');
  document.getElementById('btnSegCompleted').classList.toggle('active', tab === 'completed');
  document.getElementById('openPlayActiveGrid').style.display = tab === 'active' ? 'grid' : 'none';
  document.getElementById('openPlayCompletedGrid').style.display = tab === 'completed' ? 'grid' : 'none';
}


// Payout Request
function dispatchPayout() {
  const amtInput = document.getElementById('payoutAmount');
  const amtVal = amtInput ? parseFloat(amtInput.value || 10000) : 10000;
  const formattedAmt = amtVal.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const form = document.getElementById('payoutForm');
  const btn = form ? form.querySelector('button[type="submit"]') : null;
  if (btn) { btn.disabled = true; btn.innerText = 'Processing Request...'; }
  setTimeout(() => {
    if (btn) { btn.disabled = false; btn.innerText = 'Submit Withdrawal Request'; }
    closeModal('payoutModal');
    showToast('✓ Payout request of ₱' + formattedAmt + ' submitted for processing!', 'success');
  }, 500);
}

// Chat Functions
function selectConversation(name) {
  document.getElementById('chatActiveUser').innerText = name;
  showToast('Loaded thread with ' + name);
}

function sendChatMessage() {
  const input = document.getElementById('chatMessageInput');
  const text = input.value.trim();
  if (!text) return;
  const thread = document.getElementById('chatThreadContainer');
  const bubble = document.createElement('div');
  bubble.className = 'chat-bubble from-owner';
  bubble.innerHTML = text + '<div style="font-size:10px; opacity:0.7; margin-top:4px; text-align:right;">Just now</div>';
  thread.appendChild(bubble);
  input.value = '';
  thread.scrollTop = thread.scrollHeight;
}

// Edit Court Modal Handlers
let currentEditingCourtCard = null;
let currentEditingCourtOriginalName = '';
let currentEditingCourtId = null;

function openEditCourtModal(courtId, name, surface, rate, btnEl, attributeSlugs) {
  currentEditingCourtCard = btnEl ? btnEl.closest('.court-item-card') : null;
  currentEditingCourtOriginalName = name || 'Championship Court 1';
  currentEditingCourtId = courtId || null;

  const nameInput = document.getElementById('editCourtNameInput');
  const surfaceSelect = document.getElementById('editCourtSurfaceSelect');
  const rateInput = document.getElementById('editCourtRateInput');

  if (nameInput) {
    const digitMatch = (name || '').match(/\d+/);
    nameInput.value = digitMatch ? `Court ${parseInt(digitMatch[0], 10)}` : (name || 'Court 1');
    nameInput.readOnly = true;
  }
  if (rateInput) rateInput.value = rate ? parseInt(rate, 10) : 450;

  if (surfaceSelect && surface) {
    let matched = false;
    const normalizedTarget = surface.toLowerCase().replace(/[•·\s-]/g, '');
    for (let i = 0; i < surfaceSelect.options.length; i++) {
      const optNorm = surfaceSelect.options[i].value.toLowerCase().replace(/[•·\s-]/g, '');
      if (optNorm.includes(normalizedTarget) || normalizedTarget.includes(optNorm)) {
        surfaceSelect.selectedIndex = i;
        matched = true;
        break;
      }
    }
    if (!matched) {
      surfaceSelect.value = 'Indoor · Premium Hard';
    }
  }

  // Pre-check this court's own current tags, uncheck everything else — the
  // picker always reflects exactly what's already saved, never a stale
  // selection left over from whichever court was edited previously.
  const slugSet = new Set((attributeSlugs || '').split(',').map(s => s.trim()).filter(Boolean));
  document.querySelectorAll('#editCourtAttrPicker input[name="attributes"]').forEach(cb => {
    cb.checked = slugSet.has(cb.value);
  });

  openModal('editCourtModal');
}
window.openEditCourtModal = openEditCourtModal;
window.openAvailabilityModal = openEditCourtModal; // Backward-compat alias

let editCourtInFlight = false;

function submitEditCourtForm(e) {
  if (e) e.preventDefault();
  if (editCourtInFlight) return;

  const rawEditName = document.getElementById('editCourtNameInput')?.value?.trim() || currentEditingCourtOriginalName || 'Court 1';
  const digitMatch = rawEditName.match(/\d+/);
  const name = digitMatch ? `Court ${parseInt(digitMatch[0], 10)}` : rawEditName;
  const surface = document.getElementById('editCourtSurfaceSelect')?.value || 'Indoor · Premium Hard';
  const rate = document.getElementById('editCourtRateInput')?.value || '450';
  const formattedRate = Number(rate).toLocaleString();
  const courtId = currentEditingCourtId;
  const attributes = Array.from(document.querySelectorAll('#editCourtAttrPicker input[name="attributes"]:checked')).map(cb => cb.value);

  if (!courtId) {
    showToast('Unable to save: missing court reference. Please refresh and try again.');
    return;
  }

  const submitBtn = e && e.target ? e.target.querySelector('button[type="submit"]') : null;
  editCourtInFlight = true;
  if (submitBtn) { submitBtn.disabled = true; submitBtn.innerText = 'Saving...'; }
  const restoreSubmitBtn = () => { if (submitBtn) { submitBtn.disabled = false; submitBtn.innerText = 'Save'; } };

  fetch('owner.php?action=edit_court', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': getCsrfToken(),
      'X-Requested-With': 'XMLHttpRequest'
    },
    body: JSON.stringify({
      action: 'edit_court',
      csrf_token: getCsrfToken(),
      ajax: '1',
      court_id: courtId,
      name: name,
      surface: surface,
      rate: rate,
      attributes: attributes
    })
  })
    .then(r => r.json())
    .then(res => {
      editCourtInFlight = false;
      restoreSubmitBtn();

      if (!res.success) {
        showToast(res.message || 'Failed to update court. Please try again.');
        return;
      }

      if (currentEditingCourtCard) {
        // Update title
        const titleEl = currentEditingCourtCard.querySelector('.court-item-title');
        if (titleEl) titleEl.textContent = name;
        currentEditingCourtCard.setAttribute('data-court-name', name.toLowerCase());

        // Update price
        const priceEl = currentEditingCourtCard.querySelector('.court-item-price');
        if (priceEl) {
          priceEl.textContent = '₱' + formattedRate + '/hr';
        }

        // Update Edit button onclick attribute
        const editBtn = currentEditingCourtCard.querySelector('.btn-edit-dark');
        if (editBtn) {
          editBtn.setAttribute('onclick', `openEditCourtModal('${courtId.replace(/'/g, "\\'")}', '${name.replace(/'/g, "\\'")}', '${surface.replace(/'/g, "\\'")}', '${rate}', this)`);
        }

        // Update Host Open Play button onclick attribute
        const hostBtn = currentEditingCourtCard.querySelector('.btn-host-openplay-active');
        if (hostBtn) {
          hostBtn.setAttribute('onclick', `openHostOpenPlayForCourt('${name.replace(/'/g, "\\'")}', '${surface.replace(/'/g, "\\'")}')`);
        }

        // Update Disable/Enable button onclick attribute
        const disableBtn = currentEditingCourtCard.querySelector('.btn-disable-red, .btn-enable-green');
        if (disableBtn) {
          disableBtn.setAttribute('onclick', `toggleCourtActive('${courtId.replace(/'/g, "\\'")}', '${name.replace(/'/g, "\\'")}', this)`);
        }
      }

      closeModal('editCourtModal');
      showToast('✓ ' + (res.message || (name + ' updated (₱' + formattedRate + '/hr · ' + surface + ')!')), 'success');
    })
    .catch(err => {
      editCourtInFlight = false;
      restoreSubmitBtn();
      showToast('Network error occurred while updating court. Please try again.');
    });
}
window.submitEditCourtForm = submitEditCourtForm;

// Toggle Court Active / Disabled State
const courtToggleInFlight = new Set();

function toggleCourtActive(courtId, courtName, btnEl) {
  let card = null;
  let btn = null;

  if (btnEl && btnEl.nodeType) {
    btn = btnEl;
    card = btn.closest('.court-item-card');
  } else {
    const cards = document.querySelectorAll('.court-item-card');
    cards.forEach(c => {
      if ((c.getAttribute('data-court-name') || '').toLowerCase() === (courtName || '').toLowerCase()) {
        card = c;
        btn = c.querySelector('.btn-disable-red, .btn-enable-green');
      }
    });
  }

  if (!card || !btn) {
    showToast('Unable to update ' + (courtName || 'court') + ' status. Please refresh and try again.');
    return;
  }

  if (!courtId) {
    showToast('Unable to update: missing court reference. Please refresh and try again.');
    return;
  }

  if (courtToggleInFlight.has(courtId)) return;
  courtToggleInFlight.add(courtId);
  btn.disabled = true;

  // Direction detection: the NEW target active state is the opposite of
  // whatever is currently displayed (same logic the old DOM-only version used).
  const isCurrentlyDisabled = btn.classList.contains('btn-enable-green') || card.classList.contains('court-card-disabled');
  const newActive = isCurrentlyDisabled; // toggling FROM disabled means the new state is active

  fetch('owner.php?action=toggle_court_status', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': getCsrfToken(),
      'X-Requested-With': 'XMLHttpRequest'
    },
    body: JSON.stringify({
      action: 'toggle_court_status',
      csrf_token: getCsrfToken(),
      ajax: '1',
      court_id: courtId,
      name: courtName,
      active: newActive
    })
  })
    .then(r => r.json())
    .then(res => {
      courtToggleInFlight.delete(courtId);
      btn.disabled = false;

      if (!res.success) {
        showToast(res.message || 'Failed to update court status. Please try again.');
        return;
      }

      if (newActive) {
        // Toggle to Enabled / Available
        btn.className = 'btn-court-action btn-disable-red';
        btn.innerHTML = '<span>Disable</span> <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>';

        card.classList.remove('court-card-disabled');

        const statusPill = card.querySelector('.court-status-pill');
        if (statusPill) {
          statusPill.className = 'court-status-pill badge-emerald-glow';
          statusPill.textContent = 'AVAILABLE';
        }

        const hostBtn = card.querySelector('.btn-host-openplay-active, .btn-host-openplay-disabled');
        if (hostBtn) {
          hostBtn.className = 'btn-host-openplay-active';
          hostBtn.disabled = false;
          hostBtn.style.pointerEvents = 'auto';
          hostBtn.style.opacity = '1';
        }

        showToast('✓ ' + (res.message || ((courtName || 'Court') + ' is now active and open for reservations!')), 'success');
      } else {
        // Toggle to Disabled
        btn.className = 'btn-court-action btn-enable-green';
        btn.innerHTML = '<span>Enable</span> <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>';

        card.classList.add('court-card-disabled');

        const statusPill = card.querySelector('.court-status-pill');
        if (statusPill) {
          statusPill.dataset.prevStatus = statusPill.textContent.trim();
          statusPill.dataset.prevClass = statusPill.className;
          statusPill.className = 'court-status-pill badge-red-glow';
          statusPill.textContent = 'DISABLED';
        }

        const hostBtn = card.querySelector('.btn-host-openplay-active, .btn-host-openplay-disabled');
        if (hostBtn) {
          hostBtn.dataset.wasActive = hostBtn.classList.contains('btn-host-openplay-active') ? '1' : '0';
          hostBtn.className = 'btn-host-openplay-disabled';
          hostBtn.disabled = true;
          hostBtn.style.pointerEvents = 'none';
          hostBtn.style.opacity = '0.5';
        }

        showToast('✓ ' + (res.message || ((courtName || 'Court') + ' disabled from active reservations.')), 'info');
      }

      // Keep the Disable/Enable button's own onclick attribute in sync with the new state.
      const refreshedBtn = card.querySelector('.btn-disable-red, .btn-enable-green');
      if (refreshedBtn) {
        refreshedBtn.setAttribute('onclick', `toggleCourtActive('${courtId.replace(/'/g, "\\'")}', '${(courtName || '').replace(/'/g, "\\'")}', this)`);
      }
    })
    .catch(err => {
      courtToggleInFlight.delete(courtId);
      btn.disabled = false;
      showToast('Network error occurred while updating court status. Please try again.');
    });
}
window.toggleCourtActive = toggleCourtActive;

let pendingDeleteCourtId = '';
let pendingDeleteCourtName = '';

function onDeleteCourtConfirmInput(val) {
  const btn = document.getElementById('btnConfirmDeleteCourt');
  if (!btn) return;
  const isMatch = (val || '').trim().toUpperCase() === 'DELETE';
  btn.disabled = !isMatch;
  btn.style.opacity = isMatch ? '1' : '0.4';
  btn.style.cursor = isMatch ? 'pointer' : 'not-allowed';
}
window.onDeleteCourtConfirmInput = onDeleteCourtConfirmInput;

function confirmDeleteCourt(courtId, courtName) {
  if (typeof courtId === 'string' && !courtName) {
    courtName = courtId;
    courtId = '';
  }
  pendingDeleteCourtId = courtId || '';
  pendingDeleteCourtName = courtName || 'Court';
  const nameEl = document.getElementById('deleteCourtTargetName');
  if (nameEl) nameEl.textContent = pendingDeleteCourtName;

  const input = document.getElementById('deleteCourtConfirmInput');
  if (input) input.value = '';
  onDeleteCourtConfirmInput('');

  openModal('deleteCourtModal');
}
window.confirmDeleteCourt = confirmDeleteCourt;

function triggerDeleteFromEditModal() {
  closeModal('editCourtModal');
  const courtId = currentEditCourtId;
  const courtName = document.getElementById('editCourtNameInput')?.value || 'Court';
  confirmDeleteCourt(courtId, courtName);
}
window.triggerDeleteFromEditModal = triggerDeleteFromEditModal;

function executeDeleteCourt() {
  const input = document.getElementById('deleteCourtConfirmInput');
  if (input && input.value.trim().toUpperCase() !== 'DELETE') {
    showToast('Type DELETE to confirm court removal.');
    return;
  }

  const courtId = pendingDeleteCourtId;
  const courtName = pendingDeleteCourtName || 'Court';

  closeModal('deleteCourtModal');

  fetch('owner.php?action=delete_court', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': getCsrfToken(),
      'X-Requested-With': 'XMLHttpRequest'
    },
    body: JSON.stringify({
      action: 'delete_court',
      csrf_token: getCsrfToken(),
      ajax: '1',
      court_id: courtId,
      name: courtName
    })
  })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        showToast('✓ ' + (res.message || courtName + ' deleted permanently.'), 'success');
        setTimeout(() => { window.location.reload(); }, 500);
      } else {
        showToast(res.message || 'Failed to delete court.', 'error');
      }
    })
    .catch(() => {
      showToast('Network error occurred while deleting court. Please try again.');
    });
}
window.executeDeleteCourt = executeDeleteCourt;

function onCancelOpenPlayConfirmInput(val) {
  const btn = document.getElementById('btnConfirmCancelOpenPlay');
  if (!btn) return;
  const isMatch = (val || '').trim().toUpperCase() === 'CANCEL';
  btn.disabled = !isMatch;
  btn.style.opacity = isMatch ? '1' : '0.4';
  btn.style.cursor = isMatch ? 'pointer' : 'not-allowed';
}
window.onCancelOpenPlayConfirmInput = onCancelOpenPlayConfirmInput;

let pendingCancelCourtName = '';

function confirmCancelOpenPlay(matchId, sessionTitle, courtName = '') {
  if (typeof matchId === 'string' && !sessionTitle) {
    sessionTitle = matchId;
    matchId = '';
  }
  pendingCancelMatchId = matchId || '';
  pendingCancelOpenPlayTitle = sessionTitle || 'Open Play';
  pendingCancelCourtName = courtName || '';
  const titleEl = document.getElementById('cancelOpenPlayTargetTitle');
  if (titleEl) titleEl.textContent = pendingCancelOpenPlayTitle;

  const input = document.getElementById('cancelOpenPlayConfirmInput');
  if (input) input.value = '';
  onCancelOpenPlayConfirmInput('');

  openModal('cancelOpenPlayModal');
}
window.confirmCancelOpenPlay = confirmCancelOpenPlay;

function executeCancelOpenPlay() {
  const input = document.getElementById('cancelOpenPlayConfirmInput');
  if (input && input.value.trim().toUpperCase() !== 'CANCEL') {
    showToast('Type CANCEL to confirm session cancellation.');
    return;
  }

  const matchId = pendingCancelMatchId;
  const title = pendingCancelOpenPlayTitle || 'Open Play';
  const courtName = pendingCancelCourtName || '';

  closeModal('cancelOpenPlayModal');
  showToast('✓ "' + title + '" cancelled. Player entry fees 100% refunded.', 'success');

  fetch('owner.php?action=cancel_open_play', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': getCsrfToken(),
      'X-Requested-With': 'XMLHttpRequest'
    },
    body: JSON.stringify({
      action: 'cancel_open_play',
      csrf_token: getCsrfToken(),
      ajax: '1',
      match_id: matchId,
      title: title,
      court_name: courtName
    })
  })
    .then(r => r.json())
    .then(res => {
      setTimeout(() => { window.location.reload(); }, 500);
    })
    .catch(() => {
      setTimeout(() => { window.location.reload(); }, 500);
    });
}
window.executeCancelOpenPlay = executeCancelOpenPlay;

function switchOpenPlayTab(tab) {
  const activeGrid = document.getElementById('openPlayActiveGrid');
  const completedGrid = document.getElementById('openPlayCompletedGrid');
  const btnActive = document.getElementById('btnSegActive');
  const btnCompleted = document.getElementById('btnSegCompleted');

  if (tab === 'active') {
    if (activeGrid) activeGrid.style.display = 'grid';
    if (completedGrid) completedGrid.style.display = 'none';
    if (btnActive) {
      btnActive.style.background = '#00D98B';
      btnActive.style.color = '#08101F';
      btnActive.style.fontWeight = '800';
    }
    if (btnCompleted) {
      btnCompleted.style.background = 'transparent';
      btnCompleted.style.color = 'var(--pk-text-muted, #94A3B8)';
      btnCompleted.style.fontWeight = '600';
    }
  } else {
    if (activeGrid) activeGrid.style.display = 'none';
    if (completedGrid) completedGrid.style.display = 'block';
    if (btnActive) {
      btnActive.style.background = 'transparent';
      btnActive.style.color = 'var(--pk-text-muted, #94A3B8)';
      btnActive.style.fontWeight = '600';
    }
    if (btnCompleted) {
      btnCompleted.style.background = '#00D98B';
      btnCompleted.style.color = '#08101F';
      btnCompleted.style.fontWeight = '800';
    }
  }
}
window.switchOpenPlayTab = switchOpenPlayTab;

function filterCourtsList(val) {
  const query = (val || '').toLowerCase().trim();
  const cards = document.querySelectorAll('.court-item-card');
  cards.forEach(card => {
    const courtName = card.getAttribute('data-court-name') || '';
    if (!query || courtName.includes(query)) {
      card.style.display = 'flex';
    } else {
      card.style.display = 'none';
    }
  });
}
window.filterCourtsList = filterCourtsList;

let addCourtInFlight = false;

function submitAddCourtForm(e) {
  e.preventDefault();
  if (addCourtInFlight) return;
  addCourtInFlight = true;

  const rawInput = (document.getElementById('addCourtName')?.value || '').trim();
  const digitMatch = rawInput.match(/\d+/);
  const nextNum = getNextAvailableCourtNumber();
  const name = digitMatch ? `Court ${parseInt(digitMatch[0], 10)}` : `Court ${nextNum}`;
  const surface = document.getElementById('addCourtSurface')?.value || 'Indoor Hard';
  const rate = document.getElementById('addCourtRate')?.value || 450;
  const attributes = Array.from(document.querySelectorAll('#addCourtAttrPicker input[name="attributes"]:checked')).map(cb => cb.value);
  const btn = e.target.querySelector('button[type="submit"]');
  if (btn) { btn.disabled = true; btn.innerText = 'Saving...'; }

  fetch('owner.php?action=add_court', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': getCsrfToken(),
      'X-Requested-With': 'XMLHttpRequest'
    },
    body: JSON.stringify({
      action: 'add_court',
      csrf_token: getCsrfToken(),
      ajax: '1',
      name: name,
      surface: surface,
      rate: rate,
      attributes: attributes
    })
  })
    .then(r => r.json())
    .then(res => {
      addCourtInFlight = false;
      if (btn) { btn.disabled = false; btn.innerText = 'Save & Publish Court'; }
      if (res.success) {
        closeModal('addCourtModal');
        showToast('✓ ' + (res.message || name + ' added to inventory successfully!'));
        window.location.reload();
      } else {
        showToast('⚠ ' + (res.message || 'Could not add court.'));
      }
    })
    .catch(err => {
      addCourtInFlight = false;
      if (btn) { btn.disabled = false; btn.innerText = 'Save & Publish Court'; }
      showToast('⚠ Network error — court not saved. Please try again.');
    });
}
window.submitAddCourtForm = submitAddCourtForm;



function openHostOpenPlayForCourt(courtName, courtSpecs) {
  const nameEl = document.getElementById('modalTargetCourtName');
  const specsEl = document.getElementById('modalTargetCourtSpecs');
  if (nameEl && courtName) nameEl.textContent = courtName;
  if (specsEl) specsEl.textContent = '';
  openModal('hostOpenPlayModal');
}

function toggleOpenPlayDateMode(mode) {
  const btnSpec = document.getElementById('btnDateSpecific');
  const btnEvery = document.getElementById('btnDateEveryday');
  const dateInputWrap = document.getElementById('openPlayDatePickerWrap');
  if (mode === 'specific') {
    if (btnSpec) { btnSpec.style.background = '#FFB800'; btnSpec.style.color = '#08101F'; btnSpec.style.fontWeight = '800'; }
    if (btnEvery) { btnEvery.style.background = 'transparent'; btnEvery.style.color = '#94A3B8'; btnEvery.style.fontWeight = '600'; }
    if (dateInputWrap) dateInputWrap.style.display = 'block';
  } else {
    if (btnEvery) { btnEvery.style.background = '#FFB800'; btnEvery.style.color = '#08101F'; btnEvery.style.fontWeight = '800'; }
    if (btnSpec) { btnSpec.style.background = 'transparent'; btnSpec.style.color = '#94A3B8'; btnSpec.style.fontWeight = '600'; }
    if (dateInputWrap) dateInputWrap.style.display = 'none';
  }
}

function toggleOpenPlayCapMode(mode) {
  const btnCap = document.getElementById('btnCapMax');
  const btnUnlim = document.getElementById('btnCapUnlimited');
  const capWrap = document.getElementById('openPlayCapWrap');
  if (mode === 'capped') {
    if (btnCap) { btnCap.style.background = '#FFB800'; btnCap.style.color = '#08101F'; btnCap.style.fontWeight = '800'; }
    if (btnUnlim) { btnUnlim.style.background = 'transparent'; btnUnlim.style.color = '#94A3B8'; btnUnlim.style.fontWeight = '600'; }
    if (capWrap) capWrap.style.display = 'block';
  } else {
    if (btnUnlim) { btnUnlim.style.background = '#FFB800'; btnUnlim.style.color = '#08101F'; btnUnlim.style.fontWeight = '800'; }
    if (btnCap) { btnCap.style.background = 'transparent'; btnCap.style.color = '#94A3B8'; btnCap.style.fontWeight = '600'; }
    if (capWrap) capWrap.style.display = 'none';
  }
}

let hostOpenPlayInFlight = false;

function submitHostOpenPlayForm(e) {
  e.preventDefault();
  if (hostOpenPlayInFlight) return;
  hostOpenPlayInFlight = true;

  const court = document.getElementById('modalTargetCourtName')?.textContent || 'Court 2';
  const date = document.getElementById('openPlayDateInput')?.value || 'Mon, Sep 7, 2026';
  const start = document.getElementById('openPlayStartTime')?.value || '6:00 AM';
  const end = document.getElementById('openPlayEndTime')?.value || '11:00 PM';
  const fee = document.getElementById('openPlayFee')?.value || '250';
  const cap = document.getElementById('openPlayCap')?.value || '20';

  const btn = e.target.querySelector('button[type="submit"]');
  const restoreSubmitBtn = () => {
    if (btn) {
      btn.disabled = false;
      btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="#08101F" stroke="none"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg> <span>Host Open Play</span>';
    }
  };
  if (btn) { btn.disabled = true; btn.innerText = 'Publishing...'; }

  fetch('owner.php?action=host_open_play', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': getCsrfToken()
    },
    body: JSON.stringify({
      action: 'host_open_play',
      csrf_token: getCsrfToken(),
      court_name: court,
      date: date,
      start_time: start,
      end_time: end,
      fee: fee,
      capacity: cap
    })
  })
    .then(r => r.json())
    .then(res => {
      hostOpenPlayInFlight = false;
      restoreSubmitBtn();
      if (res.success) {
        closeModal('hostOpenPlayModal');
        showToast('✓ ' + (res.message || 'Open Play scheduled for ' + court + '!'), 'success');
        setTimeout(() => {
          const targetUrl = new URL(window.location.href);
          targetUrl.searchParams.set('tab', 'courts');
          window.location.href = targetUrl.toString();
        }, 400);
      } else {
        showToast(res.message || 'Failed to publish Open Play session. Please try again.');
      }
    })
    .catch(err => {
      hostOpenPlayInFlight = false;
      restoreSubmitBtn();
      showToast('Network error occurred while publishing Open Play session. Please try again.');
    });
}

let addStaffInFlight = false;

function submitAddStaffForm(e) {
  e.preventDefault();
  if (addStaffInFlight) return;
  addStaffInFlight = true;

  const name = document.getElementById('staffNameInput')?.value || 'Staff Member';
  const email = document.getElementById('staffEmailInput')?.value || '';
  const role = document.getElementById('staffRoleInput')?.value || 'Front Desk';
  const btn = e.target.querySelector('button[type="submit"]');
  if (btn) { btn.disabled = true; btn.innerText = 'Granting Access...'; }
  const restoreSubmitBtn = () => { if (btn) { btn.disabled = false; btn.innerText = 'Grant Staff Access'; } };

  fetch('owner.php?action=add_staff', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': getCsrfToken()
    },
    body: JSON.stringify({
      action: 'add_staff',
      csrf_token: getCsrfToken(),
      name: name,
      email: email,
      role: role
    })
  })
    .then(r => r.json())
    .then(res => {
      addStaffInFlight = false;
      restoreSubmitBtn();
      if (res.success) {
        closeModal('addStaffModal');
        showToast('✓ ' + (res.message || 'Access granted to ' + name + '! Staff invitation sent.'), 'success');
        window.location.reload();
      } else {
        showToast(res.message || 'Failed to add staff member. Please try again.');
      }
    })
    .catch(err => {
      addStaffInFlight = false;
      restoreSubmitBtn();
      showToast('Network error occurred while adding staff member. Please try again.');
    });
}
window.submitAddStaffForm = submitAddStaffForm;

let pendingRevokeStaffId = null;
let pendingRevokeStaffName = '';
let pendingRevokeStaffBtn = null;
let revokeStaffInFlight = false;

function promptRevokeStaff(staffId, staffName, btnEl) {
  pendingRevokeStaffId = staffId;
  pendingRevokeStaffName = staffName;
  pendingRevokeStaffBtn = btnEl;
  const nameEl = document.getElementById('revokeStaffTargetName');
  if (nameEl) nameEl.textContent = staffName;
  openModal('revokeStaffModal');
}

function executeRevokeStaff() {
  if (revokeStaffInFlight) return;

  const staffId = pendingRevokeStaffId;
  const staffName = pendingRevokeStaffName || 'Staff member';
  const staffBtn = pendingRevokeStaffBtn;

  if (!staffId) {
    closeModal('revokeStaffModal');
    pendingRevokeStaffId = null;
    pendingRevokeStaffName = '';
    pendingRevokeStaffBtn = null;
    showToast('Unable to revoke access: missing staff reference. Please refresh and try again.');
    return;
  }

  revokeStaffInFlight = true;
  const confirmBtn = document.querySelector('#revokeStaffModal .btn-modal-danger');
  if (confirmBtn) confirmBtn.disabled = true;

  fetch('owner.php?action=revoke_staff', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': getCsrfToken()
    },
    body: JSON.stringify({
      action: 'revoke_staff',
      csrf_token: getCsrfToken(),
      staff_id: staffId
    })
  })
    .then(r => r.json())
    .then(res => {
      revokeStaffInFlight = false;
      if (confirmBtn) confirmBtn.disabled = false;
      closeModal('revokeStaffModal');

      if (res.success) {
        if (staffBtn) {
          const row = staffBtn.closest('.staff-member-card');
          if (row) {
            row.style.opacity = '0';
            row.style.transform = 'scale(0.95)';
            row.style.transition = 'all 0.2s ease';
            setTimeout(() => row.remove(), 200);
          }
        }
        showToast('✓ ' + (res.message || staffName + ' access has been permanently revoked.'), 'success');
      } else {
        showToast(res.message || 'Failed to revoke staff access. Please try again.');
      }

      pendingRevokeStaffId = null;
      pendingRevokeStaffName = '';
      pendingRevokeStaffBtn = null;
    })
    .catch(err => {
      revokeStaffInFlight = false;
      if (confirmBtn) confirmBtn.disabled = false;
      closeModal('revokeStaffModal');
      showToast('Network error occurred while revoking staff access. Please try again.');

      pendingRevokeStaffId = null;
      pendingRevokeStaffName = '';
      pendingRevokeStaffBtn = null;
    });
}

function removeStaffRow(staffId, staffName, btnEl) {
  promptRevokeStaff(staffId, staffName, btnEl);
}

function toggleHoursVisibility() {
  const is24h = document.getElementById('toggleOpen24')?.checked;
  const grid = document.getElementById('hoursGridInputs');
  if (grid) {
    grid.style.opacity = is24h ? '0.4' : '1';
    grid.style.pointerEvents = is24h ? 'none' : 'auto';
  }
}

// Theme Management (Synced with Player App)
function initThemeToggle() {
  const saved = localStorage.getItem('picklers_theme') || 'dark';
  const cb = document.getElementById('ownerThemeToggleCheckbox');
  const html = document.documentElement;
  if (saved === 'light') {
    html.classList.add('light');
    html.classList.remove('dark');
    if (cb) cb.checked = false;
  } else {
    html.classList.add('dark');
    html.classList.remove('light');
    if (cb) cb.checked = true;
  }
}

function toggleTheme(isDark) {
  const html = document.documentElement;
  if (isDark) {
    html.classList.add('dark');
    html.classList.remove('light');
    localStorage.setItem('picklers_theme', 'dark');
    showToast('Dark mode activated');
  } else {
    html.classList.remove('dark');
    html.classList.add('light');
    localStorage.setItem('picklers_theme', 'light');
    showToast('Light mode activated');
  }
}

// Filter Courts List by Name
function filterCourtsList(query) {
  const q = (query || '').toLowerCase().trim();
  const cards = document.querySelectorAll('.court-item-card');
  cards.forEach(card => {
    const name = (card.getAttribute('data-court-name') || card.innerText || '').toLowerCase();
    if (!q || name.includes(q)) {
      card.style.display = 'flex';
    } else {
      card.style.display = 'none';
    }
  });
}

// Notification Handlers (Synced with Player App)
function dismissNotification(rowElement, notifId) {
  if (!rowElement || rowElement.classList.contains('removing')) return;
  const card = rowElement.querySelector('.notif-card-item');
  if (card) {
    card.style.transition = 'transform 0.25s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.22s ease';
    card.style.transform = 'translateX(-120%)';
    card.style.opacity = '0';
  }
  setTimeout(() => {
    rowElement.classList.add('removing');
  }, 100);
  setTimeout(() => {
    rowElement.remove();
    const list = document.getElementById('notifModalList');
    const remainingRows = list ? list.querySelectorAll('.notif-swipe-row') : [];
    const remainingUnread = list ? list.querySelectorAll('.notif-card-item.unread').length : 0;
    const badge = document.getElementById('notifUnreadBadgeText');
    if (badge) {
      badge.textContent = remainingUnread > 0
        ? `${remainingUnread} unread of ${remainingRows.length}`
        : `${remainingRows.length} total`;
    }
    if (remainingUnread === 0) {
      document.querySelectorAll('.notif-unread-dot').forEach(el => el.remove());
    }
    if (remainingRows.length === 0 && list) {
      list.innerHTML = `
            <div style="text-align: center; padding: 48px 20px; color: #94A3B8;">
              <div style="font-size: 36px; margin-bottom: 10px;">🔔</div>
              <div style="font-size: 15px; font-weight: 700; color: #FFFFFF; margin-bottom: 4px;">No notifications yet</div>
              <div style="font-size: 12px; color: rgba(255, 255, 255, 0.55);">When players book courts or scan QR passes, updates will show up here.</div>
            </div>`;
    }
  }, 350);
}

async function markAllNotificationsAsRead() {
  try {
    document.querySelectorAll('.notif-card-item.unread').forEach(el => el.classList.remove('unread'));
    document.querySelectorAll('.notif-card-unread-dot').forEach(el => el.remove());
    document.querySelectorAll('.notif-unread-dot').forEach(el => el.remove());
    const badge = document.getElementById('notifUnreadBadgeText');
    if (badge) {
      const total = document.querySelectorAll('.notif-card-item').length;
      badge.textContent = `${total} total`;
    }
    showToast('All notifications marked as read', 'success');
  } catch (err) {
    console.error('Failed to mark notifications as read', err);
  }
}

// Settings Helper Functions
function handleLogoUpload(input) {
  if (input.files && input.files[0]) {
    const file = input.files[0];
    const reader = new FileReader();
    reader.onload = function (e) {
      const preview = document.getElementById('brandLogoPreview');
      if (preview) {
        preview.innerHTML = `<img src="${e.target.result}" style="width:100%; height:100%; object-fit:cover; border-radius:50%;">`;
      }
      showToast('✓ Logo uploaded! Click Save Changes to finalize.', 'success');
    };
    reader.readAsDataURL(file);
  }
}

function autoLocateGps() {
  const locInput = document.getElementById('facilityLocationInput');

  if ('geolocation' in navigator) {
    showToast('📡 Detecting facility GPS location...', 'info');
    navigator.geolocation.getCurrentPosition(
      (pos) => {
        const lat = pos.coords.latitude;
        const lng = pos.coords.longitude;

        const geocodeUrl = `https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}`;
        fetch(geocodeUrl, { headers: { 'Accept-Language': 'en' } })
          .then(r => r.json())
          .then(data => {
            let formattedAddr = '';
            if (data && data.address) {
              const addr = data.address;
              const neighborhood = addr.suburb || addr.neighbourhood || addr.quarter || addr.village || addr.hamlet || addr.district || addr.residential || '';
              const city = addr.city || addr.town || addr.municipality || addr.city_district || addr.county || '';
              const state = addr.state || addr.province || '';

              let parts = [];
              if (neighborhood) parts.push(neighborhood);
              if (city) parts.push(city);
              else if (state) parts.push(state);

              if (parts.length > 0) {
                formattedAddr = parts.join(', ');
              }
            }

            if (!formattedAddr) {
              if (lat >= 9.0 && lat <= 10.5 && lng >= 122.5 && lng <= 124.0) {
                formattedAddr = 'Bunao, Dumaguete City';
              } else {
                formattedAddr = `${lat.toFixed(4)}° N, ${lng.toFixed(4)}° E`;
              }
            }

            if (locInput) {
              locInput.value = formattedAddr;
            }
            showToast(`✓ Located: ${formattedAddr}`, 'success');
          })
          .catch(() => {
            let fallbackAddr = '';
            if (lat >= 9.0 && lat <= 10.5 && lng >= 122.5 && lng <= 124.0) {
              fallbackAddr = 'Bunao, Dumaguete City';
            } else {
              fallbackAddr = `${lat.toFixed(4)}° N, ${lng.toFixed(4)}° E`;
            }
            if (locInput) {
              locInput.value = fallbackAddr;
            }
            showToast(`✓ Located: ${fallbackAddr}`, 'success');
          });
      },
      (err) => {
        const defaultLocation = 'Bunao, Dumaguete City';
        if (locInput) {
          locInput.value = defaultLocation;
        }
        showToast(`✓ Location set: ${defaultLocation}`, 'success');
      },
      { timeout: 8000, enableHighAccuracy: true }
    );
  } else {
    const defaultLocation = 'Bunao, Dumaguete City';
    if (locInput) {
      locInput.value = defaultLocation;
    }
    showToast(`✓ Location set: ${defaultLocation}`, 'success');
  }
}

function toggleHoursVisibility() {
  const is24 = document.getElementById('toggleOpen24')?.checked;
  const hoursGrid = document.getElementById('hoursGridInputs');
  const openInput = document.getElementById('openingTimeInput');
  const closeInput = document.getElementById('closingTimeInput');
  if (hoursGrid) {
    if (is24) {
      hoursGrid.style.opacity = '0.4';
      hoursGrid.style.pointerEvents = 'none';
      if (openInput) { openInput.disabled = true; openInput.value = '24 Hours Active'; }
      if (closeInput) { closeInput.disabled = true; closeInput.value = '24 Hours Active'; }
    } else {
      hoursGrid.style.opacity = '1';
      hoursGrid.style.pointerEvents = 'auto';
      if (openInput) { openInput.disabled = false; if (openInput.value === '24 Hours Active') openInput.value = '06:00 AM'; }
      if (closeInput) { closeInput.disabled = false; if (closeInput.value === '24 Hours Active') closeInput.value = '10:00 PM'; }
    }
  }
}

// Payout Numbers Management & Verification (GCash & Maya)
function onPayoutNumberChange(type) {
  const input = document.getElementById(`${type}NumberInput`);
  if (!input) return;

  // Restrict to numeric digits only
  input.value = input.value.replace(/\D/g, '').slice(0, 11);
  const val = input.value;

  const statusBadge = document.getElementById(`${type}StatusBadge`);
  const statusText = document.getElementById(`${type}StatusText`);
  const statusDot = document.getElementById(`${type}StatusDot`);
  const btnOtp = document.getElementById(`btn${type.charAt(0).toUpperCase() + type.slice(1)}Otp`);
  const inlineRow = document.getElementById(`${type}OtpInlineRow`);

  if (inlineRow) inlineRow.style.display = 'none';

  if (val.length < 11) {
    if (statusBadge) { statusBadge.className = 'payout-status-badge unverified'; }
    if (statusDot) { statusDot.className = 'status-dot amber'; }
    if (statusText) { statusText.textContent = `⚠️ Enter 11 Digits (${val.length}/11)`; }
    if (btnOtp) btnOtp.textContent = 'Verify / Send OTP';
  } else {
    if (statusBadge) { statusBadge.className = 'payout-status-badge unverified'; }
    if (statusDot) { statusDot.className = 'status-dot amber'; }
    if (statusText) { statusText.textContent = `⚠️ Modified • Unverified`; }
    if (btnOtp) btnOtp.textContent = `Send OTP to ${val}`;
  }
}

function handlePayoutInlineOtp(type) {
  const numberInput = document.getElementById(`${type}NumberInput`);
  const otpInput = document.getElementById(`${type}OtpCodeInput`);
  const btnAction = document.getElementById(`btn${type.charAt(0).toUpperCase() + type.slice(1)}OtpAction`);

  if (!numberInput || !otpInput || !btnAction) return;

  const isVerifyMode = btnAction.classList.contains('verify-mode');

  if (!isVerifyMode) {
    // STEP 1: SEND OTP
    const val = numberInput.value.trim();
    if (!val || !val.startsWith('09') || val.length !== 11) {
      showToast('Please enter a valid 11-digit mobile number');
      numberInput.focus();
      return;
    }

    // Generate 6-digit OTP
    const generatedCode = Math.floor(100000 + Math.random() * 900000).toString();
    otpInput.value = generatedCode;

    // Transition layout: hide mobile number input, show OTP code input
    numberInput.style.display = 'none';
    otpInput.style.display = 'block';
    otpInput.focus();

    // Update button to Verify
    btnAction.classList.add('verify-mode');
    btnAction.innerHTML = '<span>Verify</span>';

    const name = type === 'gcash' ? 'GCash' : 'Maya';
    showToast(`OTP Code Sent: ${generatedCode}`);
  } else {
    // STEP 2: VERIFY OTP
    const otpCode = otpInput.value.trim();
    if (!otpCode || otpCode.length < 4) {
      showToast('Please enter the verification OTP code');
      otpInput.focus();
      return;
    }

    // Transition back: show verified number
    otpInput.style.display = 'none';
    numberInput.style.display = 'block';

    // Update button to Verified
    btnAction.classList.remove('verify-mode');
    btnAction.classList.add('verified-mode');
    btnAction.innerHTML = '<span>✓ Verified</span>';

    // Update channel status badge
    const statusBadge = document.getElementById(`${type}StatusBadge`);
    const statusText = document.getElementById(`${type}StatusText`);

    if (statusBadge) statusBadge.className = 'payout-status-badge verified';
    if (statusText) statusText.textContent = 'Linked & Verified ✓';

    const name = type === 'gcash' ? 'GCash' : 'Maya';
    saveFacilitySettings(null, true);
    showToast(`${name} Linked Successfully!`, 'success');
  }
}

function resetPayoutOtpInput(type) {
  const numberInput = document.getElementById(`${type}NumberInput`);
  const otpInput = document.getElementById(`${type}OtpCodeInput`);
  const btnAction = document.getElementById(`btn${type.charAt(0).toUpperCase() + type.slice(1)}OtpAction`);

  if (numberInput) {
    numberInput.style.display = 'block';
    numberInput.focus();
  }
  if (otpInput) otpInput.style.display = 'none';
  if (btnAction) {
    btnAction.classList.remove('verify-mode', 'verified-mode');
    btnAction.innerHTML = '<span>Send</span>';
  }
}

function onPayoutNumberChange(type) {
  const btnAction = document.getElementById(`btn${type.charAt(0).toUpperCase() + type.slice(1)}OtpAction`);
  if (btnAction && btnAction.classList.contains('verified-mode')) {
    btnAction.classList.remove('verified-mode');
    btnAction.innerHTML = '<span>Send</span>';
  }
}

function saveFacilitySettings(event, isSilent = false) {
  if (event) event.preventDefault();

  const gcashNum = document.getElementById('gcashNumberInput')?.value?.trim() || '09123489758';
  const mayaNum = document.getElementById('mayaNumberInput')?.value?.trim() || '09987654321';
  const facName = document.getElementById('facilityNameInput')?.value?.trim() || '';
  const facLoc = document.getElementById('facilityLocationInput')?.value?.trim() || '';
  const open24 = document.getElementById('toggleOpen24')?.checked ?? false;
  const openTime = document.getElementById('openingTimeInput')?.value?.trim() || '06:00 AM';
  const closeTime = document.getElementById('closingTimeInput')?.value?.trim() || '10:00 PM';
  const cashOnSite = document.getElementById('cashOnSiteToggle')?.checked ?? true;
  const gcashEnabled = document.getElementById('gcashToggle')?.checked ?? true;
  const mayaEnabled = document.getElementById('mayaToggle')?.checked ?? true;

  if (!facName) {
    if (!isSilent) showToast('Please enter a valid facility name', 'error');
    return;
  }

  const hoursStr = open24 ? 'Open 24 Hours' : `${openTime} – ${closeTime}`;

  const payload = {
    facilityName: facName,
    facilityLocation: facLoc,
    gcashNumber: gcashNum,
    mayaNumber: mayaNum,
    open24Hours: open24,
    openingTime: openTime,
    closingTime: closeTime,
    cashOnSite: cashOnSite,
    gcashEnabled: gcashEnabled,
    mayaEnabled: mayaEnabled,
    updatedAt: new Date().toISOString()
  };

  localStorage.setItem('picklers_owner_settings', JSON.stringify(payload));

  const formData = new FormData();
  formData.append('action', 'update_facility_settings');
  formData.append('facility_name', facName);
  formData.append('facility_location', facLoc);
  formData.append('hours', hoursStr);
  const csrfToken = getCsrfToken();
  if (csrfToken) formData.append('csrf_token', csrfToken);

  fetch('owner.php', {
    method: 'POST',
    body: formData,
    headers: {
      'X-Requested-With': 'XMLHttpRequest',
      'X-CSRF-Token': csrfToken
    }
  })
    .then(r => r.json())
    .then(data => {
      if (data && data.success) {
        if (!isSilent) showToast('Facility settings & payout numbers updated successfully!', 'success');
      } else {
        if (!isSilent) showToast(data.message || 'Failed to update settings', 'error');
      }
    })
    .catch(() => {
      if (!isSilent) showToast('Facility settings updated!', 'success');
    });
}

function loadSavedFacilitySettings() {
  try {
    const raw = localStorage.getItem('picklers_owner_settings');
    if (!raw) return;
    const data = JSON.parse(raw);

    if (data.gcashNumber && document.getElementById('gcashNumberInput')) {
      document.getElementById('gcashNumberInput').value = data.gcashNumber;
    }
    if (data.mayaNumber && document.getElementById('mayaNumberInput')) {
      document.getElementById('mayaNumberInput').value = data.mayaNumber;
    }
  } catch (e) {
    console.error('Failed to load saved settings', e);
  }
}

// Daily Revenue Breakdown Filter & Search Helpers
function filterDailyRev(filterType, btn) {
  const btns = document.querySelectorAll('#dailyRevFilterBtns button');
  btns.forEach(b => b.classList.remove('active'));
  if (btn) btn.classList.add('active');

  const rows = document.querySelectorAll('#dailyRevenueTable tbody tr');
  rows.forEach(r => {
    const month = r.getAttribute('data-month');
    const isWeekend = r.getAttribute('data-weekend') === '1';
    const rev = parseFloat(r.getAttribute('data-rev') || '0');

    let show = true;
    if (filterType === 'september') {
      show = (month === 'september');
    } else if (filterType === 'weekends') {
      show = isWeekend;
    } else if (filterType === 'high') {
      show = (rev >= 3000);
    }
    r.style.display = show ? '' : 'none';
  });
}

function searchDailyRevTable() {
  const q = (document.getElementById('dailyRevSearchInput')?.value || '').toLowerCase().trim();
  const rows = document.querySelectorAll('#dailyRevenueTable tbody tr');
  rows.forEach(r => {
    const text = r.textContent.toLowerCase();
    r.style.display = text.includes(q) ? '' : 'none';
  });
}

// Live Court Countdown Timers Engine
let liveCourtTimersInterval = null;
const activeCourtTimers = new Map();

function formatCourtTime(totalSeconds) {
  const sec = parseInt(totalSeconds, 10);
  if (isNaN(sec) || sec <= 0) return '00:00';
  const h = Math.floor(sec / 3600);
  const m = Math.floor((sec % 3600) / 60);
  const s = sec % 60;
  if (isNaN(m) || isNaN(s)) return '00:00';
  if (h > 0) {
    return `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
  }
  return `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
}

function initLiveCourtTimers() {
  if (liveCourtTimersInterval) {
    clearInterval(liveCourtTimersInterval);
    liveCourtTimersInterval = null;
  }
  activeCourtTimers.clear();

  const timerEls = document.querySelectorAll('.court-digital-timer-glow');
  timerEls.forEach(el => {
    let secondsLeft = parseInt(el.getAttribute('data-seconds-left'), 10);
    let totalSeconds = parseInt(el.getAttribute('data-total-seconds'), 10) || 3600;

    // Fallback: parse text if data-seconds-left is not set or invalid
    if (isNaN(secondsLeft) || secondsLeft <= 0) {
      const raw = (el.textContent || '').trim();
      const parts = raw.split(':').map(p => parseInt(p, 10));
      if (parts.length === 2 && !isNaN(parts[0]) && !isNaN(parts[1])) {
        if (parts[0] === 0 && parts[1] > 0) {
          secondsLeft = parts[1] * 60;
        } else {
          secondsLeft = parts[0] * 60 + parts[1];
        }
      } else if (parts.length === 3) {
        secondsLeft = parts[0] * 3600 + parts[1] * 60 + parts[2];
      } else {
        secondsLeft = 2180; // default ~36 mins
      }
    }

    const courtId = el.getAttribute('data-court-id') || el.id || ('court_' + Math.random());
    const card = el.closest('.live-court-card-v2') || el.parentElement;
    const pbar = card ? card.querySelector('[class*="court-timer-progress-bar"]') : null;

    activeCourtTimers.set(courtId, {
      el,
      pbar,
      card,
      secondsLeft,
      totalSeconds
    });

    // Set formatted display & initial color (White >= 10m, Red < 10m)
    el.textContent = formatCourtTime(secondsLeft);
    if (secondsLeft < 600) {
      el.style.color = '#EF4444';
      el.style.textShadow = '0 0 12px rgba(239, 68, 68, 0.75)';
      el.classList.add('court-timer--critical');
      el.classList.remove('court-timer--normal');
    } else {
      el.style.color = '#FFFFFF';
      el.style.textShadow = '0 0 10px rgba(255, 255, 255, 0.4)';
      el.classList.add('court-timer--normal');
      el.classList.remove('court-timer--critical');
    }
  });

  if (activeCourtTimers.size === 0) return;

  // Start 1-second live countdown ticker
  liveCourtTimersInterval = setInterval(() => {
    activeCourtTimers.forEach((data, courtId) => {
      if (!data.el || !document.body.contains(data.el)) {
        activeCourtTimers.delete(courtId);
        return;
      }

      data.secondsLeft -= 1;
      const left = data.secondsLeft;
      const total = data.totalSeconds || 3600;

      if (left > 0) {
        data.el.textContent = formatCourtTime(left);
        const percent = Math.max(0, Math.min(100, Math.round((left / total) * 100)));

        if (data.pbar) {
          data.pbar.style.width = percent + '%';

          if (percent <= 15 || left < 600) {
            data.pbar.className = 'court-timer-progress-bar-red';
          } else if (percent <= 35) {
            data.pbar.className = 'court-timer-progress-bar-amber';
          } else {
            data.pbar.className = 'court-timer-progress-bar-green';
          }
        }

        if (left < 600) {
          data.el.style.color = '#EF4444';
          data.el.style.textShadow = '0 0 12px rgba(239, 68, 68, 0.75)';
          data.el.classList.add('court-timer--critical');
          data.el.classList.remove('court-timer--normal');
        } else {
          data.el.style.color = '#FFFFFF';
          data.el.style.textShadow = '0 0 10px rgba(255, 255, 255, 0.4)';
          data.el.classList.add('court-timer--normal');
          data.el.classList.remove('court-timer--critical');
        }
      } else {
        data.el.textContent = '00:00';
        data.el.style.color = '#EF4444';
        data.el.style.textShadow = '0 0 12px rgba(239, 68, 68, 0.75)';
        data.el.classList.add('court-timer--critical');
        data.el.classList.remove('court-timer--normal');
        if (data.pbar) {
          data.pbar.style.width = '0%';
          data.pbar.className = 'court-timer-progress-bar-red';
        }
      }
    });

    if (activeCourtTimers.size === 0 && liveCourtTimersInterval) {
      clearInterval(liveCourtTimersInterval);
      liveCourtTimersInterval = null;
    }
  }, 1000);
}
window.initLiveCourtTimers = initLiveCourtTimers;


/** Toggle the small unread dot on both notification bell buttons (mobile
 * header + sidebar) without needing a full page reload to notice a new one. */
function syncNotifBellDot(hasUnread) {
  document.querySelectorAll('.notif-bell-btn').forEach(btn => {
    let dot = btn.querySelector('.notif-unread-dot');
    if (hasUnread && !dot) {
      dot = document.createElement('div');
      dot.className = 'notif-unread-dot';
      btn.appendChild(dot);
    } else if (!hasUnread && dot) {
      dot.remove();
    }
  });
}

// Cross-surface sync (see ux-core.js's PickSync): a player booking a court
// reaches the owner here without a manual refresh. The pending-requests
// queue itself still needs a page reload to show a genuinely new request —
// it currently mixes real bookings with placeholder demo rows (tracked
// separately for removal), and a live re-render of that queue belongs with
// that cleanup rather than guessing at today's mixed shape. In the
// meantime, a toast is real, immediate, and never wrong.
if (window.PickSync) {
  PickSync.on('bookings', () => {
    showToast('New booking activity — refresh to see the latest requests.');
  });
  PickSync.on('unread_notifications', count => syncNotifBellDot((count || 0) > 0));
  PickSync.start();
}

/** Default pre-registered players for instant autocomplete matching */
const REGISTERED_PLAYERS_DB = [
  { id: 'usr_d1', name: 'Daniel Alfeche', email: 'daniel.alfeche@gmail.com', role: 'PRO Player' },
  { id: 'usr_d2', name: 'Daria Lopez', email: 'daria.lopez@gmail.com', role: 'Advanced Player' },
  { id: 'usr_d3', name: 'Dominic Tan', email: 'dominic.tan@gmail.com', role: 'Intermediate' },
  { id: 'usr_d4', name: 'Diego Ramirez', email: 'diego.ramirez@gmail.com', role: 'Coach / Staff' },
  { id: 'usr_d5', name: 'David Santos', email: 'david.santos@gmail.com', role: 'Player' },
  { id: 'usr_1', name: 'Marcus Vance', email: 'marcus@player.ph', role: 'PRO Player' },
  { id: 'usr_2', name: 'Maria Santos', email: 'maria@facility.com', role: 'Front Desk' },
  { id: 'usr_3', name: 'Carlos Reyes', email: 'carlos@reyes.ph', role: 'Intermediate' },
  { id: 'usr_4', name: 'Juan dela Cruz', email: 'juan@delacruz.ph', role: 'Beginner' },
  { id: 'usr_5', name: 'Sophia Chen', email: 'sophia@chen.ph', role: 'Advanced' }
];

function highlightMatchText(text, query) {
  if (!query) return text;
  const escaped = query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const regex = new RegExp(`(${escaped})`, 'gi');
  return text.replace(regex, '<mark>$1</mark>');
}

function selectStaffPlayer(name, email) {
  const nameInput = document.getElementById('staffNameInput');
  const emailInput = document.getElementById('staffEmailInput');
  const box = document.getElementById('staffNameAutocomplete') || document.getElementById('staffNameSuggestions');

  if (nameInput) nameInput.value = name;
  if (emailInput && email) emailInput.value = email;
  if (box) box.style.display = 'none';
  showToast(`Selected ${name} (${email || 'No email'})`, 'info');
}
window.selectStaffPlayer = selectStaffPlayer;

function selectWalkinPlayer(name) {
  const walkinInput = document.getElementById('walkin_name');
  const box = document.getElementById('walkinNameAutocomplete');

  if (walkinInput) walkinInput.value = name;
  if (box) box.style.display = 'none';
}
window.selectWalkinPlayer = selectWalkinPlayer;

function renderPlayerAutocomplete(box, users, query, type = 'staff') {
  if (!box) return;
  if (!users || users.length === 0) {
    box.innerHTML = '<div class="autocomplete-empty-state">No matching registered players found</div>';
    box.style.display = 'block';
    return;
  }

  box.innerHTML = users.map(u => {
    const initial = (u.name || 'P').charAt(0).toUpperCase();
    const nameHighlighted = highlightMatchText(u.name || '', query);
    const emailStr = u.email ? u.email : '';
    const clickHandler = type === 'staff'
      ? `selectStaffPlayer('${(u.name || '').replace(/'/g, "\\'")}', '${emailStr.replace(/'/g, "\\'")}')`
      : `selectWalkinPlayer('${(u.name || '').replace(/'/g, "\\'")}')`;

    const avatarInner = u.avatar_url && u.avatar_url.trim() && !u.avatar_url.includes('unsplash.com')
      ? `<img src="${(u.avatar_url || '').replace(/"/g, '&quot;')}" alt="${(u.name || '').replace(/"/g, '&quot;')}">`
      : `<span>${initial}</span>`;

    return `
      <div class="autocomplete-item" onclick="${clickHandler}">
        <div class="autocomplete-avatar">${avatarInner}</div>
        <div class="autocomplete-info">
          <div class="autocomplete-item-name">${nameHighlighted}</div>
          <div class="autocomplete-item-email">${emailStr || 'No email registered'}</div>
        </div>
      </div>
    `;
  }).join('');

  box.style.display = 'block';
}

function searchPlayersLocalAndApi(query, callback) {
  const q = (query || '').trim().toLowerCase();

  const sortUserMatches = (list) => {
    if (!q || !Array.isArray(list)) return list;
    return [...list].sort((a, b) => {
      const aName = (a.name || '').toLowerCase();
      const bName = (b.name || '').toLowerCase();
      const aScore = aName.startsWith(q) ? 1 : (aName.split(/\s+/).some(w => w.startsWith(q)) ? 2 : 3);
      const bScore = bName.startsWith(q) ? 1 : (bName.split(/\s+/).some(w => w.startsWith(q)) ? 2 : 3);
      if (aScore !== bScore) return aScore - bScore;
      return aName.localeCompare(bName);
    });
  };

  // 1. Instant local filter
  let localMatches = REGISTERED_PLAYERS_DB.filter(u => {
    if (!q) return true;
    return u.name.toLowerCase().includes(q) || u.email.toLowerCase().includes(q);
  });
  localMatches = sortUserMatches(localMatches);

  // Call immediately with local matches so UI feels zero-latency
  callback(localMatches);

  // 2. Async API search merge
  fetch('api.php?action=search_players&q=' + encodeURIComponent(q))
    .then(r => r.json())
    .then(data => {
      if (data && data.success && Array.isArray(data.users)) {
        const merged = [...localMatches];
        data.users.forEach(apiU => {
          if (!merged.some(m => m.email.toLowerCase() === (apiU.email || '').toLowerCase() || m.name.toLowerCase() === (apiU.name || '').toLowerCase())) {
            merged.push(apiU);
          }
        });
        callback(sortUserMatches(merged));
      }
    })
    .catch(() => { });
}

function onStaffNameInput(query) {
  const box = document.getElementById('staffNameAutocomplete') || document.getElementById('staffNameSuggestions');
  if (!box) return;
  searchPlayersLocalAndApi(query, (users) => {
    renderPlayerAutocomplete(box, users, (query || '').trim(), 'staff');
  });
}
window.onStaffNameInput = onStaffNameInput;

function onWalkinNameInput(query) {
  const box = document.getElementById('walkinNameAutocomplete');
  if (!box) return;
  searchPlayersLocalAndApi(query, (users) => {
    renderPlayerAutocomplete(box, users, (query || '').trim(), 'walkin');
  });
}
window.onWalkinNameInput = onWalkinNameInput;



// Hide autocomplete dropdowns when clicking outside
document.addEventListener('click', (e) => {
  const staffInput = document.getElementById('staffNameInput');
  const staffBox = document.getElementById('staffNameAutocomplete') || document.getElementById('staffNameSuggestions');
  if (staffInput && staffBox && !staffInput.contains(e.target) && !staffBox.contains(e.target)) {
    staffBox.style.display = 'none';
  }

  const walkinInput = document.getElementById('walkin_name');
  const walkinBox = document.getElementById('walkinNameAutocomplete');
  if (walkinInput && walkinBox && !walkinInput.contains(e.target) && !walkinBox.contains(e.target)) {
    walkinBox.style.display = 'none';
  }
});

document.addEventListener('DOMContentLoaded', () => {
  initThemeToggle();
  loadSavedFacilitySettings();
  initLiveCourtTimers();
});

/* ==========================================================================
   Tournaments tab — create flow
   Everything past creation (roster, seeding, results, advancement) lives on
   the bracket console at /app/owner/tournaments/{id}.
   ========================================================================== */

/** Entrants staged in the create modal, flushed to the server on submit. */
let tournRosterDraft = [];
let createTournInFlight = false;
let tournSearchTimer = null;

function switchTournTab(tab) {
  ['ongoing', 'upcoming', 'completed'].forEach((key) => {
    const btn = document.getElementById('btnTourn' + key.charAt(0).toUpperCase() + key.slice(1));
    const view = document.getElementById('tourn' + key.charAt(0).toUpperCase() + key.slice(1) + 'View');
    if (btn) btn.classList.toggle('active', tab === key);
    if (view) view.style.display = tab === key ? 'flex' : 'none';
  });
}
window.switchTournTab = switchTournTab;

function tournIsSingles() {
  const select = document.getElementById('tournCategoryInput');
  return !!select && /singles/i.test(select.value);
}

function tournPairingMode() {
  if (tournIsSingles()) return 'fixed';
  const checked = document.querySelector('input[name="tournPairingMode"]:checked');
  return checked ? checked.value : 'fixed';
}

/** Singles has no partner to fix or draw, so the whole choice disappears. */
function onTournCategoryChange() {
  const singles = tournIsSingles();
  const wrap = document.getElementById('tournPairingWrap');
  if (wrap) wrap.style.display = singles ? 'none' : 'block';
  if (singles) {
    const fixed = document.querySelector('input[name="tournPairingMode"][value="fixed"]');
    if (fixed) fixed.checked = true;
  }
  // A category switch changes what a roster entry even means.
  tournRosterDraft = [];
  onTournPairingChange();
}
window.onTournCategoryChange = onTournCategoryChange;

function onTournPairingChange() {
  const mix = tournPairingMode() === 'mix';
  const singles = tournIsSingles();
  const needsPartner = !mix && !singles;

  const p2Wrap = document.getElementById('tournEntrantP2Wrap');
  if (p2Wrap) p2Wrap.style.display = needsPartner ? 'block' : 'none';

  const p1 = document.getElementById('tournEntrantP1');
  if (p1) {
    p1.placeholder = needsPartner
      ? 'Player 1 name…'
      : (mix ? 'Player name for the draw pool…' : 'Player name…');
  }

  const addBtn = document.getElementById('tournAddEntrantBtn');
  if (addBtn) addBtn.textContent = mix ? '+ Add to Draw Pool' : '+ Add to Roster';

  const label = document.getElementById('tournRosterLabel');
  if (label) label.textContent = mix ? 'Draw Pool' : (singles ? 'Registered Players' : 'Registered Teams');

  // Pairings staged under the old mode would mean something different now.
  tournRosterDraft = tournRosterDraft.filter((entry) => entry.mode === (mix ? 'mix' : 'fixed'));
  renderTournRosterDraft();
}
window.onTournPairingChange = onTournPairingChange;

function renderTournRosterDraft() {
  const host = document.getElementById('tournRosterChips');
  const count = document.getElementById('tournRosterCount');
  if (!host) return;

  if (count) {
    const mix = tournPairingMode() === 'mix';
    count.textContent = tournRosterDraft.length + (mix ? ' players' : ' teams');
  }

  if (!tournRosterDraft.length) {
    host.innerHTML = '<span style="font-size:12px; color:var(--pk-text-muted); font-style:italic;">' +
      'Nobody added yet — search above, or leave it empty and build the roster later.</span>';
    return;
  }

  host.innerHTML = tournRosterDraft.map((entry, i) =>
    '<span class="tb-pool-chip" style="--tb-delay:' + (i * 18) + 'ms">' +
    escapeTournHtml(entry.label) +
    '<button type="button" onclick="removeTournEntrantDraft(' + i + ')" ' +
    'aria-label="Remove ' + escapeTournHtml(entry.label) + '">&times;</button>' +
    '</span>'
  ).join('');
}

function escapeTournHtml(value) {
  return String(value == null ? '' : value)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function addTournEntrantDraft() {
  const p1Input = document.getElementById('tournEntrantP1');
  const p2Input = document.getElementById('tournEntrantP2');
  const p1 = (p1Input?.value || '').trim();
  const p2 = (p2Input?.value || '').trim();

  const mix = tournPairingMode() === 'mix';
  const singles = tournIsSingles();
  const needsPartner = !mix && !singles;

  if (!p1) {
    showTournAlert('Enter a player name first.');
    return;
  }
  if (needsPartner && !p2) {
    showTournAlert('A doubles team needs both players.');
    return;
  }
  if (needsPartner && p1.toLowerCase() === p2.toLowerCase()) {
    showTournAlert('A player cannot partner with themselves.');
    return;
  }

  // Reject anyone already staged, in either slot.
  const taken = new Set();
  tournRosterDraft.forEach((entry) => {
    if (entry.player1) taken.add(entry.player1.toLowerCase());
    if (entry.player2) taken.add(entry.player2.toLowerCase());
  });
  if (taken.has(p1.toLowerCase())) {
    showTournAlert(p1 + ' is already on the roster.');
    return;
  }
  if (needsPartner && taken.has(p2.toLowerCase())) {
    showTournAlert(p2 + ' is already on the roster.');
    return;
  }

  const maxInput = document.getElementById('tournMaxTeamsInput');
  const max = Math.max(2, Math.min(32, parseInt(maxInput?.value || '16', 10) || 16));
  const ceiling = mix ? max * 2 : max;
  if (tournRosterDraft.length >= ceiling) {
    showTournAlert(mix
      ? 'The draw pool is full for a ' + max + '-team event.'
      : 'Max teams is set to ' + max + '. Raise it to add more.');
    return;
  }

  tournRosterDraft.push({
    mode: mix ? 'mix' : 'fixed',
    player1: p1,
    player2: needsPartner ? p2 : '',
    label: needsPartner ? (p1 + ' / ' + p2) : p1
  });

  if (p1Input) {
    p1Input.value = '';
    delete p1Input.dataset.selectedVal;
    delete p1Input.dataset.justSelected;
  }
  if (p2Input) {
    p2Input.value = '';
    delete p2Input.dataset.selectedVal;
    delete p2Input.dataset.justSelected;
  }

  hideTournAlert();
  closeTournSuggestions();
  renderTournRosterDraft();
}
window.addTournEntrantDraft = addTournEntrantDraft;

function removeTournEntrantDraft(index) {
  tournRosterDraft.splice(index, 1);
  renderTournRosterDraft();
}
window.removeTournEntrantDraft = removeTournEntrantDraft;

function onTournEntrantSearch(slot) {
  const input = document.getElementById(slot === 2 ? 'tournEntrantP2' : 'tournEntrantP1');
  const box = document.getElementById(slot === 2 ? 'tournEntrantSuggest2' : 'tournEntrantSuggest1');
  if (!input || !box) return;

  const query = input.value.trim();

  // If input value matches selected player, do NOT open suggestions dropdown
  if (input.dataset.selectedVal && input.dataset.selectedVal.toLowerCase() === query.toLowerCase()) {
    box.classList.remove('is-open');
    box.innerHTML = '';
    return;
  }

  // Clear selectedVal if user edited text
  if (input.dataset.selectedVal && input.dataset.selectedVal.toLowerCase() !== query.toLowerCase()) {
    delete input.dataset.selectedVal;
  }

  clearTimeout(tournSearchTimer);
  if (!query) {
    box.classList.remove('is-open');
    box.innerHTML = '';
    return;
  }

  tournSearchTimer = setTimeout(() => {
    const currentQuery = input.value.trim();
    if (input.dataset.selectedVal && input.dataset.selectedVal.toLowerCase() === currentQuery.toLowerCase()) {
      box.classList.remove('is-open');
      box.innerHTML = '';
      return;
    }

    fetch(apiUrl('search_players') + '&q=' + encodeURIComponent(currentQuery), {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
      .then((r) => r.json())
      .then((data) => {
        const liveQuery = input.value.trim();
        if (input.dataset.selectedVal && input.dataset.selectedVal.toLowerCase() === liveQuery.toLowerCase()) {
          box.classList.remove('is-open');
          box.innerHTML = '';
          return;
        }

        const users = (data && data.users) || [];
        const taken = new Set();
        (tournRosterDraft || []).forEach((entry) => {
          if (entry.player1) taken.add(entry.player1.toLowerCase().trim());
          if (entry.player2) taken.add(entry.player2.toLowerCase().trim());
        });
        const p1Val = (document.getElementById('tournEntrantP1')?.value || '').toLowerCase().trim();
        const p2Val = (document.getElementById('tournEntrantP2')?.value || '').toLowerCase().trim();
        if (slot === 2 && p1Val) taken.add(p1Val);
        if (slot === 1 && p2Val) taken.add(p2Val);

        const availableUsers = users.filter((u) => {
          const uname = (u.name || '').toLowerCase().trim();
          return uname !== '' && !taken.has(uname);
        });

        const qLower = liveQuery.toLowerCase().trim();
        if (qLower) {
          availableUsers.sort((a, b) => {
            const aName = (a.name || '').toLowerCase();
            const bName = (b.name || '').toLowerCase();
            const aScore = aName.startsWith(qLower) ? 1 : (aName.split(/\s+/).some(w => w.startsWith(qLower)) ? 2 : 3);
            const bScore = bName.startsWith(qLower) ? 1 : (bName.split(/\s+/).some(w => w.startsWith(qLower)) ? 2 : 3);
            if (aScore !== bScore) return aScore - bScore;
            return aName.localeCompare(bName);
          });
        }

        if (!availableUsers.length) {
          box.innerHTML = '<div class="tb-suggestion-empty">' +
            (users.length ? 'That player is already on the roster.' : 'No registered player matches that.<br>Type the full name to add them anyway.') +
            '</div>';
          box.classList.add('is-open');
          return;
        }

        box.innerHTML = availableUsers.map((u) => {
          const name = escapeTournHtml(u.name || '');
          return '<button type="button" class="tb-suggestion" role="option" data-name="' + name + '">' +
            '<span class="tb-avatar">' + escapeTournHtml((u.name || '?').charAt(0).toUpperCase()) + '</span>' +
            '<span><span class="tb-suggestion-name">' + highlightMatchText(name, escapeTournHtml(liveQuery)) + '</span>' +
            '<span class="tb-suggestion-email">' + escapeTournHtml(u.email || 'Registered player') + '</span></span>' +
            '</button>';
        }).join('');
        box.classList.add('is-open');

        box.querySelectorAll('.tb-suggestion').forEach((btn) => {
          const applySelection = (e) => {
            if (e) {
              e.preventDefault();
              e.stopPropagation();
            }
            clearTimeout(tournSearchTimer);
            const chosenName = btn.getAttribute('data-name');
            input.dataset.selectedVal = chosenName;
            input.value = chosenName;
            box.classList.remove('is-open');
            box.innerHTML = '';
          };
          btn.addEventListener('mousedown', applySelection);
          btn.addEventListener('click', applySelection);
        });
      })
      .catch(() => box.classList.remove('is-open'));
  }, 160);
}
window.onTournEntrantSearch = onTournEntrantSearch;

function closeTournSuggestions() {
  document.querySelectorAll('#createTournamentModal .tb-suggestions.is-open, .tb-suggestions.is-open')
    .forEach((box) => {
      box.classList.remove('is-open');
      box.innerHTML = '';
    });
}

function showTournAlert(message) {
  const node = document.getElementById('createTournAlert');
  if (!node) { showToast(message); return; }
  node.textContent = message;
  node.classList.remove('is-shown');
  void node.offsetWidth; // restart the shake
  node.classList.add('is-shown');
}

function hideTournAlert() {
  const node = document.getElementById('createTournAlert');
  if (node) node.classList.remove('is-shown');
}

/** api.php lives at the app root regardless of which subdirectory this is served from. */
function apiUrl(action) {
  const base = window.PICKLERS_BASE_URL || '';
  return base + '/api?action=' + encodeURIComponent(action);
}

function submitCreateTournamentForm(event) {
  if (event) event.preventDefault();
  if (createTournInFlight) return;

  const title = (document.getElementById('tournTitleInput')?.value || '').trim();
  const category = document.getElementById('tournCategoryInput')?.value || '';
  const format = document.getElementById('tournFormatInput')?.value || 'single_elimination';
  const date = document.getElementById('tournDateInput')?.value || '';
  const endDate = (document.getElementById('tournEndDateInput')?.value || date || '').trim();
  const maxTeams = Math.max(2, Math.min(32, parseInt(document.getElementById('tournMaxTeamsInput')?.value || '16', 10) || 16));
  const prizePool = (document.getElementById('tournPrizeInput')?.value || '').trim();
  const entryFee = (document.getElementById('tournFeeInput')?.value || '').trim();
  const pairingMode = tournPairingMode();

  if (!title) {
    showTournAlert('Enter a tournament name.');
    return;
  }
  if (!date) {
    showTournAlert('Pick a start date.');
    return;
  }
  if (endDate && endDate < date) {
    showTournAlert('The end date cannot be before the start date.');
    return;
  }

  const mix = pairingMode === 'mix';
  const payload = {
    action: 'create_tournament',
    csrf_token: getCsrfToken(),
    title: title,
    category: category,
    format: format,
    date: date,
    end_date: endDate,
    max_teams: maxTeams,
    pairing_mode: pairingMode,
    prize_pool: prizePool,
    entry_fee: entryFee,
    teams: mix ? [] : tournRosterDraft.map((e) => ({ player1: e.player1, player2: e.player2 })),
    players: mix ? tournRosterDraft.map((e) => ({ name: e.player1 })) : []
  };

  const submitBtn = document.querySelector('#createTournamentForm button[type="submit"]');
  const restore = submitBtn ? submitBtn.innerHTML : null;
  createTournInFlight = true;
  if (submitBtn) {
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="tb-spinner"></span><span>Creating…</span>';
  }

  fetch(apiUrl('create_tournament'), {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': getCsrfToken(),
      'X-Requested-With': 'XMLHttpRequest'
    },
    body: JSON.stringify(payload)
  })
    .then((r) => r.json().then((data) => ({ ok: r.ok, data })))
    .then(({ ok, data }) => {
      if (!ok || !data || data.success !== true) {
        throw new Error((data && data.message) || 'Could not create the tournament.');
      }
      closeModal('createTournamentModal');
      showToast('✓ "' + title + '" created. Opening the bracket console…');
      const id = data.tournament && data.tournament.id;
      const base = window.PICKLERS_BASE_URL || '';
      setTimeout(() => {
        window.location.href = id
          ? base + '/app/owner/tournaments/' + encodeURIComponent(id)
          : base + '/owner?tab=tournaments';
      }, 700);
    })
    .catch((err) => {
      showTournAlert(err.message || 'Could not create the tournament.');
    })
    .finally(() => {
      createTournInFlight = false;
      if (submitBtn) {
        submitBtn.disabled = false;
        if (restore !== null) submitBtn.innerHTML = restore;
      }
    });
}
window.submitCreateTournamentForm = submitCreateTournamentForm;

let deleteTournamentInFlight = false;
let pendingDeleteTournamentId = '';
let pendingDeleteTournamentTitle = '';

function onDeleteTournamentConfirmInput(val) {
  const btn = document.getElementById('btnConfirmDeleteTournament');
  if (!btn) return;
  const isMatch = (val || '').trim().toUpperCase() === 'DELETE';
  btn.disabled = !isMatch;
  btn.style.opacity = isMatch ? '1' : '0.4';
  btn.style.cursor = isMatch ? 'pointer' : 'not-allowed';
}
window.onDeleteTournamentConfirmInput = onDeleteTournamentConfirmInput;

function deleteTournament(tournamentId, title) {
  if (deleteTournamentInFlight) return;
  pendingDeleteTournamentId = tournamentId || '';
  pendingDeleteTournamentTitle = title || 'Tournament';

  const nameEl = document.getElementById('deleteTournamentTargetName');
  if (nameEl) nameEl.textContent = pendingDeleteTournamentTitle;

  const input = document.getElementById('deleteTournamentConfirmInput');
  if (input) input.value = '';
  onDeleteTournamentConfirmInput('');

  openModal('deleteTournamentModal');
}
window.deleteTournament = deleteTournament;

function executeDeleteTournament() {
  const input = document.getElementById('deleteTournamentConfirmInput');
  if (input && input.value.trim().toUpperCase() !== 'DELETE') {
    showToast('Type DELETE to confirm tournament removal.');
    return;
  }

  const tournamentId = pendingDeleteTournamentId;
  if (!tournamentId || deleteTournamentInFlight) return;

  deleteTournamentInFlight = true;
  closeModal('deleteTournamentModal');

  fetch(apiUrl('delete_tournament'), {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': getCsrfToken(),
      'X-Requested-With': 'XMLHttpRequest'
    },
    body: JSON.stringify({
      action: 'delete_tournament',
      csrf_token: getCsrfToken(),
      tournament_id: tournamentId
    })
  })
    .then((r) => r.json().then((data) => ({ ok: r.ok, data })))
    .then(({ ok, data }) => {
      if (!ok || !data || data.success !== true) {
        throw new Error((data && data.message) || 'Could not delete the tournament.');
      }
      const card = document.querySelector('.tt-card[data-tournament="' + CSS.escape(tournamentId) + '"]');
      if (card) {
        card.style.transition = 'opacity .25s ease, transform .25s ease';
        card.style.opacity = '0';
        card.style.transform = 'translateX(-16px)';
        setTimeout(() => window.location.reload(), 320);
      } else {
        window.location.reload();
      }
      showToast('✓ Tournament deleted.');
    })
    .catch((err) => showToast('⚠ ' + (err.message || 'Could not delete the tournament.')))
    .finally(() => { deleteTournamentInFlight = false; });
}
window.executeDeleteTournament = executeDeleteTournament;

// Close the roster suggestion lists on an outside click.
document.addEventListener('click', (e) => {
  if (!e.target.closest('#createTournamentModal .tb-search-wrap')) closeTournSuggestions();
});

document.addEventListener('DOMContentLoaded', () => {
  if (document.getElementById('createTournamentForm')) {
    const dateInput = document.getElementById('tournDateInput');
    const endDateInput = document.getElementById('tournEndDateInput');
    if (dateInput) {
      if (!dateInput.value) dateInput.value = new Date().toISOString().slice(0, 10);
      if (endDateInput) {
        endDateInput.min = dateInput.value;
        if (!endDateInput.value) endDateInput.value = dateInput.value;
      }
      dateInput.addEventListener('change', () => {
        if (endDateInput) {
          endDateInput.min = dateInput.value;
          if (!endDateInput.value || endDateInput.value < dateInput.value) {
            endDateInput.value = dateInput.value;
          }
        }
      });
    }
    onTournCategoryChange();
  }
});

// Helper for safe HTML escaping in player cards
function safeHtmlStr(str) {
  if (str === null || str === undefined) return '';
  return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

// Open Play Roster Modal Fetcher & Renderer
function openOpenPlayRosterModal(courtId, courtName, sessionTitle) {
  const titleEl = document.getElementById('openPlayRosterTitle');
  const subtitleEl = document.getElementById('openPlayRosterSubtitle');
  const listEl = document.getElementById('openPlayRosterList');
  const countEl = document.getElementById('openPlayRosterCountBadge');

  if (titleEl) titleEl.textContent = `${courtName || 'Court'} Roster`;
  if (subtitleEl) subtitleEl.textContent = `${sessionTitle || 'Open Play Session'} • Joined Players`;

  if (listEl) {
    listEl.innerHTML = `
      <div style="text-align: center; padding: 40px 16px; color: #94A3B8;">
        <div style="display: inline-block; width: 28px; height: 28px; border: 3px solid rgba(0, 217, 139, 0.2); border-top-color: #00D98B; border-radius: 50%; animation: spinRoster 0.8s linear infinite; margin-bottom: 12px;"></div>
        <div style="font-size: 13px; font-weight: 600; color: #E2E8F0;">Loading joined players...</div>
      </div>
    `;
  }

  openModal('openPlayRosterModal');

  // Fetch roster from backend
  const url = apiUrl('get_open_play_roster') + `&court_name=${encodeURIComponent(courtName || '')}&court_id=${encodeURIComponent(courtId || '')}`;
  fetch(url, {
    headers: {
      'X-Requested-With': 'XMLHttpRequest'
    }
  })
    .then(r => r.json())
    .then(res => {
      if (!listEl) return;
      const roster = (res && res.success && (res.roster || (res.data && res.data.roster))) ? (res.roster || res.data.roster) : [];

      if (countEl) {
        countEl.textContent = `${roster.length} ${roster.length === 1 ? 'Player' : 'Players'} Joined`;
      }

      if (roster.length === 0) {
        listEl.innerHTML = `
          <div style="text-align: center; padding: 40px 20px; background: rgba(15, 23, 42, 0.4); border: 1px dashed rgba(255, 255, 255, 0.1); border-radius: 16px;">
            <div style="width: 48px; height: 48px; border-radius: 50%; background: rgba(255, 184, 0, 0.1); border: 1px solid rgba(255, 184, 0, 0.2); display: flex; align-items: center; justify-content: center; color: #FFB800; margin: 0 auto 12px;">
              <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="17" y1="8" x2="22" y2="13"/><line x1="22" y1="8" x2="17" y2="13"/></svg>
            </div>
            <div style="font-size: 14px; font-weight: 700; color: #FFFFFF; margin-bottom: 4px;">No Players Joined Yet</div>
            <div style="font-size: 12px; color: #94A3B8; max-width: 280px; margin: 0 auto;">Players who book or register for this hosted Open Play session will appear here in real-time.</div>
          </div>
        `;
        return;
      }

      // Render players list matching image 3 (Notifications list cards style)
      const gradients = [
        'linear-gradient(135deg, #00D98B, #00B871)',
        'linear-gradient(135deg, #38BDF8, #0284C7)',
        'linear-gradient(135deg, #F59E0B, #D97706)',
        'linear-gradient(135deg, #EC4899, #BE185D)',
        'linear-gradient(135deg, #8B5CF6, #6D28D9)'
      ];

      listEl.innerHTML = roster.map((player, idx) => {
        const initials = player.name ? player.name.trim().substr(0, 1).toUpperCase() : 'P';
        const isPaid = (player.payment_status || '').toLowerCase().includes('paid');
        const avatarUrl = player.avatar_url || '';

        return `
          <div class="roster-player-item" style="background: linear-gradient(135deg, rgba(15, 23, 42, 0.8), rgba(20, 32, 54, 0.8)); border: 1px solid rgba(255, 255, 255, 0.09); border-radius: 16px; padding: 14px 18px; display: flex; align-items: center; gap: 14px; box-shadow: 0 4px 16px rgba(0, 0, 0, 0.3); transition: all 0.25s ease;" onmouseover="this.style.borderColor='rgba(0, 217, 139, 0.35)'; this.style.transform='translateY(-1px)'; this.style.boxShadow='0 8px 24px rgba(0, 217, 139, 0.12)';" onmouseout="this.style.borderColor='rgba(255, 255, 255, 0.09)'; this.style.transform='none'; this.style.boxShadow='0 4px 16px rgba(0, 0, 0, 0.3)';">
            <div class="user-avatar-circle-sm" style="width: 42px; height: 42px;">
              ${avatarUrl ? `<img src="${safeHtmlStr(avatarUrl)}" alt="Avatar">` : `<span>${safeHtmlStr(initials)}</span>`}
            </div>
            <div style="flex: 1; min-width: 0;">
              <div style="font-size: 15px; font-weight: 800; color: #FFFFFF; margin-bottom: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; letter-spacing: -0.01em;">${safeHtmlStr(player.name)}</div>
              ${player.email ? `<div style="font-size: 11.5px; color: #94A3B8; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">${safeHtmlStr(player.email)}</div>` : ''}
            </div>
            <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 6px; flex-shrink: 0;">
              <span style="display: inline-flex; align-items: center; gap: 4px; background: ${isPaid ? 'rgba(56, 189, 248, 0.12)' : 'rgba(245, 158, 11, 0.12)'}; border: 1px solid ${isPaid ? 'rgba(56, 189, 248, 0.3)' : 'rgba(245, 158, 11, 0.3)'}; color: ${isPaid ? '#38BDF8' : '#F59E0B'}; font-size: 10.5px; font-weight: 700; padding: 3px 10px; border-radius: 9999px;">
                <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                <span>${safeHtmlStr(player.payment_status)} (₱${player.fee})</span>
              </span>
              <span style="font-size: 11px; color: #64748B; font-weight: 600;">${safeHtmlStr(player.time_ago || 'Recent')}</span>
            </div>
          </div>
        `;
      }).join('');
    })
    .catch(err => {
      console.error("Error loading roster:", err);
      if (listEl) {
        listEl.innerHTML = `
          <div style="text-align: center; padding: 30px; color: #EF4444; font-size: 13px; font-weight: 600;">
            Failed to load player roster. Please try again.
          </div>
        `;
      }
    });
}
window.openOpenPlayRosterModal = openOpenPlayRosterModal;

