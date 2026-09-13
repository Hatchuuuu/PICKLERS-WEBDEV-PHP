<?php
declare(strict_types=1);
/**
 * Court Owner Portal - Facility Dashboard View
 * @var array $currentFacility
 * @var array $metrics
 * @var array $financials
 * @var array $liveCourts
 * @var array $pendingRequests
 */
$liveCourtsFullCount = count(array_filter($liveCourts, fn($c) => in_array($c['status'] ?? '', ['occupied', 'open_play'], true)));
?>
<div class="owner-topbar" style="align-items:center; justify-content:space-between;">
  <div class="owner-topbar-title-wrap">
    <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
      <h1 class="owner-page-title" style="margin:0; display:inline-flex; align-items:center;">Facility Dashboard</h1>
      <button type="button" class="btn-eye-toggle" id="btnEyeToggle" onclick="toggleRevenueStats()" title="Toggle Metrics Tray" style="color:#64748B;">
        <svg id="eyeIconSvg" xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><line x1="2" y1="2" x2="22" y2="22"/>
        </svg>
      </button>
      <button type="button" class="btn-camera-scan-icon" onclick="openModal('scannerModal')" title="Scan QR Pass">
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z"/><circle cx="12" cy="13" r="3"/>
        </svg>
      </button>
    </div>
    <p class="owner-page-subtitle">Manage your courts and track performance</p>
  </div>
</div>

<!-- High-Definition Analytics & Performance KPI Suite -->
<div class="metrics-tray-container collapsed" id="metricsTray">
  
  <!-- 1. Hero Revenue Intelligence & Trend Card -->
  <div class="kpi-card kpi-hero-card">
    <div class="kpi-hero-header">
      <div class="kpi-hero-title-group">
        <div>
          <div class="kpi-label-row">
            <span class="kpi-label">MONTHLY REVENUE</span>
          </div>
          <div class="kpi-hero-val-wrap">
            <span class="kpi-val kpi-hero-val"><?= htmlspecialchars($metrics['monthly_revenue']['value']) ?></span>
          </div>
        </div>
      </div>

      <div class="kpi-hero-quick-stats">
        <div class="quick-stat-item">
          <span class="quick-stat-label">Daily Avg</span>
          <span class="quick-stat-num">₱<?= number_format($financials['daily_avg_gross'] ?? 0) ?></span>
        </div>
        <div class="quick-stat-divider"></div>
        <div class="quick-stat-item">
          <span class="quick-stat-label">Peak Day</span>
          <span class="quick-stat-num text-emerald">₱<?= number_format($financials['peak_day_gross'] ?? 0) ?></span>
        </div>
        <div class="quick-stat-divider"></div>
        <div class="quick-stat-item quick-stat-clickable" onclick="openModal('dailyRevenueModal')" title="Click to view September daily income breakdown" role="button" tabindex="0">
          <span class="quick-stat-label quick-stat-yesterday-label">
            Yesterday's Income
            <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
          </span>
          <span class="quick-stat-num text-cyan">₱<?= number_format($financials['yesterday_gross'] ?? 0) ?></span>
        </div>
      </div>
    </div>
  </div>

  <!-- 2. Symmetrical 4-Card Performance Grid -->
  <div class="kpi-grid-4col">
    
    <!-- Card 1: REPEATERS -->
    <div class="kpi-card kpi-sub-card">
      <div class="kpi-top-row">
        <div class="kpi-icon-badge badge-sub-circle badge-red">
          <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        </div>
        <span class="kpi-delta-pill delta-pill-red">
          <span><?= htmlspecialchars($metrics['repeaters_rate']['delta']) ?></span>
        </span>
      </div>
      <div class="kpi-label">REPEATERS RATE</div>
      <div class="kpi-val-row">
        <span class="kpi-val"><?= htmlspecialchars($metrics['repeaters_rate']['value']) ?></span>
        <span class="kpi-sub-context"><?= (int)($financials['repeaters_count'] ?? 0) ?> / <?= (int)($financials['repeat_eligible_count'] ?? 0) ?> players</span>
      </div>
    </div>

    <!-- Card 2: TODAY'S REVENUE -->
    <div class="kpi-card kpi-sub-card">
      <div class="kpi-top-row">
        <div class="kpi-icon-badge badge-sub-circle badge-cyan">
          <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
        </div>
        <span class="kpi-delta-pill delta-pill-cyan">
          <span><?= htmlspecialchars($metrics['today_revenue']['delta']) ?></span>
        </span>
      </div>
      <div class="kpi-label">TODAY'S REVENUE</div>
      <div class="kpi-val-row">
        <span class="kpi-val"><?= htmlspecialchars($metrics['today_revenue']['value']) ?></span>
        <?php $todaySessions = (int)($financials['today_sessions'] ?? 0); ?>
        <span class="kpi-sub-context"><?= $todaySessions ?> session<?= $todaySessions === 1 ? '' : 's' ?></span>
      </div>
    </div>

    <!-- Card 3: ACTIVE BOOKINGS -->
    <div class="kpi-card kpi-sub-card">
      <div class="kpi-top-row">
        <div class="kpi-icon-badge badge-sub-circle badge-amber">
          <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        </div>
        <span class="kpi-delta-pill delta-pill-amber">
          <span class="kpi-live-dot"></span>
          <span>Live</span>
        </span>
      </div>
      <div class="kpi-label">ACTIVE BOOKINGS</div>
      <div class="kpi-val-row">
        <span class="kpi-val"><?= htmlspecialchars($metrics['active_bookings']['value']) ?></span>
        <span class="kpi-sub-context"><?= $liveCourtsFullCount ?> court<?= $liveCourtsFullCount === 1 ? '' : 's' ?> full</span>
      </div>
    </div>

    <!-- Card 4: NEW PLAYERS -->
    <div class="kpi-card kpi-sub-card">
      <div class="kpi-top-row">
        <div class="kpi-icon-badge badge-sub-circle badge-purple">
          <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        </div>
        <span class="kpi-delta-pill delta-pill-purple">
          <span><?= htmlspecialchars($metrics['new_players']['delta']) ?></span>
        </span>
      </div>
      <div class="kpi-label">NEW PLAYERS</div>
      <div class="kpi-val-row">
        <span class="kpi-val"><?= htmlspecialchars($metrics['new_players']['value']) ?></span>
        <span class="kpi-sub-context"><?= (int)($financials['repeat_eligible_count'] ?? 0) ?> total players</span>
      </div>
    </div>

  </div>
