      <!-- ====================================================================
           TAB 1: PLAY (Discover Courts)
           ==================================================================== -->
      <?php if ($activeTab === 'play'): ?>
        <?php
          $facilities = $db->getFacilities();
          // No fictional venue name here: an empty $facilities means there is
          // genuinely nothing to fall back to yet, so this says so honestly
          // rather than inventing a specific-sounding business ("South Metro
          // Dinkers") that was never a real Picklers partner.
          $defaultFacility = $facilities[0] ?? [
            'id' => 0,
            'name' => 'No Facilities Available',
            'location' => '',
            'image' => ''
          ];
        ?>
        <div id="playTabContent">
          <div>

            <!-- Header with Search Swap & Filter Sheet Trigger -->
            <div class="play-header-actions">
              <div id="playVenuesTitleGroup">
                <h1 style="font-family: 'Montserrat', var(--font-heading), sans-serif; font-size: 26px; font-weight: 800; color: #FFFFFF; margin: 0 0 6px; display: flex; align-items: center; gap: 8px;">
                  <span>Discover Venues</span>
                </h1>
                <p style="font-size: 13px; color: #94A3B8; margin: 0;">Verified partner courts across the Philippines</p>
              </div>

              <!-- Search Swap Box & Filter Button -->
              <div class="play-search-toggle-box">
                <div class="search-swap-container">
                  <svg class="search-swap-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" x2="16.65" y1="21" y2="16.65"/></svg>
                  <input type="search" id="facilitySearchInput" name="q_venue_search" class="search-swap-input" placeholder="Search facilities by name, barangay, or area (Bantayan, Daro, Balugo)..." oninput="handleFacilitySearchInput(this)" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" data-lpignore="true" data-form-type="other" value="">
                  <button type="button" id="facilitySearchClearBtn" class="search-clear-btn" onclick="clearFacilitySearch()">✕</button>
                </div>
                <button type="button" class="btn-filter-trigger" id="playFilterSheetBtn" onclick="openModal('filterModal')">
                  <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                  <span>Filter</span>
                  <span class="filter-badge-counter" id="activeFilterCount" style="display:none;">0</span>
                </button>
              </div>
            </div>

            <!-- Loading Skeletons (Displayed during hydration or search filtering) -->
            <div class="facilities-main-grid" id="facilitiesSkeletonGrid" style="display:none;">
              <?php for ($sk = 0; $sk < 6; $sk++): ?>
                <div class="skeleton-facility-card" style="background: rgba(17, 35, 61, 0.6); border: 1px solid rgba(255,255,255,0.08); border-radius: 20px; overflow: hidden; display: flex; flex-direction: column;">
                  <div class="pk-skeleton" style="height: 200px; width: 100%;"></div>
                  <div style="padding: 16px; display: flex; flex-direction: column; gap: 10px; flex: 1;">
                    <div class="pk-skeleton" style="height: 22px; width: 65%; border-radius: 6px;"></div>
                    <div class="pk-skeleton" style="height: 14px; width: 45%; border-radius: 4px;"></div>
                    <div class="pk-skeleton" style="height: 14px; width: 80%; border-radius: 4px;"></div>
                    <div style="margin-top: auto; padding-top: 10px; display: flex; justify-content: space-between; align-items: center;">
                      <div class="pk-skeleton" style="height: 24px; width: 32%; border-radius: 6px;"></div>
                      <div class="pk-skeleton" style="height: 36px; width: 38%; border-radius: 12px;"></div>
                    </div>
                  </div>
                </div>
              <?php endfor; ?>
            </div>

            <!-- Facilities Main Grid (1 col mobile -> 2 col sm -> 3 col xl) -->
            <div class="facilities-main-grid" id="facilitiesGrid">
              <?php
                foreach ($facilities as $f):
                  $typeStr = $f['type'] ?? 'Indoor';
                  $typeNormalized = (stripos($typeStr, 'indoor') !== false) ? 'Indoor' : 'Outdoor';
                  $minPrice = (float)($f['min_price'] ?? $f['price_numeric'] ?? 140);
                  $maxPrice = (float)($f['max_price'] ?? $f['price_numeric'] ?? $minPrice);
                  $ratingNum = (float)($f['rating'] ?? 4.8);
              ?>
                <div class="app-facility-card facility-card-item" onclick="openFacilityDetail(<?php echo $f['id']; ?>)" style="cursor:pointer;" data-id="<?php echo $f['id']; ?>" data-name="<?php echo htmlspecialchars($f['name']); ?>" data-loc="<?php echo htmlspecialchars($f['location']); ?>" data-lat="<?php echo (float)($f['latitude'] ?? 9.3065); ?>" data-lng="<?php echo (float)($f['longitude'] ?? 123.3050); ?>" data-type="<?php echo $typeNormalized; ?>" data-price="<?php echo $minPrice; ?>" data-price-max="<?php echo $maxPrice; ?>" data-rating="<?php echo $ratingNum; ?>">
                  <?php
                    $imgSrc = !empty($f['image']) ? $f['image'] : 'assets/images/facilities/overhead_dumaguete.jpg';
                  ?>
                  <div class="card-thumb-wrap">
                    <img src="<?php echo htmlspecialchars($imgSrc); ?>" alt="<?php echo htmlspecialchars($f['name']); ?>" class="card-thumb-img" loading="lazy" onerror="this.onerror=null; this.src='assets/images/facilities/overhead_dumaguete.jpg';">
                    <button type="button" class="card-heart-btn" onclick="event.stopPropagation(); toggleFavoriteFacility(this, '<?php echo htmlspecialchars(addslashes($f['name'])); ?>')" title="Add to favorites">
                      <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></svg>
                    </button>
                  </div>
                  <div class="card-body">
                    <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:4px;">
                      <h3 class="card-title" style="margin:0;"><?php echo htmlspecialchars($f['name']); ?></h3>
                    </div>
                    <div class="card-location" style="margin-bottom: 4px;">
                      <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                      <span><?php echo htmlspecialchars($f['location']); ?></span>
                    </div>
                    <div style="margin-top: 2px; margin-bottom: 3px;">
                      <span style="color:#FFFFFF; font-weight:800; font-size:12.5px;"><?php echo (int)($f['courts_count'] ?? 0); ?> Courts Listed</span>
                    </div>
                    <div style="font-size:12px; color:#94A3B8; margin-bottom: 3px;">
                      <span class="facility-transit-text"><?php echo htmlspecialchars($f['transit'] ?? '🛵 5 min · 🚗 10 min'); ?></span>
                    </div>
                    <div style="font-size:12px; color:rgba(255,255,255,0.7); display:flex; align-items:center; gap:8px; margin-bottom: 6px; flex-wrap:wrap;">
                      <span style="color:#F59E0B; font-weight:800;">★ <?php echo number_format($ratingNum, 1); ?></span>
                      <span style="color:var(--pk-text-muted, #94A3B8);">(<?php echo $f['reviews'] ?? 100; ?> reviews)</span>
                    </div>
                    <div class="card-meta-row" style="margin-top:auto; gap: 8px;">
                      <div>
                        <div style="font-size:10px; color:rgba(255,255,255,0.45); text-transform:uppercase; font-weight:700;"><?php echo ($minPrice < $maxPrice) ? 'Rates' : 'Rate'; ?></div>
                        <div class="card-price-cyan">
                          <?php if ($minPrice < $maxPrice): ?>
                            ₱<?php echo number_format($minPrice, 0); ?> - ₱<?php echo number_format($maxPrice, 0); ?><span style="font-size:12px; color:rgba(255,255,255,0.5);">/hr</span>
                          <?php else: ?>
                            ₱<?php echo number_format($minPrice, 0); ?><span style="font-size:12px; color:rgba(255,255,255,0.5);">/hr</span>
                          <?php endif; ?>
                        </div>
                      </div>
                      <div style="display:flex; align-items:center; gap:6px;">
                        <button type="button" class="btn-view-courts" onclick="event.stopPropagation(); openFacilityDetail(<?php echo $f['id']; ?>)">
                          <span>View Courts</span>
                          <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
                        </button>
                      </div>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>

            <!-- Zero-Results Empty State -->
            <div id="facilitiesEmptyState" style="display:none; text-align:center; padding:60px 20px; background: rgba(17,31,58,0.5); border: 1px dashed rgba(255,255,255,0.1); border-radius: 20px; margin-bottom: 30px;">
              <div style="font-size:48px; margin-bottom:14px;">🏓</div>
              <h3 style="font-size:19px; font-weight:800; color:#FFFFFF; margin-bottom:6px;">No Facilities Found</h3>
              <p style="font-size:13px; color:#94A3B8; max-width:380px; margin:0 auto 20px; line-height:1.5;">We couldn't find any pickleball courts matching your search or filters. Try adjusting your keywords or clearing filters.</p>
              <button type="button" onclick="resetAllPlayFilters()" style="background: rgba(0, 217, 139,0.15); border: 1px solid rgba(0, 217, 139,0.3); color: #00D98B; border-radius: 12px; padding: 10px 22px; font-size: 13px; font-weight: 800; cursor: pointer; transition: all 0.2s;">
                Reset All Filters
              </button>
            </div>
          </div>
        </div>

        <!-- ====================================================================
             Inline Facility Detail View (§8 Flowchart)
             ==================================================================== -->
        <div id="facilityDetailView" style="display:none; animation: modalSlideUp 0.3s cubic-bezier(0.16, 1, 0.3, 1) forwards;">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:12px;">
            <button type="button" onclick="closeFacilityDetail()" style="display:inline-flex; align-items:center; gap:8px; background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.1); padding:9px 18px; border-radius:12px; color:#FFFFFF; font-size:13px; font-weight:700; cursor:pointer; transition:all 0.2s;">
              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
              <span>Back to Facilities</span>
            </button>
            <div id="facilityDetailActionBtns" style="display:flex; gap:10px;">
              <!-- Injected follow / share buttons -->
            </div>
          </div>

          <div id="facilityDetailHeaderWrap">
            <!-- Dynamically populated via openFacilityDetail(id) -->
          </div>

          <div style="margin-top:28px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; gap:16px;">
              <div>
                <h3 style="font-size:22px; font-weight:900; color:#FFFFFF; margin:0 0 4px; letter-spacing:0.5px; text-transform:uppercase;">
                  COURTS <span id="facilityCourtsCount" style="color:#94A3B8; font-size:18px; font-weight:600;">(3)</span>
                </h3>
                <p style="font-size:13px; color:#94A3B8; margin:0;">Select a court to book</p>
              </div>

              <!-- Quick Book Gradient Button -->
              <div>
                <button type="button" class="btn-quick-book-gradient" onclick="triggerQuickBookFirstAvailable()">
                  <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg>
                  <span>Quick Book</span>
                </button>
              </div>
            </div>
            <div id="facilityCourtsList" class="facility-courts-grid">
              <!-- Court cards injected dynamically -->
            </div>
          </div>
        </div>

        <!-- PAYMENT REVIEW VIEW (Modern 2-Column Overhaul) -->
        <div id="paymentReviewView" style="display:none;" class="payment-view-container">
          <!-- Top Bar -->
          <div class="payment-top-bar">
            <button type="button" class="payment-back-glass-btn" onclick="backToCourtsView()">
              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
              <span>Back to Courts</span>
            </button>
          </div>

          <div class="payment-header-title-wrap">
            <h1 class="payment-page-heading">Review &amp; Secure Checkout</h1>
            <p class="payment-page-subheading">Complete your booking for instant court reservation and verified digital gate pass.</p>
          </div>

          <div class="payment-grid-layout">
            <!-- LEFT COLUMN: Payment Methods, Pass Recipient, Guarantees -->
            <div class="payment-main-col">
              <!-- Payment Method Selection Box -->
              <div class="payment-section-box">
                <div class="payment-box-header">
                  <div class="payment-section-tag">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg>
                    <span>SELECT PAYMENT METHOD</span>
                  </div>
                </div>

                <!-- Option 1: GCash (RECOMMENDED) -->
                <div class="payment-method-card active" id="payMethodGCash" onclick="selectPaymentChannel('GCash')">
                  <div class="payment-method-left">
                    <div class="payment-channel-icon" style="background:#FFFFFF; padding:6px; display:flex; align-items:center; justify-content:center; box-sizing:border-box;">
                      <img src="assets/images/gcash.svg" alt="GCash" style="width:100%; height:100%; object-fit:contain; display:block;">
                    </div>
                    <div class="payment-channel-text">
                      <div class="payment-channel-title-row">
                        <span class="payment-channel-name">GCash</span>
                        <span class="payment-tag-rec">RECOMMENDED</span>
                      </div>
                      <div class="payment-channel-desc">Instant QR &amp; e-wallet payment with automated receipt</div>
                    </div>
                  </div>
                  <div class="payment-radio-circle" id="radioGCash">✓</div>
                </div>

                <!-- Option 2: Maya -->
                <div class="payment-method-card" id="payMethodMaya" onclick="selectPaymentChannel('Maya')">
                  <div class="payment-method-left">
                    <div class="payment-channel-icon" style="background:#000000; display:flex; align-items:center; justify-content:center; border:1px solid rgba(255,255,255,0.12); padding:10px 6px; box-sizing:border-box; box-shadow: 0 2px 8px rgba(0,0,0,0.25);">
                      <img src="assets/images/maya.svg" alt="Maya" style="width:100%; height:100%; object-fit:contain; display:block;">
                    </div>
                    <div class="payment-channel-text">
                      <div class="payment-channel-title-row">
                        <span class="payment-channel-name">Maya</span>
                      </div>
                      <div class="payment-channel-desc">Pay via Maya wallet, Visa, or Mastercard</div>
                    </div>
                  </div>
                  <div class="payment-radio-circle" id="radioMaya"></div>
                </div>

                <!-- Option 3: Pickle Credits -->
                <div class="payment-method-card" id="payMethodCredits" onclick="selectPaymentChannel('Pickle Credits')">
                  <div class="payment-method-left">
                    <div class="payment-channel-icon credits-icon">
                      <img src="assets/images/pickle-credits.svg" alt="Pickle Credits" style="width:24px; height:24px; object-fit:contain; display:block;">
                    </div>
                    <div class="payment-channel-text">
                      <div class="payment-channel-title-row">
                        <span class="payment-channel-name">Pickle Credits</span>
                      </div>
                      <div class="payment-channel-desc">Available Balance: <strong style="color:#00D98B;">₱<?php echo number_format($currentUser['wallet_balance'] ?? 0); ?></strong> • Zero fees</div>
                    </div>
                  </div>
                  <div class="payment-radio-circle" id="radioCredits"></div>
                </div>

                <!-- Option 4: Cash on Site -->
                <div class="payment-method-card" id="payMethodCash" onclick="selectPaymentChannel('Cash on Site')">
                  <div class="payment-method-left">
                    <div class="payment-channel-icon cash-icon">
                      <img src="assets/images/cash.svg" alt="Cash on Site" style="width:24px; height:24px; object-fit:contain; display:block;">
                    </div>
                    <div class="payment-channel-text">
                      <div class="payment-channel-title-row">
                        <span class="payment-channel-name">Cash on Site</span>
                      </div>
                      <div class="payment-channel-desc">Pay at the facility front desk before match start</div>
                    </div>
                  </div>
                  <div class="payment-radio-circle" id="radioCash"></div>
                </div>
              </div>

            </div>

            <!-- RIGHT COLUMN: Sticky Invoice Summary Card -->
            <div class="payment-side-col">
              <div class="payment-invoice-card">
                <!-- Facility Image Header Banner -->
                <div class="invoice-cover-wrap" id="payFacilityCoverWrap" style="background-image: url('<?php echo htmlspecialchars($defaultFacility['image']); ?>');">
                  <div class="invoice-cover-overlay"></div>
                  <div class="invoice-cover-content">
                    <div class="invoice-cover-badge">VERIFIED VENUE</div>
                    <h4 class="invoice-cover-title" id="payFacilityCoverName"><?php echo htmlspecialchars($defaultFacility['name']); ?></h4>
                    <div class="invoice-cover-loc" id="payFacilityCoverLoc">
                      <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                      <span><?php echo htmlspecialchars($defaultFacility['location'] ?? 'Metro Manila, PH'); ?></span>
                    </div>
                  </div>
                </div>

                <!-- Invoice Body -->
                <div class="invoice-body">
                  <!-- Hidden payFacilityName for JS compatibility -->
                  <span id="payFacilityName" style="display:none;"><?php echo htmlspecialchars($defaultFacility['name']); ?></span>

                  <!-- Court Name & Header -->
                  <div class="invoice-court-header">
                    <div>
                      <div class="invoice-meta-sub">RESERVED COURT</div>
                      <h3 class="invoice-court-name" id="payCourtName">Court 2</h3>
                    </div>
                    <span id="payCourtBadge" style="display:none;"></span>
                  </div>

                  <!-- 4-Tile Detail Matrix -->
                  <div class="invoice-matrix-grid">
                    <div class="invoice-matrix-cell">
                      <div class="matrix-label">
                        <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg>
                        <span>DATE</span>
                      </div>
                      <div class="matrix-val" id="payDateVal">Today</div>
                    </div>

                    <div class="invoice-matrix-cell">
                      <div class="matrix-label">
                        <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        <span>TIME SLOT</span>
                      </div>
                      <div class="matrix-val" id="payTimeVal">8:00 AM – 10:00 AM</div>
                    </div>

                    <div class="invoice-matrix-cell">
                      <div class="matrix-label">
                        <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 14 10"/></svg>
                        <span>DURATION</span>
                      </div>
                      <div class="matrix-val" id="payDurationVal">2 hours</div>
                    </div>

                    <div class="invoice-matrix-cell" style="display:none;">
                      <div class="matrix-label">
                        <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m21 16-4 4-4-4"/><path d="M17 20V4"/><path d="m3 8 4-4 4 4"/><path d="M7 4v16"/></svg>
                        <span>SURFACE</span>
                      </div>
                      <div class="matrix-val" id="paySurfaceVal">Hard Court</div>
                    </div>
                  </div>

                  <div class="invoice-divider"></div>

                  <!-- Cost Line Items -->
                  <div class="invoice-line-items">
                    <div class="invoice-line-row">
                      <span id="payFeeLabel">Court fee (2h × ₱180)</span>
                      <span class="invoice-line-num" id="payFeeVal">₱360</span>
                    </div>
                    <div class="invoice-line-row">
                      <span>Platform &amp; Service Fee (10%)</span>
                      <span class="invoice-line-num" id="payServiceVal">₱36</span>
                    </div>
                    <div class="invoice-line-row">
                      <span>Court Lighting &amp; Amenities</span>
                      <span class="invoice-line-free">FREE</span>
                    </div>
                    <div class="invoice-line-row" id="voucherDiscountRow" style="display:none; color:#00D98B;">
                      <span style="display:flex; align-items:center; gap:5px;">
                        <span>🏷️</span>
                        <span id="voucherDiscountLabel">Promo Voucher</span>
                      </span>
                      <span class="invoice-line-num" id="payDiscountVal" style="color:#00D98B;">-₱0</span>
                    </div>
                  </div>

                  <!-- Voucher Promo Code Input Bar -->
                  <div class="invoice-voucher-bar">
                    <input type="text" id="voucherCodeInput" class="invoice-voucher-input" placeholder="Promo code (e.g. WELCOME10)" autocomplete="off">
                    <button type="button" class="invoice-voucher-btn" onclick="applyVoucherCode()">Apply</button>
                  </div>
                  <div id="voucherMsg" style="font-size:11px; margin-top:5px; display:none;"></div>

                  <div class="invoice-divider"></div>

                  <!-- Total Row -->
                  <div class="invoice-total-row">
                    <div>
                      <div class="invoice-total-label">TOTAL AMOUNT DUE</div>
                      <div class="invoice-tax-note">Includes all taxes &amp; court fees</div>
                    </div>
                    <div class="invoice-total-amount" id="payTotalVal">₱396</div>
                  </div>

                  <!-- Hidden Form Inputs for Submission -->
                  <input type="hidden" id="finalFacilityId" value="<?php echo $defaultFacility['id']; ?>">
                  <input type="hidden" id="finalCourtId" value="">
                  <input type="hidden" id="finalCourtName" value="Court 2">
                  <input type="hidden" id="finalDate" value="Today">
                  <input type="hidden" id="finalTime" value="8:00 AM – 10:00 AM">
                  <input type="hidden" id="finalDuration" value="2 hours">
                  <input type="hidden" id="finalPrice" value="396">
                  <input type="hidden" id="finalPaymentMethod" value="GCash">
                  <input type="hidden" id="appliedVoucherDiscount" value="0">
                  <input type="hidden" id="finalMatchId" value="">

                  <button type="button" class="btn-checkout-primary" id="btnConfirmFinalPay" onclick="executeFinalBooking()">
                    <div class="btn-checkout-content">
                      <span>Pay <span id="btnPayTotalText">₱396</span></span>
                      <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                    </div>
                  </button>

                  <!-- Trust Badges Strip -->
                  <div class="invoice-trust-strip">
                    <span>🔒 100% Secure Checkout</span>
                    <span>•</span>
                    <span>Official Venue Partner</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      <?php endif; ?>

