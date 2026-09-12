<?php if ($activeTab === 'explore'): ?>
  <div class="openplay-container">
    <!-- Header -->
    <div style="margin-bottom: 24px;">
      <h1 style="font-family: 'Montserrat', var(--font-heading), sans-serif; font-size: 26px; font-weight: 800; color: #FFFFFF; margin: 0 0 6px;">Open Play</h1>
      <p style="font-size: 13px; color: #94A3B8; margin: 0;">Connect, compete, and play without the hassle.</p>
    </div>

    <!-- Filter Pills Bar -->
    <div class="openplay-filter-bar">
      <button type="button" class="openplay-filter-pill active" id="pillLevelAll" onclick="filterMatchesLevel('All')">
        All
      </button>
      <button type="button" class="openplay-filter-pill" id="pillLevelBeginner" onclick="filterMatchesLevel('Beginner')">
        Beginner
      </button>
      <button type="button" class="openplay-filter-pill" id="pillLevelIntermediate" onclick="filterMatchesLevel('Intermediate')">
        Intermediate
      </button>
      <button type="button" class="openplay-filter-pill" id="pillLevelAdvanced" onclick="filterMatchesLevel('Advanced')">
        Advanced
      </button>
    </div>

    <!-- Matches Loading Skeletons -->
    <div class="openplay-matches-grid" id="matchesSkeletonGrid" style="display:none;">
      <?php for ($msk = 0; $msk < 4; $msk++): ?>
        <div style="background: rgba(17, 35, 61, 0.6); border: 1px solid rgba(255,255,255,0.08); border-radius: 20px; padding: 20px; display: flex; flex-direction: column; gap: 14px;">
          <div style="display: flex; justify-content: space-between; align-items: center;">
            <div class="pk-skeleton" style="height: 22px; width: 55%; border-radius: 6px;"></div>
            <div class="pk-skeleton" style="height: 24px; width: 25%; border-radius: 9999px;"></div>
          </div>
          <div style="display: flex; flex-direction: column; gap: 8px;">
            <div class="pk-skeleton" style="height: 14px; width: 45%; border-radius: 4px;"></div>
            <div class="pk-skeleton" style="height: 14px; width: 60%; border-radius: 4px;"></div>
            <div class="pk-skeleton" style="height: 14px; width: 35%; border-radius: 4px;"></div>
          </div>
          <div style="margin-top: auto; padding-top: 10px; display: flex; justify-content: space-between; align-items: center;">
            <div class="pk-skeleton" style="height: 28px; width: 30%; border-radius: 6px;"></div>
            <div class="pk-skeleton" style="height: 38px; width: 35%; border-radius: 12px;"></div>
          </div>
        </div>
      <?php endfor; ?>
    </div>

    <!-- Matches Grid (2 columns on desktop) -->
    <div class="openplay-matches-grid" id="matchesGrid">
      <?php
        $matches = $db->getMatches();
        foreach ($matches as $m):
          $current = intval($m['current_players'] ?? 0);
          $max = intval($m['max_players'] ?? 4);
          $isFull = $current >= $max;
          $spotsLeft = max(0, $max - $current);
          $joined = !empty($m['is_joined']);
          $facilityName = !empty($m['facility_name']) ? trim((string)$m['facility_name']) : (!empty($m['title']) ? $m['title'] : 'Pickleball Facility');
          $rawCourt = !empty($m['court_name']) ? $m['court_name'] : (!empty($m['type']) && str_starts_with($m['type'], 'Court') ? $m['type'] : 'Court 1');
          $courtNameDisplay = \Picklers\Core\Database::normalizeCourtName($rawCourt);
          $searchData = $facilityName . ' ' . $courtNameDisplay . ' ' . ($m['title'] ?? '') . ' ' . ($m['type'] ?? '');
      ?>
        <div class="openplay-card match-card-item" data-id="<?php echo $m['id']; ?>" data-level="<?php echo htmlspecialchars($m['level']); ?>" data-facility="<?php echo htmlspecialchars($searchData); ?>" data-location="<?php echo htmlspecialchars($m['location']); ?>">
          <div>
            <!-- Card Header: Title + Level Badge -->
            <div class="openplay-card-header">
              <h3 class="openplay-card-title"><?php echo htmlspecialchars($facilityName); ?></h3>
              <span class="openplay-level-badge"><?php echo htmlspecialchars($m['level']); ?></span>
            </div>

            <!-- Details List -->
            <div class="openplay-details-list" style="margin-top: 14px;">
              <div class="openplay-detail-item facility" style="color: #FFFFFF; font-weight: 800; font-size: 14px; margin-bottom: 4px; display: flex; align-items: center; gap: 6px;">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink: 0;"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                <span><?php echo htmlspecialchars($courtNameDisplay); ?></span>
              </div>
              <div class="openplay-detail-item loc">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                <span><?php echo htmlspecialchars($m['location']); ?></span>
              </div>
              <div class="openplay-detail-item date">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg>
                <span><?php echo htmlspecialchars($m['date']); ?></span>
              </div>
              <div class="openplay-detail-item time">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                <span><?php echo htmlspecialchars($m['time']); ?></span>
              </div>
              <div class="openplay-host-price-row">
                <div class="openplay-detail-item host">
                  <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                  <span>Host: <strong><?php echo htmlspecialchars($m['host'] ?? 'Picklers Organizer'); ?></strong></span>
                </div>
                <div class="openplay-price-wrap">
                  <span class="openplay-price-val">₱<?php echo number_format($m['price'] ?? 0, 0); ?></span>
                  <span class="openplay-price-label">your share</span>
                </div>
              </div>
            </div>
          </div>

          <!-- Card Footer -->
          <div class="openplay-card-footer">
            <!-- Spots Indicator -->
            <div class="openplay-spots-group">
              <div class="openplay-spots-circle">
                <?php echo $current; ?>/<?php echo $max; ?>
              </div>
              <div class="openplay-spots-info">
                <span class="openplay-spots-filled"><?php echo $current; ?> of <?php echo $max; ?> spots filled</span>
                <span class="openplay-spots-remain">
                  <?php if ($isFull): ?>
                    Session full
                  <?php else: ?>
                    <?php echo $spotsLeft; ?> <?php echo ($spotsLeft === 1) ? 'spot' : 'spots'; ?> left
                  <?php endif; ?>
                </span>
              </div>
            </div>

            <!-- Join Action Button -->
            <div class="openplay-actions-group">
              <?php if ($joined): ?>
                <button type="button" class="btn-openplay-join" id="btnJoinMatch_<?php echo $m['id']; ?>" style="background: rgba(0, 217, 139, 0.15); color: #00D98B; border: 1px solid rgba(0, 217, 139, 0.3);" disabled>
                  Joined
                </button>
              <?php elseif ($isFull): ?>
                <button type="button" class="btn-openplay-full" id="btnJoinMatch_<?php echo $m['id']; ?>" disabled>
                  Full
                </button>
              <?php else: ?>
                <button type="button" class="btn-openplay-join" id="btnJoinMatch_<?php echo $m['id']; ?>" onclick="joinOpenPlay('<?php echo $m['id']; ?>', '<?php echo htmlspecialchars(addslashes($m['facility_name'] ?? '')); ?>', '<?php echo htmlspecialchars(addslashes($m['type'] ?? 'Open Play')); ?>', '<?php echo htmlspecialchars(addslashes($m['date'] ?? '')); ?>', '<?php echo htmlspecialchars(addslashes($m['time'] ?? '')); ?>', <?php echo (float)($m['price'] ?? 200); ?>, '<?php echo htmlspecialchars(addslashes($m['level'] ?? 'All Levels')); ?>', '<?php echo htmlspecialchars(addslashes($m['location'] ?? '')); ?>')">
                  Join
                </button>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- Empty State -->
    <div id="matchesEmptyState" style="display:none; text-align:center; padding:60px 20px; background:rgba(17,31,58,0.5); border:1px dashed rgba(255,255,255,0.1); border-radius:20px; margin-top:20px;">
      <div style="font-size:48px; margin-bottom:14px;">🏆</div>
      <h3 style="font-size:19px; font-weight:800; color:#FFFFFF; margin-bottom:6px;">No Matches Found</h3>
      <p style="font-size:13px; color:#94A3B8; max-width:380px; margin:0 auto 18px; line-height:1.5;">There are no open play sessions currently matching your selected level.</p>
      <button type="button" onclick="filterMatchesLevel('All')" style="background:rgba(0, 217, 139,0.15); border:1px solid rgba(0, 217, 139,0.3); color:#00D98B; border-radius:12px; padding:10px 22px; font-size:13px; font-weight:800; cursor:pointer;">
        Show All Matches
      </button>
    </div>
  </div>
<?php endif; ?>