</div>

<!-- Mobile-Only Segmented View Selector Pills -->
<div class="owner-segmented-pills-wrap">
  <div class="owner-segmented-pills">
    <button type="button" class="pill-btn-amber active" id="pillLiveCourts" onclick="switchDashboardView('live')">
      <span>Live Courts</span>
    </button>
    <button type="button" class="pill-requests-glow <?= (!empty($pendingRequests) && count($pendingRequests) > 0) ? 'requests-breathing-red' : '' ?>" id="pillRequests" onclick="switchDashboardView('requests')">
      <span>Requests</span>
      <span class="pill-badge-red" id="pillRequestBadge"><?= count($pendingRequests) ?></span>
    </button>
  </div>
</div>

<!-- Main 2-Column Dashboard Layout (Laptop: Side-by-Side | Mobile: Controlled by Pills) -->
<div class="owner-dashboard-grid-layout">
  
  <!-- Column 1: Live Courts Section -->
  <section class="owner-dash-col-left" id="secLiveCourts">
    <!-- Desktop-Only Section Header Pill -->
    <div class="desktop-section-pill-wrap">
      <div class="pill-badge-desktop-dark">Live Courts</div>
    </div>

    <!-- Courts Grid (3 columns on Desktop, 2 columns on Mobile) -->
    <div class="live-courts-grid">
      <?php foreach ($liveCourts as $court): ?>
        <div class="live-court-card-v2" id="card_<?= htmlspecialchars($court['id']) ?>">
          <div class="court-card-header-row" style="position:relative;">
            <div style="display:flex; align-items:center; gap:6px;">
              <div class="court-name-text" title="<?= htmlspecialchars($court['name']) ?>"><?= htmlspecialchars($court['name']) ?></div>
              <?php if (!empty($court['upcoming_bookings']) || !empty($court['completed_bookings'])): ?>
                <div style="position:relative; display:inline-block;">
                  <button type="button" onclick="event.stopPropagation(); toggleCourtDropdown('dd_<?= htmlspecialchars(addslashes($court['id'])) ?>')" style="background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.12); color:#CBD5E1; padding:2px 6px; border-radius:6px; font-size:10.5px; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:3px; transition:all 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.12)'" onmouseout="this.style.background='rgba(255,255,255,0.06)'">
                    <span>Schedule ▾</span>
                  </button>
                  <div class="court-schedule-dropdown" id="dd_<?= htmlspecialchars($court['id']) ?>" style="display:none; position:absolute; top:100%; left:0; margin-top:4px; background:#0F172A; border:1px solid rgba(255,255,255,0.15); border-radius:12px; padding:10px; width:220px; z-index:100; box-shadow:0 12px 30px rgba(0,0,0,0.6); text-align:left;">
                    <?php if (!empty($court['upcoming_bookings'])): ?>
                      <div style="font-size:10px; font-weight:800; color:#00D98B; text-transform:uppercase; letter-spacing:0.04em; margin-bottom:4px;">Upcoming Bookings</div>
                      <?php foreach ($court['upcoming_bookings'] as $ub): ?>
                        <div style="font-size:11.5px; color:#FFFFFF; font-weight:700; margin-bottom:4px; display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid rgba(255,255,255,0.05); padding-bottom:3px;">
                          <span><?= htmlspecialchars($ub['user_name']) ?></span>
                          <span style="color:#00D98B; font-weight:800;"><?= htmlspecialchars($ub['time']) ?></span>
                        </div>
                      <?php endforeach; ?>
                    <?php endif; ?>
                    <?php if (!empty($court['completed_bookings'])): ?>
                      <div style="font-size:10px; font-weight:800; color:#94A3B8; text-transform:uppercase; letter-spacing:0.04em; margin-top:8px; margin-bottom:4px;">Completed Today</div>
                      <?php foreach ($court['completed_bookings'] as $cb): ?>
                        <div style="font-size:11.5px; color:#94A3B8; font-weight:600; margin-bottom:3px; display:flex; justify-content:space-between; align-items:center;">
                          <span><?= htmlspecialchars($cb['user_name']) ?></span>
                          <span><?= htmlspecialchars($cb['time']) ?></span>
                        </div>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endif; ?>
            </div>
            <?php if (($court['dot'] ?? '') === 'cyan'): ?>
              <span class="court-status-dot-cyan"></span>
            <?php elseif (($court['dot'] ?? '') === 'amber' || !empty($court['has_open_play'])): ?>
              <button type="button" class="btn-court-players-trigger" onclick="openOpenPlayRosterModal('<?= htmlspecialchars(addslashes($court['id'])) ?>', '<?= htmlspecialchars(addslashes($court['name'])) ?>', '<?= htmlspecialchars(addslashes($court['open_play_title'] ?? 'Open Play Session')) ?>')" title="View joined players list and profiles" style="background: transparent; border: none; color: #FFFFFF; padding: 2px 4px; font-size: 12px; font-weight: 800; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; transition: all 0.2s;" onmouseover="this.style.opacity='0.8'" onmouseout="this.style.opacity='1'">
                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                <span>Players ▾</span>
              </button>
            <?php elseif (($court['dot'] ?? '') === 'green'): ?>
              <span class="court-status-dot-green"></span>
            <?php else: ?>
              <span class="court-status-dot-gray"></span>
            <?php endif; ?>
          </div>

          <?php if ($court['status'] === 'occupied'):
            $secLeftVal = (int)($court['seconds_left'] ?? 3600);
            $timerClass = ($secLeftVal < 600) ? 'court-timer--critical' : 'court-timer--normal';
          ?>
            <div class="court-player-sub"><?= htmlspecialchars($court['player_name'] ?? 'Player') ?></div>
            <div class="court-digital-timer-glow <?= $timerClass ?>"
                 id="timer_<?= htmlspecialchars($court['id']) ?>"
                 data-court-id="<?= htmlspecialchars($court['id']) ?>"
                 data-seconds-left="<?= (int)($court['seconds_left'] ?? 2180) ?>"
                 data-total-seconds="<?= (int)($court['total_seconds'] ?? 3600) ?>"><?= (!empty($court['timer']) && $court['timer'] !== 'NaN:NaN') ? htmlspecialchars($court['timer']) : '36:20' ?></div>
            <div class="court-timer-progress-track">
              <div class="court-timer-progress-bar-<?= $court['progress_color'] ?? 'green' ?>" 
                   id="pbar_<?= htmlspecialchars($court['id']) ?>" 
                   style="width: <?= (int)($court['progress_percent'] ?? 50) ?>%;"></div>
            </div>
            <button type="button" class="btn-end-session-red-dark" onclick="endCourtSession('<?= htmlspecialchars($court['id']) ?>', '<?= htmlspecialchars(addslashes($court['name'])) ?>')">End Session Early</button>
          <?php elseif ($court['status'] === 'open_play' || !empty($court['has_open_play'])): 
            $joinedCount = (int)($court['joined_players'] ?? 0);
            $maxCap = (int)($court['max_players'] ?? 20);
            $timeVal = !empty($court['time_range']) ? $court['time_range'] : '6:00 AM – 11:00 PM';
          ?>
            <div style="display:flex; flex-direction:column; align-items:center; justify-content:center; gap:6px; margin-top: 4px; margin-bottom: 4px; text-align:center;">
              <div style="display:flex; align-items:center; gap:5px; font-size:11.5px; font-weight:700; color:#FFFFFF;">
                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                <span><?= htmlspecialchars($timeVal) ?></span>
              </div>
              <div style="font-size:15px; font-weight:900; color:#FFFFFF; letter-spacing:-0.01em;">
                <span style="color:#00D98B;"><?= $joinedCount ?></span><span style="color:#94A3B8; font-size:12.5px; font-weight:600;">/<?= $maxCap ?> Joined</span>
              </div>
              <span style="background: rgba(255, 184, 0, 0.15); border: 1px solid rgba(255, 184, 0, 0.4); color: #FFB800; font-size: 10px; font-weight: 800; padding: 4px 10px; border-radius: 9999px; letter-spacing: 0.04em; text-transform: uppercase; white-space: nowrap;">HOSTED OPEN PLAY</span>
            </div>
          <?php elseif ($court['status'] === 'waiting'): ?>
            <div class="court-waiting-pill-wrap" style="display:flex; flex-direction:column; align-items:center; justify-content:center; gap:6px; margin-top:8px; margin-bottom:8px;">
              <?php if (!empty($court['next_booking'])): ?>
                <span style="background: rgba(0, 217, 139, 0.12); border: 1px solid rgba(0, 217, 139, 0.4); color: #00D98B; font-weight: 800; font-size: 11.5px; padding: 6px 14px; border-radius: 9999px; letter-spacing: 0.01em; display: inline-block;">
                  Booked <?= htmlspecialchars($court['next_booking']['time']) ?>
                </span>
                <div style="font-size: 12.5px; color: #CBD5E1; font-weight: 600; text-align: center; max-width: 140px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                  <?= htmlspecialchars($court['next_booking']['user_name']) ?>
                </div>
              <?php else: ?>
                <span class="court-waiting-pill">Waiting for players</span>
              <?php endif; ?>
            </div>
          <?php elseif ($court['status'] === 'maintenance'): ?>
            <div class="court-maintenance-wrap">
              <span class="court-maintenance-text">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                Under maintenance
              </span>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- Column 2: Requests Section -->
  <section class="owner-dash-col-right" id="secRequestsQueue">
    <!-- Section Header Pill (Desktop: pill with red badge; Mobile: integrated or header) -->
    <div class="desktop-section-pill-wrap">
      <div class="pill-badge-requests-red <?= (!empty($pendingRequests) && count($pendingRequests) > 0) ? 'requests-breathing-red' : '' ?>" id="requestsPillBadge">
        <span>Requests</span>
        <span class="req-count-badge-red" id="requestCountBadge"><?= count($pendingRequests) ?></span>
      </div>
    </div>

    <!-- Requests Cards Stack -->
    <div class="requests-queue-container" id="requestsList">
      <?php if (empty($pendingRequests)): ?>
        <div style="text-align:center; padding:36px 20px; background:rgba(255,255,255,0.03); border:1px dashed rgba(255,255,255,0.1); border-radius:16px;">
          <svg xmlns="http://www.w3.org/2000/svg" width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="var(--pk-text-muted)" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom:10px;"><path d="M20 6 9 17l-5-5"/></svg>
          <p style="font-size:14px; font-weight:700; color:var(--pk-text-primary); margin:0 0 4px;">You're all caught up</p>
          <p style="font-size:12.5px; color:var(--pk-text-muted); margin:0;">New reservations will appear here as players book your courts.</p>
        </div>
      <?php endif; ?>
      <?php foreach ($pendingRequests as $req): ?>
        <div class="request-card-v2" id="req_card_<?= htmlspecialchars($req['id']) ?>">
          <div class="req-card-top-row">
            <span class="req-player-name"><?= htmlspecialchars($req['name']) ?></span>
            <span class="req-payment-method-badge"><?= htmlspecialchars($req['badge'] ?? 'GCASH') ?></span>
          </div>

          <div class="req-details-col">
            <div class="req-court-title"><?= htmlspecialchars($req['court_name']) ?></div>
            <div class="req-schedule-subtitle"><?= htmlspecialchars($req['schedule']) ?></div>
          </div>

          <div class="req-price-cyan"><?= htmlspecialchars($req['fee']) ?></div>

          <div class="req-actions-row">
            <button type="button" class="btn-req-accept-v2" onclick="acceptBooking('<?= htmlspecialchars($req['id']) ?>', '<?= htmlspecialchars(addslashes($req['name'])) ?>')">
              <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
              <span>Accept</span>
            </button>
            <button type="button" class="btn-req-decline-v2" onclick="openDeclineModal('<?= htmlspecialchars($req['id']) ?>', '<?= htmlspecialchars(addslashes($req['name'])) ?>')">
              <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
              <span>Decline</span>
            </button>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

</div>
