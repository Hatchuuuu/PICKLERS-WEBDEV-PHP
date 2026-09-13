<?php
declare(strict_types=1);
/**
 * Court Owner Portal - My Courts & Open Play View
 * @var array $courts
 * @var array $openPlayMatches
 */
?>
<!-- Top Header -->
<div class="owner-topbar">
  <div class="owner-topbar-title-wrap">
    <h1 class="owner-page-title">My Courts &amp; Open Play</h1>
    <p class="owner-page-subtitle">Manage facility courts and host Open Play sessions.</p>
  </div>
  <button type="button" class="btn-list-court-emerald" onclick="openModal('addCourtModal')">
    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
    <span>List Court</span>
  </button>
</div>

<!-- Search / Filter Input Box -->
<div class="court-filter-wrap">
  <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--pk-text-muted, #94A3B8)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
  <input type="text" id="courtSearchInput" class="court-filter-input" placeholder="Filter by court name..." onkeyup="filterCourtsList(this.value)">
</div>

<!-- Physical Court Cards Stack -->
<div class="courts-cards-stack" id="courtsListContainer">
  <?php if (empty($courts)): ?>
  <div style="text-align:center; padding:48px 20px; color:var(--pk-text-muted, #94A3B8);">
    <p style="font-size:14px; font-weight:600;">No courts added yet.</p>
    <p style="font-size:13px; margin-top:4px;">Click "List Court" above to add your first court.</p>
  </div>
  <?php else: ?>
  <?php foreach ($courts as $idx => $c):
    $isActive = ($c['active'] ?? true);
    $hasOpenPlay = !empty($c['has_open_play']);
    $statusText = $c['status'] ?? (!$isActive ? 'UNAVAILABLE' : 'AVAILABLE');
    $statusNormUpper = strtoupper(trim((string)$statusText));

    if ($hasOpenPlay && ($statusNormUpper === 'AVAILABLE' || $statusNormUpper === 'HOSTED OPEN PLAY' || $statusNormUpper === 'OCCUPIED')) {
      $statusText = 'HOSTED OPEN PLAY';
      $statusClass = 'badge-amber-glow';
    } elseif ($statusNormUpper === 'OCCUPIED') {
      $statusClass = 'badge-amber-glow';
      $statusText = 'OCCUPIED';
    } elseif ($statusNormUpper === 'UNAVAILABLE' || !$isActive) {
      $statusClass = 'badge-red-glow';
      $statusText = 'UNAVAILABLE';
    } else {
      $statusClass = 'badge-emerald-glow';
      $statusText = 'AVAILABLE';
    }
    $isOccupied = ($statusText === 'OCCUPIED');
    $rateRaw = $c['rate'] ?? 350;
    $rateNum = is_numeric($rateRaw) ? (float)$rateRaw : (float)preg_replace('/[^0-9.]/', '', (string)$rateRaw);
    if ($rateNum <= 0) $rateNum = 350;
    $surfaceStr = $c['surface'] ?? 'Premium Hard';
    $surfaceVal = $surfaceStr;
    $player = $c['player'] ?? null;
    $playerTime = $c['player_time'] ?? null;
  ?>
    <div class="court-item-card <?php echo (!$isActive || $statusText === 'UNAVAILABLE') ? 'court-card-disabled' : ''; ?>" data-court-name="<?php echo htmlspecialchars(strtolower((string)$c['name'])); ?>">
      <!-- Header Row -->
      <div class="court-item-header">
        <div>
          <div class="court-item-title"><?php echo htmlspecialchars((string)$c['name']); ?></div>
        </div>
        <span class="court-status-pill <?php echo $statusClass; ?>" <?php echo $hasOpenPlay ? 'style="background: rgba(255, 184, 0, 0.15); border: 1px solid rgba(255, 184, 0, 0.4); color: #FFB800;"' : ''; ?>><?php echo $statusText; ?></span>
      </div>

      <!-- Pricing & Player Row -->
      <div class="court-item-details-row">
        <div class="court-item-price">₱<?php echo number_format($rateNum); ?>/hr</div>
        <?php if ($isOccupied && !empty($player)): ?>
          <div class="court-item-player">
            <div>
              <div style="font-size:13px; font-weight:800; text-align:right;"><?php echo htmlspecialchars((string)$player); ?></div>
              <?php if (!empty($playerTime)): ?>
                <div style="font-size:11px; font-weight:700; color:#EF4444; text-align:right;"><?php echo htmlspecialchars((string)$playerTime); ?></div>
              <?php endif; ?>
            </div>
            <div class="player-avatar-circle">
              <span><?php echo htmlspecialchars(strtoupper(substr(trim((string)$player), 0, 1))); ?></span>
            </div>
          </div>
        <?php elseif (!empty($c['next_booking'])): ?>
          <div class="court-item-player">
            <div>
              <div style="font-size:11.5px; font-weight:800; color:#00D98B; text-align:right;">Booked <?php echo htmlspecialchars((string)$c['next_booking']['time']); ?></div>
              <div style="font-size:11px; font-weight:600; color:#CBD5E1; text-align:right;"><?php echo htmlspecialchars((string)$c['next_booking']['user_name']); ?></div>
            </div>
            <div class="player-avatar-circle">
              <span><?php echo htmlspecialchars(strtoupper(substr(trim((string)($c['next_booking']['user_name'] ?? 'B')), 0, 1))); ?></span>
            </div>
          </div>
        <?php endif; ?>
      </div>

      <!-- Row 1 Buttons: Edit, Disable & Delete -->
      <div class="court-action-row-1">
        <button type="button" class="btn-court-action btn-edit-dark" onclick="openEditCourtModal('<?php echo htmlspecialchars(addslashes((string)$c['id'])); ?>', '<?php echo htmlspecialchars(addslashes($c['name'])); ?>', '<?php echo htmlspecialchars(addslashes($surfaceVal)); ?>', '<?php echo (int)$rateNum; ?>', this, '<?php echo htmlspecialchars(implode(',', $c['attribute_slugs'] ?? [])); ?>')">Edit</button>

        <?php if ($isOccupied || $hasOpenPlay): ?>
          <button type="button" class="btn-court-action btn-disable-disabled" disabled title="Court is currently active/hosted and cannot be disabled">
            <span>Disable</span>
            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
          </button>
        <?php elseif ($isActive && $statusText !== 'UNAVAILABLE'): ?>
          <button type="button" class="btn-court-action btn-disable-red" onclick="toggleCourtActive('<?php echo htmlspecialchars(addslashes((string)$c['id'])); ?>', '<?php echo htmlspecialchars(addslashes($c['name'])); ?>', this)">
            <span>Disable</span>
            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
          </button>
        <?php else: ?>
          <button type="button" class="btn-court-action btn-enable-green" onclick="toggleCourtActive('<?php echo htmlspecialchars(addslashes((string)$c['id'])); ?>', '<?php echo htmlspecialchars(addslashes($c['name'])); ?>', this)">
            <span>Enable</span>
            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
          </button>
        <?php endif; ?>

        <?php if ($isOccupied || $hasOpenPlay): ?>
          <button type="button" class="btn-court-action btn-disable-disabled" disabled title="Court is currently active/hosted and cannot be deleted">
            <span>Delete</span>
            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
          </button>
        <?php else: ?>
          <button type="button" class="btn-court-action btn-delete-red" onclick="confirmDeleteCourt('<?php echo htmlspecialchars(addslashes((string)$c['id'])); ?>', '<?php echo htmlspecialchars(addslashes($c['name'])); ?>')" title="Permanently Delete Court">
            <span>Delete</span>
            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
          </button>
        <?php endif; ?>
      </div>

      <!-- Row 2 Button: Host Open Play or Cancel Open Play -->
      <?php if ($isOccupied): ?>
        <button type="button" class="btn-host-openplay-disabled" disabled>
          <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg>
          <span>Host Open Play</span>
        </button>
      <?php elseif ($hasOpenPlay): ?>
        <?php
          $opMatch = $c['open_play_match'] ?? [];
          $opId = (string)($opMatch['id'] ?? '');
          $opTitle = !empty($opMatch['title']) ? (string)$opMatch['title'] : ($c['name'] . ' Open Play');
        ?>
        <button type="button" class="btn-cancel-openplay-active" onclick="confirmCancelOpenPlay('<?php echo htmlspecialchars(addslashes($opId)); ?>', '<?php echo htmlspecialchars(addslashes($opTitle)); ?>', '<?php echo htmlspecialchars(addslashes($c['name'])); ?>')">
          <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          <span>Cancel Open Play</span>
        </button>
      <?php else: ?>
        <button type="button" class="btn-host-openplay-active" onclick="openHostOpenPlayForCourt('<?php echo htmlspecialchars(addslashes($c['name'])); ?>', '<?php echo htmlspecialchars(addslashes($surfaceVal)); ?>')">
          <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="#00D98B" stroke="none"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg>
          <span>Host Open Play</span>
        </button>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  <?php endif; ?>
</div>
