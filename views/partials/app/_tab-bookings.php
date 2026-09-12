<?php if ($activeTab === 'bookings'): ?>
  <?php
    $subTab = (string)($_GET['sub'] ?? 'upcoming');
    $validSubTabs = ['upcoming', 'completed', 'refunds', 'cancelled'];
    if (!in_array($subTab, $validSubTabs, true)) {
        $subTab = 'upcoming';
    }

    $allBookings = $db->getBookings($currentUser['id']);
    // A reservation the player still has coming up, whether or not the venue
    // has explicitly accepted it yet. 'confirmed' — what approve_booking
    // actually sets — matched nothing here before, so an owner accepting a
    // booking made it disappear from the player's own Bookings tab entirely.
    $upcomingBookings = array_values(array_filter($allBookings, fn($b) => in_array($b['status'] ?? '', ['upcoming', 'confirmed', 'pending'], true)));
    $completedBookings = array_values(array_filter($allBookings, fn($b) => ($b['status'] ?? '') === 'completed'));
    $cancelledBookings = array_values(array_filter($allBookings, fn($b) => in_array($b['status'] ?? '', ['cancelled', 'declined'], true)));

    $allTransactions = $db->getWalletTransactions($currentUser['id'] ?? '');
    $refundTransactions = array_values(array_filter($allTransactions, fn($t) => stripos($t['label'] ?? '', 'refund') !== false));

    if (!function_exists('getBookingTypeDetails')) {
        function getBookingTypeDetails($b) {
            $rawCourt = trim((string)($b['court_name'] ?? ''));
            $facility = trim((string)($b['facility_name'] ?? 'Pickleball Facility'));
            $bId = (string)($b['id'] ?? '');

            $isOP = (isset($b['type']) && $b['type'] === 'open_play')
                 || (isset($b['booking_type']) && $b['booking_type'] === 'open_play')
                 || (strpos($bId, 'PKL-OP-') === 0)
                 || (stripos($rawCourt, 'open play') !== false)
                 || (stripos($rawCourt, 'king of the court') !== false)
                 || (stripos($rawCourt, 'match') !== false);

            if ($isOP) {
                $typeKey = 'open_play';
                $typeLabel = 'Open Play';
                $icon = '🔥';

                if (empty($rawCourt) || strcasecmp($rawCourt, $facility) === 0) {
                    $sessionName = 'Open Play Session';
                } else {
                    $sessionName = preg_replace('/\s*[\(–-].*$/', '', $rawCourt);
                    if (empty(trim($sessionName))) {
                        $sessionName = 'Open Play Session';
                    }
                }

                if (stripos($sessionName, 'open play') !== false) {
                    $displayName = $sessionName;
                } else {
                    $displayName = 'Open Play - ' . $sessionName;
                }
            } else {
                $typeKey = 'book';
                $typeLabel = 'Book';
                $icon = '🏟️';

                if (preg_match('/Court\s*\d+/i', $rawCourt, $cm)) {
                    $cName = preg_replace('/^court\s*/i', 'Court ', $cm[0]);
                } else {
                    $cName = preg_replace('/\s*[\(–-].*$/', '', $rawCourt);
                    if (empty(trim($cName))) {
                        $cName = 'Court 1';
                    }
                }
                $displayName = 'Book - ' . $cName;
            }

            $pm = trim((string)($b['payment_method'] ?? 'Maya'));
            if (empty($pm)) {
                $pm = 'Maya';
            }
            if (stripos($pm, 'paid via') !== false) {
                $payText = $pm;
            } elseif (stripos($pm, 'venue') !== false) {
                $payText = 'Pay at Venue';
            } else {
                $payText = 'Paid via ' . $pm;
            }

            return [
                'is_op' => $isOP,
                'type_key' => $typeKey,
                'type_label' => $typeLabel,
                'icon' => $icon,
                'display_name' => $displayName,
                'facility_name' => $facility,
                'payment_label' => $payText
            ];
        }
    }

    // End of helper functions
  ?>
  <div>
    <!-- Header -->
    <div style="margin-bottom: 24px;">
      <h1 style="font-family: 'Montserrat', var(--font-heading), sans-serif; font-size: 26px; font-weight: 800; color: #FFFFFF; margin: 0 0 6px;">Bookings</h1>
      <p style="font-size: 13px; color: #94A3B8; margin: 0;">Manage your court passes, completed games, and cancellation refunds.</p>
    </div>

    <!-- Sub-Navigation Bar -->
    <div class="booking-subnav">
      <button type="button" id="bookingSubTabBtn_upcoming" class="booking-subnav-btn <?php echo $subTab === 'upcoming' ? 'active' : ''; ?>" onclick="switchBookingSubTab('upcoming')">
        <span>Upcoming</span>
        <span class="booking-badge-count"><?php echo count($upcomingBookings); ?></span>
      </button>
      <button type="button" id="bookingSubTabBtn_completed" class="booking-subnav-btn <?php echo $subTab === 'completed' ? 'active' : ''; ?>" onclick="switchBookingSubTab('completed')">
        <span>Completed</span>
        <span class="booking-badge-count"><?php echo count($completedBookings); ?></span>
      </button>
      <button type="button" id="bookingSubTabBtn_refunds" class="booking-subnav-btn <?php echo $subTab === 'refunds' ? 'active' : ''; ?>" onclick="switchBookingSubTab('refunds')">
        <span>Refunds</span>
        <span class="booking-badge-count"><?php echo count($refundTransactions); ?></span>
      </button>
      <button type="button" id="bookingSubTabBtn_cancelled" class="booking-subnav-btn <?php echo $subTab === 'cancelled' ? 'active' : ''; ?>" onclick="switchBookingSubTab('cancelled')">
        <span>Cancelled</span>
        <span class="booking-badge-count"><?php echo count($cancelledBookings); ?></span>
      </button>
    </div>

    <!-- Sub-Tab 1: Upcoming -->
    <div class="booking-subtab-panel" id="bookingPanel_upcoming" style="display: <?php echo $subTab === 'upcoming' ? 'block' : 'none'; ?>;">
      <?php if (empty($upcomingBookings)): ?>
        <div class="booking-empty-state">
          <svg class="booking-empty-icon" xmlns="http://www.w3.org/2000/svg" width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg>
          <div class="booking-empty-title">No Upcoming Bookings</div>
          <p class="booking-empty-desc">Bookings you reserve will appear here with your QR check-in passes.</p>
          <a href="app.php?tab=play" class="btn-view-courts" style="background:#00D98B; color:#FFFFFF; padding:10px 22px; font-weight:800; font-size:13px; text-decoration:none; margin-top:18px; border-radius:12px; display:inline-flex;">Browse Available Courts →</a>
        </div>
      <?php else: ?>
        <div style="display:flex; flex-direction:column; gap:16px;">
          <?php foreach ($upcomingBookings as $b): ?>
            <?php $det = getBookingTypeDetails($b); ?>
            <div class="booking-card-item" data-booking-type="<?php echo $det['type_key']; ?>">
              <div class="app-facility-card" style="padding:22px;">
                <div class="booking-card-header-top">
                  <div>
                    <h4 class="booking-card-title">
                      <?php echo htmlspecialchars($det['display_name']); ?>
                    </h4>
                    <div class="booking-card-passid">
                      #<?php echo htmlspecialchars($b['id']); ?>
                    </div>
                    <?php if (($b['status'] ?? '') === 'confirmed'): ?>
                      <span class="booking-confirm-pill booking-confirm-pill--confirmed">
                        <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                        Confirmed by venue
                      </span>
                    <?php else: ?>
                      <span class="booking-confirm-pill booking-confirm-pill--pending">
                        <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        Awaiting confirmation
                      </span>
                    <?php endif; ?>
                  </div>
                  <div style="text-align:right; flex-shrink:0;">
                    <div class="card-price-cyan">₱<?php echo number_format($b['price'], 2); ?></div>
                    <div class="booking-card-payment">
                      <?php echo htmlspecialchars($det['payment_label']); ?>
                    </div>
                  </div>
                </div>
                <div class="booking-card-location">
                  <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                  <span><?php echo htmlspecialchars($det['facility_name']); ?></span>
                </div>
                <div class="booking-card-schedule">
                  <span><?php echo htmlspecialchars($b['date']); ?></span>
                  <span class="booking-card-sep">•</span>
                  <span><?php echo htmlspecialchars($b['time']); ?></span>
                </div>
                <div class="booking-card-footer">
                  <button type="button" class="btn-view-courts" onclick="openQrPassModal('<?php echo $b['id']; ?>', '<?php echo htmlspecialchars(addslashes($det['facility_name'])); ?>', '<?php echo htmlspecialchars(addslashes($det['display_name'])); ?>', '<?php echo $b['date']; ?>', '<?php echo $b['time']; ?>', '<?php echo $det['type_key']; ?>')">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect width="5" height="5" x="3" y="3" rx="1"/><rect width="5" height="5" x="16" y="3" rx="1"/><rect width="5" height="5" x="3" y="16" rx="1"/><path d="M21 16h-3a2 2 0 0 0-2 2v3"/><path d="M21 21v.01"/><path d="M12 7v3a2 2 0 0 1-2 2H7"/><path d="M3 12h.01"/><path d="M12 3h.01"/><path d="M12 16v.01"/><path d="M16 12h1"/><path d="M21 12v.01"/><path d="M12 21v-1"/></svg>
                    <span>View Pass</span>
                  </button>
                  <button type="button" class="btn-danger" onclick="promptCancelBooking('<?php echo $b['id']; ?>', '<?php echo htmlspecialchars(addslashes($b['date'])); ?>')">
                    Cancel Booking
                  </button>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- Sub-Tab 2: Completed -->
    <div class="booking-subtab-panel" id="bookingPanel_completed" style="display: <?php echo $subTab === 'completed' ? 'block' : 'none'; ?>;">
      <?php if (empty($completedBookings)): ?>
        <div class="booking-empty-state">
          <svg class="booking-empty-icon" xmlns="http://www.w3.org/2000/svg" width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>
          <div class="booking-empty-title">No Completed Bookings</div>
          <p class="booking-empty-desc">Past court reservations and finished game sessions will appear here.</p>
        </div>
      <?php else: ?>
        <div style="display:flex; flex-direction:column; gap:16px;">
          <?php foreach ($completedBookings as $b): ?>
            <?php $det = getBookingTypeDetails($b); ?>
            <div class="booking-card-item" data-booking-type="<?php echo $det['type_key']; ?>">
              <div class="app-facility-card" style="padding:22px;">
                <div class="booking-card-header-top">
                  <div>
                    <h4 class="booking-card-title">
                      <?php echo htmlspecialchars($det['display_name']); ?>
                    </h4>
                    <div class="booking-card-passid">
                      #<?php echo htmlspecialchars($b['id']); ?>
                    </div>
                  </div>
                  <div style="text-align:right; flex-shrink:0;">
                    <div class="card-price-cyan card-price-cyan--muted">₱<?php echo number_format($b['price'], 2); ?></div>
                    <div class="booking-card-payment booking-card-payment--compact">
                      <?php echo htmlspecialchars($det['payment_label']); ?>
                    </div>
                    <div style="margin-top:4px;">
                      <span class="booking-status-label booking-status-label--completed">
                        ● COMPLETED
                      </span>
                    </div>
                  </div>
                </div>
                <div class="booking-card-location">
                  <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                  <span><?php echo htmlspecialchars($det['facility_name']); ?></span>
                </div>
                <div class="booking-card-schedule" style="margin-bottom:0;">
                  <span><?php echo htmlspecialchars($b['date']); ?></span>
                  <span class="booking-card-sep">•</span>
                  <span><?php echo htmlspecialchars($b['time']); ?></span>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- Sub-Tab 3: Refunds -->
    <div class="booking-subtab-panel" id="bookingPanel_refunds" style="display: <?php echo $subTab === 'refunds' ? 'block' : 'none'; ?>;">
      <?php if (empty($refundTransactions)): ?>
        <div class="booking-empty-state">
          <svg class="booking-empty-icon" xmlns="http://www.w3.org/2000/svg" width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
          <div class="booking-empty-title">No Refund Records</div>
          <p class="booking-empty-desc">Refunds from cancelled court bookings credited to your wallet will be listed here.</p>
        </div>
      <?php else: ?>
        <div style="display:flex; flex-direction:column; gap:12px;">
          <?php foreach ($refundTransactions as $t): ?>
            <div class="app-facility-card" style="padding:16px 20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
              <div style="display:flex; align-items:center; gap:14px;">
                <div class="refund-row-icon">
                  ↺
                </div>
                <div>
                  <div class="refund-row-label"><?php echo htmlspecialchars($t['label']); ?></div>
                  <div class="refund-row-date"><?php echo htmlspecialchars($t['date']); ?></div>
                </div>
              </div>
              <div class="refund-row-amount">
                +₱<?php echo number_format($t['amount'], 2); ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- Sub-Tab 4: Cancelled -->
    <div class="booking-subtab-panel" id="bookingPanel_cancelled" style="display: <?php echo $subTab === 'cancelled' ? 'block' : 'none'; ?>;">
      <?php if (empty($cancelledBookings)): ?>
        <div class="booking-empty-state">
          <svg class="booking-empty-icon" xmlns="http://www.w3.org/2000/svg" width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" x2="9" y1="9" y2="15"/><line x1="9" x2="15" y1="9" y2="15"/></svg>
          <div class="booking-empty-title">No Cancelled Bookings</div>
          <p class="booking-empty-desc">Any cancelled reservations will appear here with refund receipts.</p>
        </div>
      <?php else: ?>
        <div style="display:flex; flex-direction:column; gap:16px;">
          <?php foreach ($cancelledBookings as $b): ?>
            <?php $det = getBookingTypeDetails($b); ?>
            <div class="booking-card-item" data-booking-type="<?php echo $det['type_key']; ?>">
              <div class="app-facility-card" style="padding:22px; opacity:0.85;">
                <div class="booking-card-header-top">
                  <div>
                    <h4 class="booking-card-title">
                      <?php echo htmlspecialchars($det['display_name']); ?>
                    </h4>
                    <div class="booking-card-passid">
                      #<?php echo htmlspecialchars($b['id']); ?>
                    </div>
                  </div>
                  <div style="text-align:right; flex-shrink:0;">
                    <div class="card-price-cyan card-price-cyan--danger">₱<?php echo number_format($b['price'], 2); ?></div>
                    <div class="booking-card-payment booking-card-payment--compact">
                      <?php echo htmlspecialchars($det['payment_label']); ?>
                    </div>
                    <div style="margin-top:4px;">
                      <span class="booking-status-label booking-status-label--cancelled">
                        ● CANCELLED
                      </span>
                    </div>
                  </div>
                </div>
                <div class="booking-card-location">
                  <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                  <span><?php echo htmlspecialchars($det['facility_name']); ?></span>
                </div>
                <div class="booking-card-schedule" style="margin-bottom:0;">
                  <span><?php echo htmlspecialchars($b['date']); ?></span>
                  <span class="booking-card-sep">•</span>
                  <span><?php echo htmlspecialchars($b['time']); ?></span>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>
