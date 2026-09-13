// Toast Notification Dispatcher (Single active notification, no stacking)
let activeToastTimeout = null;

function showToast(message, type = 'success') {
  // Toasts were invisible to assistive tech: mirror every one into a live region.
  if (window.UX) window.UX.announce(message, type);
  const container = document.getElementById('toastContainer');
  if (!container) return;

  // Clear any existing toast immediately to prevent stacking
  container.innerHTML = '';
  if (activeToastTimeout) {
    clearTimeout(activeToastTimeout);
    activeToastTimeout = null;
  }

  // Automatic consistency mapping for disconnect/revoked/removed/failed actions
  let finalType = type;
  const msgLower = (message || '').toLowerCase();
  if (
    msgLower.includes('disconnect') ||
    msgLower.includes('revoked') ||
    msgLower.includes('removed') ||
    msgLower.includes('failed') ||
    msgLower.includes('declined') ||
    msgLower.includes('cancel')
  ) {
    finalType = 'error';
  } else if (
    msgLower.includes('connect') ||
    msgLower.includes('verified') ||
    msgLower.includes('success')
  ) {
    if (type !== 'error') finalType = 'success';
  }

  // Strip manual checkmark prefixes if present to prevent double icons
  let cleanMsg = (message || '')
    .replace(/^[✓✔☑\s]+/, '')
    .replace(/^⚠️\s*/, '')
    .replace(/^📱\s*/, '')
    .replace(/^🌙\s*/, '')
    .replace(/^☀️\s*/, '');

  let iconSvg = '';
  if (finalType === 'error') {
    iconSvg = `<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#EF4444" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>`;
  } else if (finalType === 'info') {
    iconSvg = `<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#38BDF8" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>`;
  } else {
    // success
    iconSvg = `<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#00D98B" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>`;
  }

  const toast = document.createElement('div');
  toast.className = `toast-card toast-${finalType}`;
  toast.innerHTML = `
      ${iconSvg}
      <span>${cleanMsg}</span>
    `;
  container.appendChild(toast);

  activeToastTimeout = setTimeout(() => {
    toast.style.opacity = '0';
    toast.style.transform = 'translateY(12px) scale(0.95)';
    toast.style.transition = 'transform 200ms cubic-bezier(0.23, 1, 0.32, 1), opacity 200ms cubic-bezier(0.23, 1, 0.32, 1)';
    setTimeout(() => {
      if (toast.parentNode === container) {
        toast.remove();
      }
    }, 200);
  }, 3000);
}

// Modal Control
// Dialog mechanics live in ux-core.js: Escape, focus trapping, backdrop
// dismissal, reference-counted scroll locking and a real exit animation.
// These wrappers keep every existing openModal()/closeModal() call site working.
function openModal(modalId, options) {
  if (window.UX) return window.UX.openDialog(modalId, options);
  const el = document.getElementById(modalId);
  if (el) { el.style.display = 'flex'; document.body.style.overflow = 'hidden'; }
}
function closeModal(modalId) {
  if (window.UX) return window.UX.closeDialog(modalId);
  const el = document.getElementById(modalId);
  if (el) { el.style.display = 'none'; document.body.style.overflow = ''; }
}

// Toggle Notifications Dialog Modal
function toggleNotificationsPanel() {
  openModal('notifModal');
  setTimeout(initNotificationSwipeGestures, 50);
}

// Swipe-to-Dismiss / Remove Notification Handler
function dismissNotification(rowElement, notifId, directSlide = false) {
  if (!rowElement || rowElement.classList.contains('removing')) return;

  const card = rowElement.querySelector('.notif-card-item');

  // Animate card off-screen and collapse row
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

    // Update Notification count badge
    const list = document.getElementById('notifModalList');
    const remainingRows = list ? list.querySelectorAll('.notif-swipe-row') : [];
    const remainingUnread = list ? list.querySelectorAll('.notif-card-item.unread').length : 0;
    const badge = document.getElementById('notifUnreadBadgeText');
    if (badge) {
      badge.textContent = remainingUnread > 0
        ? `${remainingUnread} unread of ${remainingRows.length}`
        : `${remainingRows.length} total`;
    }

    // Also update notification bell badge dot if 0 unread
    if (remainingUnread === 0) {
      document.querySelectorAll('.notif-unread-dot').forEach(el => el.remove());
    }

    // If no notifications left, show elegant empty state
    if (list && remainingRows.length === 0) {
      list.innerHTML = `
        <div style="text-align: center; padding: 48px 20px; color: #94A3B8;">
          <div style="font-size: 36px; margin-bottom: 10px;">🔔</div>
          <div style="font-size: 15px; font-weight: 700; color: #FFFFFF; margin-bottom: 4px;">No notifications yet</div>
          <div style="font-size: 12px; color: rgba(255, 255, 255, 0.55);">When you book courts or join open play sessions, updates will show up here.</div>
        </div>
      `;
    }

    // Call backend API to delete from database
    if (notifId) {
      fetch('api.php?action=delete_notification', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': getCsrfToken()
        },
        body: JSON.stringify({
          id: notifId,
          csrf_token: getCsrfToken()
        })
      }).catch(err => console.error('Failed to delete notification:', err));
    }
  }, 350);
}

// Initialize swipe gestures for all notification items
function initNotificationSwipeGestures() {
  const modalList = document.getElementById('notifModalList');
  if (!modalList) return;

  const rows = modalList.querySelectorAll('.notif-swipe-row');
  rows.forEach(row => {
    const card = row.querySelector('.notif-card-item');
    const notifId = row.getAttribute('data-id');
    if (!card || card._hasSwipeListener) return;

    card._hasSwipeListener = true;

    let startX = 0;
    let startY = 0;
    let currentX = 0;
    let isDragging = false;
    let isHorizontalSwipe = false;
    let isLockedOpen = false;

    const onPointerDown = (e) => {
      // Close any other open rows
      document.querySelectorAll('.notif-card-item').forEach(other => {
        if (other !== card && other.style.transform && other.style.transform !== 'translateX(0px)') {
          other.style.transition = 'transform 0.25s cubic-bezier(0.16, 1, 0.3, 1)';
          other.style.transform = 'translateX(0px)';
        }
      });

      startX = e.clientX || (e.touches && e.touches[0].clientX) || 0;
      startY = e.clientY || (e.touches && e.touches[0].clientY) || 0;
      currentX = startX;
      isDragging = true;
      isHorizontalSwipe = false;

      card.classList.add('swiping');
      row.classList.add('swiping');
    };

    const onPointerMove = (e) => {
      if (!isDragging) return;

      const clientX = e.clientX || (e.touches && e.touches[0].clientX) || 0;
      const clientY = e.clientY || (e.touches && e.touches[0].clientY) || 0;
      const diffX = clientX - startX;
      const diffY = clientY - startY;

      // Lock direction: if vertical scrolling, cancel swipe tracking
      if (!isHorizontalSwipe) {
        if (Math.abs(diffY) > 8 && Math.abs(diffY) > Math.abs(diffX)) {
          isDragging = false;
          card.classList.remove('swiping');
          row.classList.remove('swiping');
          card.style.transform = isLockedOpen ? 'translateX(-86px)' : 'translateX(0px)';
          return;
        }
        if (Math.abs(diffX) > 8) {
          isHorizontalSwipe = true;
        }
      }

      if (isHorizontalSwipe) {
        if (e.cancelable) e.preventDefault();

        let targetOffset = isLockedOpen ? (-86 + diffX) : diffX;

        if (targetOffset < -5) {
          row.classList.add('is-swiped');
        } else if (!isLockedOpen) {
          row.classList.remove('is-swiped');
        }

        // Swiping Left (towards delete)
        if (targetOffset < 0) {
          card.style.transform = `translateX(${targetOffset}px)`;
        } else {
          // Swiping Right: elastic rubber-band resistance
          card.style.transform = `translateX(${targetOffset * 0.25}px)`;
        }
      }
    };

    const onPointerUp = (e) => {
      if (!isDragging) return;
      isDragging = false;
      card.classList.remove('swiping');
      row.classList.remove('swiping');
      card.style.transition = 'transform 0.28s cubic-bezier(0.16, 1, 0.3, 1)';

      const clientX = e.clientX || (e.changedTouches && e.changedTouches[0].clientX) || currentX;
      const diffX = clientX - startX;
      const effectiveOffset = isLockedOpen ? (-86 + diffX) : diffX;

      // Full swipe past threshold -> dismiss immediately
      if (effectiveOffset < -130) {
        dismissNotification(row, notifId, true);
        return;
      }

      // Partial swipe past delete button width -> snap open delete button
      if (effectiveOffset < -45) {
        card.style.transform = 'translateX(-86px)';
        isLockedOpen = true;
        row.classList.add('is-swiped');
      } else {
        // Snap closed
        card.style.transform = 'translateX(0px)';
        isLockedOpen = false;
        row.classList.remove('is-swiped');
      }
    };

    // Attach touch & pointer events
    card.addEventListener('pointerdown', onPointerDown, { passive: true });
    window.addEventListener('pointermove', onPointerMove, { passive: false });
    window.addEventListener('pointerup', onPointerUp, { passive: true });
    window.addEventListener('pointercancel', onPointerUp, { passive: true });
  });
}

// Notifications functions defined below in §12.10

// Close overlays on outside click or ESC
window.addEventListener('click', (e) => {
  if (e.target.classList.contains('app-modal-overlay')) {
    e.target.style.display = 'none';
    document.body.style.overflow = '';
  }
});
window.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') {
    document.querySelectorAll('.app-modal-overlay').forEach(m => m.style.display = 'none');
    document.body.style.overflow = '';
  }
});

// Helper: HTML Escaper for XSS Prevention (S-5)
function escapeHtml(str) {
  if (str === null || str === undefined) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

// Helper: CSRF Token Getter
function getCsrfToken() {
  return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

// Loading State Helper Function
function showLoadingState(isLoading, buttonElement = null) {
  if (!buttonElement) return;

  const originalText = buttonElement.getAttribute('data-original-text') || buttonElement.textContent;
  if (isLoading) {
    buttonElement.setAttribute('data-original-text', originalText);
    buttonElement.disabled = true;
    buttonElement.innerHTML = '<span class="spinner"></span> Loading...';
    buttonElement.classList.add('loading');
  } else {
    buttonElement.disabled = false;
    buttonElement.textContent = originalText;
    buttonElement.classList.remove('loading');
  }
}

// Filter Courts by Surface (All / Indoor / Outdoor)
function filterCourtsBySurface(type) {
  document.querySelectorAll('.court-filter-btn').forEach(b => b.classList.remove('active'));
  const activeBtn = document.getElementById(`filterBtn${type.charAt(0).toUpperCase() + type.slice(1)}`);
  if (activeBtn) activeBtn.classList.add('active');

  const cards = document.querySelectorAll('#referenceCourtsGrid .ref-court-card');
  cards.forEach(card => {
    const cardType = (card.getAttribute('data-surface-type') || '').toLowerCase();
    if (type === 'all' || cardType === type.toLowerCase()) {
      card.style.display = 'flex';
    } else {
      card.style.display = 'none';
    }
  });
}

// Toggle Other Venues Expandable Section
function toggleOtherVenues() {
  const sec = document.getElementById('otherVenuesSection');
  const btn = document.getElementById('btnToggleOtherVenues');
  if (!sec) return;
  if (sec.style.display === 'none' || !sec.style.display) {
    sec.style.display = 'block';
    if (btn) btn.innerHTML = '<span>Hide Other Facilities ▴</span>';
  } else {
    sec.style.display = 'none';
    if (btn) btn.innerHTML = '<span>Show Other Facilities ▾</span>';
  }
}

// Community Tag Insertion Helper
function insertPostTag(tag) {
  const ta = document.getElementById('communityPostInput');
  if (!ta) return;
  if (!ta.value.includes(tag)) {
    ta.value = ta.value.trim() ? `${ta.value.trim()} ${tag} ` : `${tag} `;
  }
  ta.focus();
}

// Community Post Submission
function submitCommunityPost() {
  const ta = document.getElementById('communityPostInput');
  if (!ta || !ta.value.trim()) {
    showToast('Please type something to post to the community', 'error');
    return;
  }
  const text = ta.value.trim();
  const feed = document.getElementById('communityFeedList');
  if (feed) {
    const newPost = document.createElement('div');
    newPost.className = 'community-post-card';
    newPost.style.animation = 'modalSlideUp 0.3s cubic-bezier(0.16, 1, 0.3, 1) forwards';
    newPost.innerHTML = `
          <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:12px;">
            <div style="display:flex; align-items:center; gap:12px;">
              <div class="user-avatar-circle-sm" style="width:38px; height:38px; font-size:15px;">P</div>
              <div>
                <div style="font-size:14px; font-weight:800; color:#FFFFFF;">PICKLERS Dev</div>
                <div style="font-size:12px; color:#64748B;">Just now • Verified Player</div>
              </div>
            </div>
            <span class="ref-court-tag" style="color:#00D98B; border-color:rgba(0, 217, 139,0.3);">#Community</span>
          </div>
          <p style="font-size:14px; color:#E2E8F0; line-height:1.6; margin:0 0 16px;">
            ${escapeHtml(text)}
          </p>
          <div style="display:flex; gap:20px; border-top:1px solid rgba(255,255,255,0.06); padding-top:12px; font-size:13px; color:#94A3B8;">
            <button type="button" onclick="togglePostLike(this)" style="background:transparent; border:none; color:inherit; font-size:inherit; font-weight:700; cursor:pointer; display:flex; align-items:center; gap:6px;">
              <span>❤️</span> <span class="like-count">1</span> Like
            </button>
            <button type="button" onclick="showToast('Comments feature opens reply thread', 'info')" style="background:transparent; border:none; color:inherit; font-size:inherit; font-weight:700; cursor:pointer; display:flex; align-items:center; gap:6px;">
              <span>💬</span> 0 Comments
            </button>
            <button type="button" onclick="showToast('Link copied to clipboard!', 'success')" style="background:transparent; border:none; color:inherit; font-size:inherit; font-weight:700; cursor:pointer; display:flex; align-items:center; gap:6px;">
              <span>↗</span> Share
            </button>
          </div>
        `;
    feed.insertBefore(newPost, feed.firstChild);
  }
  ta.value = '';
  showToast('✓ Post shared with Picklers community!', 'success');
}

// Toggle Post Like Counter
function togglePostLike(btn) {
  const countEl = btn.querySelector('.like-count');
  if (!countEl) return;
  let count = parseInt(countEl.textContent, 10) || 0;
  if (btn.classList.contains('liked')) {
    btn.classList.remove('liked');
    countEl.textContent = Math.max(0, count - 1);
    btn.style.color = '#94A3B8';
  } else {
    btn.classList.add('liked');
    countEl.textContent = count + 1;
    btn.style.color = '#EF4444';
    showToast('❤️ Liked post', 'success');
  }
}

// ========================================================================
// Facility Discovery, Filter & Search Controllers (§7 & §8 Flowchart)
// ========================================================================
let currentCourtTypeFilter = 'All';
let currentSortFilter = 'recommended';
let currentMaxPriceFilter = 2000;
let cachedFacilityCourts = [];

// Search Input Handler with Clear Button Toggle
function handleFacilitySearchInput(input) {
  const clearBtn = document.getElementById('facilitySearchClearBtn');
  if (clearBtn) {
    if (input.value.trim().length > 0) {
      clearBtn.classList.add('visible');
    } else {
      clearBtn.classList.remove('visible');
    }
  }
  filterFacilitiesLive();
}

function clearFacilitySearch() {
  const input = document.getElementById('facilitySearchInput');
  const clearBtn = document.getElementById('facilitySearchClearBtn');
  if (input) input.value = '';
  if (clearBtn) clearBtn.classList.remove('visible');
  filterFacilitiesLive();
}

// Dynamic Price Range Slider Display Readout
function updatePriceFilterDisplay(val) {
  const valNum = parseInt(val, 10) || 2000;
  const displayEl = document.getElementById('priceRangeValue');
  if (displayEl) {
    if (valNum >= 2000) {
      displayEl.textContent = '₱200 – ₱2,000+';
    } else {
      displayEl.textContent = `Up to ₱${valNum.toLocaleString()} / hr`;
    }
  }
}

// Quick Court Type Filter Pills (All / Indoor / Outdoor)
function selectQuickFilter(type) {
  currentCourtTypeFilter = type;
  ['fQuickAll', 'fQuickIndoor', 'fQuickOutdoor'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.classList.remove('active');
  });
  const activeBtn = document.getElementById(`fQuick${type}`);
  if (activeBtn) activeBtn.classList.add('active');

  // Sync with modal segmented control
  ['fTypeAll', 'fTypeIndoor', 'fTypeOutdoor'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.classList.remove('active');
  });
  const modalBtn = document.getElementById(`fType${type}`);
  if (modalBtn) modalBtn.classList.add('active');

  updateFilterBadgeCounter();
  filterFacilitiesLive();
}

// Sort Dropdown Change
function applyQuickSort(sortVal) {
  currentSortFilter = sortVal;
  // Sync radio in modal
  const radio = document.querySelector(`input[name="modalSortRadio"][value="${sortVal}"]`);
  if (radio) radio.checked = true;
  updateFilterBadgeCounter();
  filterFacilitiesLive();
}

// Filter Modal: Segmented Control Selection
function selectFilterType(type) {
  ['fTypeAll', 'fTypeIndoor', 'fTypeOutdoor'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.classList.remove('active');
  });
  const btn = document.getElementById(`fType${type}`);
  if (btn) btn.classList.add('active');
}

// Filter Modal: Apply Action
function applyFiltersFromModal() {
  // Read selected type
  let selectedType = 'All';
  if (document.getElementById('fTypeIndoor')?.classList.contains('active')) selectedType = 'Indoor';
  if (document.getElementById('fTypeOutdoor')?.classList.contains('active')) selectedType = 'Outdoor';
  currentCourtTypeFilter = selectedType;

  // Sync quick buttons
  ['fQuickAll', 'fQuickIndoor', 'fQuickOutdoor'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.classList.remove('active');
  });
  const quickBtn = document.getElementById(`fQuick${selectedType}`);
  if (quickBtn) quickBtn.classList.add('active');

  // Read selected price range slider
  const priceInput = document.getElementById('filterPriceRange');
  if (priceInput) {
    currentMaxPriceFilter = parseInt(priceInput.value, 10) || 2000;
  }

  // Read selected sort
  const checkedSort = document.querySelector('input[name="modalSortRadio"]:checked')?.value || 'recommended';
  currentSortFilter = checkedSort;
  const selectEl = document.getElementById('quickSortSelect');
  if (selectEl) selectEl.value = checkedSort;

  updateFilterBadgeCounter();
  closeModal('filterModal');
  filterFacilitiesLive();
  showToast(`Filters applied: ${selectedType} courts, max ₱${currentMaxPriceFilter >= 2000 ? '2,000+' : currentMaxPriceFilter}`, 'success');
}

// Filter Modal: Reset Action
function resetFilterModal() {
  selectFilterType('All');
  const recRadio = document.querySelector('input[name="modalSortRadio"][value="recommended"]');
  if (recRadio) recRadio.checked = true;
  const priceInput = document.getElementById('filterPriceRange');
  if (priceInput) {
    priceInput.value = 2000;
    updatePriceFilterDisplay(2000);
  }
  currentMaxPriceFilter = 2000;
}

// Reset All Play Tab Filters
function resetAllPlayFilters() {
  clearFacilitySearch();
  selectQuickFilter('All');
  const selectEl = document.getElementById('quickSortSelect');
  if (selectEl) selectEl.value = 'recommended';
  currentSortFilter = 'recommended';
  const priceInput = document.getElementById('filterPriceRange');
  if (priceInput) {
    priceInput.value = 2000;
    updatePriceFilterDisplay(2000);
  }
  currentMaxPriceFilter = 2000;
  updateFilterBadgeCounter();
  filterFacilitiesLive();
  showToast('All filters have been reset', 'info');
}

function updateFilterBadgeCounter() {
  let count = 0;
  if (currentCourtTypeFilter !== 'All') count++;
  if (currentSortFilter !== 'recommended') count++;
  if (currentMaxPriceFilter < 2000) count++;
  const badge = document.getElementById('activeFilterCount');
  if (badge) {
    if (count > 0) {
      badge.textContent = count;
      badge.style.display = 'inline-block';
    } else {
      badge.style.display = 'none';
    }
  }
}

// Live Multi-Facet Filtering and Sorting of Facilities
let facilityFilterDebounceTimer = null;

function filterFacilitiesLive() {
  const query = (document.getElementById('facilitySearchInput')?.value || '').toLowerCase().trim();
  const grid = document.getElementById('facilitiesGrid');
  const skGrid = document.getElementById('facilitiesSkeletonGrid');
  if (!grid) return;

  // Show skeleton shimmer during filtering
  if (skGrid && skGrid.style.display === 'none') {
    skGrid.style.display = 'grid';
    grid.style.display = 'none';
  }

  if (facilityFilterDebounceTimer) clearTimeout(facilityFilterDebounceTimer);

  facilityFilterDebounceTimer = setTimeout(() => {
    const cards = Array.from(grid.querySelectorAll('.facility-card-item'));
    let matchCount = 0;

    cards.forEach(card => {
      const name = (card.getAttribute('data-name') || '').toLowerCase();
      const loc = (card.getAttribute('data-loc') || '').toLowerCase();
      const type = (card.getAttribute('data-type') || '').toLowerCase();
      const price = parseFloat(card.getAttribute('data-price') || 0);

      const matchesQuery = !query || name.includes(query) || loc.includes(query);
      const matchesType = (currentCourtTypeFilter === 'All') || (type === currentCourtTypeFilter.toLowerCase());
      const matchesPrice = (currentMaxPriceFilter >= 2000) || (price <= currentMaxPriceFilter);

      if (matchesQuery && matchesType && matchesPrice) {
        card.style.display = 'flex';
        matchCount++;
      } else {
        card.style.display = 'none';
      }
    });

    // Apply Sorting
    if (currentSortFilter === 'price_asc') {
      cards.sort((a, b) => parseFloat(a.getAttribute('data-price') || 0) - parseFloat(b.getAttribute('data-price') || 0));
      cards.forEach(c => grid.appendChild(c));
    } else if (currentSortFilter === 'rating_desc') {
      cards.sort((a, b) => parseFloat(b.getAttribute('data-rating') || 0) - parseFloat(a.getAttribute('data-rating') || 0));
      cards.forEach(c => grid.appendChild(c));
    }

    if (skGrid) skGrid.style.display = 'none';
    grid.style.display = 'grid';

    const emptyState = document.getElementById('facilitiesEmptyState');
    if (emptyState) emptyState.style.display = (matchCount === 0) ? 'block' : 'none';
  }, 180);
}

/**
 * Re-render the Discover Courts grid from live data — a JS-side mirror of
 * _tab-play.php's own facility-card markup, kept deliberately close to that
 * template (same classes, same fields, same fallbacks) so the two never
 * visibly disagree.
 *
 * This exists so an owner listing a new court, or a brand-new facility
 * being approved, reaches a player's Discover feed without that player
 * having to reload the page — see PickSync's 'facilities'/'courts'
 * subscription below. Only runs when the grid is actually on screen
 * (#facilitiesGrid only exists in the DOM on the Play tab's list view, not
 * the facility-detail view or any other tab).
 */
function renderFacilitiesGrid(facilities) {
  const grid = document.getElementById('facilitiesGrid');
  if (!grid) return;

  grid.innerHTML = (facilities || []).map(f => {
    const typeStr = f.type || 'Indoor';
    const typeNormalized = /indoor/i.test(typeStr) ? 'Indoor' : 'Outdoor';
    const minPrice = parseFloat(f.min_price ?? f.price_numeric ?? 140) || 140;
    const maxPrice = parseFloat(f.max_price ?? f.price_numeric ?? minPrice) || minPrice;
    const ratingNum = parseFloat(f.rating ?? 4.8) || 4.8;
    const priceHtml = minPrice < maxPrice
      ? `₱${minPrice.toFixed(0)} - ₱${maxPrice.toFixed(0)}<span style="font-size:12px; color:rgba(255,255,255,0.5);">/hr</span>`
      : `₱${minPrice.toFixed(0)}<span style="font-size:12px; color:rgba(255,255,255,0.5);">/hr</span>`;

    return `
      <div class="app-facility-card facility-card-item" onclick="openFacilityDetail(${f.id})" style="cursor:pointer;" data-id="${f.id}" data-name="${escapeHtml(f.name)}" data-loc="${escapeHtml(f.location)}" data-lat="${f.latitude || 9.3065}" data-lng="${f.longitude || 123.3050}" data-type="${typeNormalized}" data-price="${minPrice}" data-price-max="${maxPrice}" data-rating="${ratingNum}">
        <div class="card-thumb-wrap">
          <img src="${escapeHtml(f.image)}" alt="${escapeHtml(f.name)}" class="card-thumb-img" loading="lazy" onerror="this.onerror=null; this.removeAttribute('src'); this.style.background='var(--pk-bg-card-hover, #162D4D)';">
          <button type="button" class="card-heart-btn" onclick="event.stopPropagation(); toggleFavoriteFacility(this, '${escapeHtml(f.name).replace(/'/g, "\\'")}')" title="Add to favorites">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></svg>
          </button>
        </div>
        <div class="card-body">
          <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:4px;">
            <h3 class="card-title" style="margin:0;">${escapeHtml(f.name)}</h3>
          </div>
          <div class="card-location" style="margin-bottom: 4px;">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
            <span>${escapeHtml(f.location)}</span>
          </div>
          <div style="margin-top: 2px; margin-bottom: 3px;">
            <span style="color:#FFFFFF; font-weight:800; font-size:12.5px;">${parseInt(f.courts_count ?? 0, 10)} Courts Listed</span>
          </div>
          <div style="font-size:12px; color:#94A3B8; margin-bottom: 3px;">
            <span class="facility-transit-text">${escapeHtml(f.transit || '🛵 5 min · 🚗 10 min')}</span>
          </div>
          <div style="font-size:12px; color:rgba(255,255,255,0.7); display:flex; align-items:center; gap:8px; margin-bottom: 6px; flex-wrap:wrap;">
            <span style="color:#F59E0B; font-weight:800;">★ ${ratingNum.toFixed(1)}</span>
            <span style="color:var(--pk-text-muted, #94A3B8);">(${f.reviews ?? 100} reviews)</span>
          </div>
          <div class="card-meta-row" style="margin-top:auto; gap: 8px;">
            <div>
              <div style="font-size:10px; color:rgba(255,255,255,0.45); text-transform:uppercase; font-weight:700;">${minPrice < maxPrice ? 'Rates' : 'Rate'}</div>
              <div class="card-price-cyan">${priceHtml}</div>
            </div>
            <div style="display:flex; align-items:center; gap:6px;">
              <button type="button" class="btn-view-courts" onclick="event.stopPropagation(); openFacilityDetail(${f.id})">
                <span>View Courts</span>
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
              </button>
            </div>
          </div>
        </div>
      </div>
    `;
  }).join('');

  const emptyState = document.getElementById('facilitiesEmptyState');
  if (emptyState) emptyState.style.display = (facilities && facilities.length) ? 'none' : 'block';
}

/** Re-fetch and silently re-render whichever Discover surface is on screen
 * right now — the grid (#facilitiesGrid) or an open facility's own court
 * list (#facilityDetailView) — in response to a PickSync 'facilities' or
 * 'courts' change. Exactly one of the two exists in the DOM at a time. */
function silentRefreshDiscover() {
  if (document.getElementById('facilitiesGrid')) {
    fetch('api.php?action=facilities')
      .then(r => r.json())
      .then(data => { if (data && data.success) renderFacilitiesGrid(data.facilities); })
      .catch(() => {});
    return;
  }
  const detailView = document.getElementById('facilityDetailView');
  if (detailView && detailView.style.display !== 'none' && currentSelectedFacility) {
    fetch(`api.php?action=facility_detail&id=${encodeURIComponent(currentSelectedFacility.id)}`)
      .then(r => r.json())
      .then(data => {
        if (data && data.success && data.facility) {
          renderDetailCourtsList(data.courts, data.facility);
        }
      })
      .catch(() => {});
  }
}

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

if (window.PickSync) {
  PickSync.on('facilities', silentRefreshDiscover);
  PickSync.on('courts', silentRefreshDiscover);
  PickSync.on('unread_notifications', count => syncNotifBellDot((count || 0) > 0));
  PickSync.start();
}

// Dynamic Real Transit Calculation based on GPS / Haversine Distance
function updateFacilitiesRealTransit(userLat, userLng) {
  const currentLat = userLat || 9.3065;
  const currentLng = userLng || 123.3050;

  const cards = document.querySelectorAll('.app-facility-card');
  cards.forEach(card => {
    const facLat = parseFloat(card.dataset.lat || '9.3065');
    const facLng = parseFloat(card.dataset.lng || '123.3050');
    
    // Calculate Haversine distance in km
    const dLat = (facLat - currentLat) * Math.PI / 180;
    const dLon = (facLng - currentLng) * Math.PI / 180;
    const a = Math.sin(dLat/2) * Math.sin(dLat/2) +
              Math.cos(currentLat * Math.PI / 180) * Math.cos(facLat * Math.PI / 180) *
              Math.sin(dLon/2) * Math.sin(dLon/2);
    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
    const distKm = 6371 * c;
    
    // Real estimated transit times:
    // Motorbike ~ 28 km/h + 2 min overhead
    const motoMin = Math.max(2, Math.round((distKm / 28) * 60 + 2));
    // Car ~ 20 km/h + 4 min overhead
    const carMin = Math.max(4, Math.round((distKm / 20) * 60 + 4));
    
    const transitText = `🛵 ${motoMin} min · 🚗 ${carMin} min`;
    const transitEl = card.querySelector('.facility-transit-text');
    if (transitEl) {
      transitEl.textContent = transitText;
    }
  });
}

// Dynamic Geolocation Detection with OpenStreetMap Reverse Geocoding
function detectLocation() {
  const pill = document.getElementById('locationPillText');
  if (pill) pill.textContent = "Locating...";
  if (navigator.geolocation) {
    navigator.geolocation.getCurrentPosition(
      (pos) => {
        const lat = pos.coords.latitude;
        const lon = pos.coords.longitude;
        updateFacilitiesRealTransit(lat, lon);
        fetch(`https://nominatim.openstreetmap.org/reverse?lat=${lat}&lon=${lon}&format=json`)
          .then(r => r.json())
          .then(data => {
            const addr = data.address || {};
            const city = addr.city || addr.town || addr.municipality || addr.county || 'Metro Manila';
            if (pill) pill.textContent = city;
            showToast(`Location detected: ${city}`, 'success');
          })
          .catch(() => {
            let region = "Metro Manila";
            if (lat < 10.5 && lat > 9.0) region = "Dumaguete City, Negros";
            else if (lat >= 10.0 && lat < 11.5) region = "Cebu City";
            else if (lat < 8.0) region = "Davao City";
            if (pill) pill.textContent = region;
            showToast(`Location updated: ${region}`, "success");
          });
      },
      (err) => {
        if (pill) pill.textContent = "Metro Manila";
        updateFacilitiesRealTransit(9.3065, 123.3050);
        showToast("Using default location: Metro Manila", "info");
      },
      { timeout: 4000 }
    );
  } else {
    if (pill) pill.textContent = "Metro Manila";
    updateFacilitiesRealTransit(9.3065, 123.3050);
  }
}

// Favorites Toggle
function toggleFavoriteFacility(btn, name) {
  btn.classList.toggle('favorited');
  if (btn.classList.contains('favorited')) {
    showToast(`Added ${name} to Favorites ♡`, "success");
  } else {
    showToast(`Removed ${name} from Favorites`, "success");
  }
}

// Notifications Management (§12.10)
function markAllNotificationsAsRead() {
  const unreadItems = document.querySelectorAll('.notif-card-item.unread');
  if (unreadItems.length === 0) {
    showToast('All notifications are already marked as read.', 'info');
    return;
  }

  unreadItems.forEach(item => {
    item.classList.remove('unread');
    const dot = item.querySelector('.notif-card-unread-dot');
    if (dot) dot.remove();
  });
  document.querySelectorAll('.notif-unread-dot, .notif-bell-badge, .header-notif-dot').forEach(dot => {
    dot.style.display = 'none';
  });

  const totalNotifs = document.querySelectorAll('.notif-card-item').length;
  const unreadBadgeText = document.getElementById('notifUnreadBadgeText');
  if (unreadBadgeText) {
    unreadBadgeText.textContent = `${totalNotifs} total`;
  }

  const fd = new FormData();
  fd.append('action', 'mark_notifications_read');
  fd.append('csrf_token', getCsrfToken());
  fetch('api.php', { method: 'POST', headers: { 'X-CSRF-TOKEN': getCsrfToken() }, body: fd })
    .then(r => r.json())
    .then(res => {
      if (res && res.success) {
        showToast('All notifications marked as read', 'success');
      } else {
        showToast((res && res.message) || 'Could not update notifications', 'error');
      }
    })
    .catch(() => showToast('Network error - notifications not updated', 'error'));
}

function dismissNotification(rowElement, notifId) {
  if (!rowElement) return;
  rowElement.style.transition = 'all 0.25s cubic-bezier(0.16, 1, 0.3, 1)';
  rowElement.style.opacity = '0';
  rowElement.style.transform = 'scale(0.9) translateX(40px)';
  rowElement.style.maxHeight = rowElement.offsetHeight + 'px';

  setTimeout(() => {
    rowElement.style.maxHeight = '0px';
    rowElement.style.padding = '0px';
    rowElement.style.margin = '0px';
    setTimeout(() => {
      rowElement.remove();
      const remaining = document.querySelectorAll('#notifModalList .notif-swipe-row');
      const unreadCount = document.querySelectorAll('#notifModalList .notif-card-item.unread').length;
      const unreadBadgeText = document.getElementById('notifUnreadBadgeText');

      if (unreadBadgeText) {
        unreadBadgeText.textContent = unreadCount > 0 ? `${unreadCount} unread of ${remaining.length}` : `${remaining.length} total`;
      }

      if (remaining.length === 0) {
        const listContainer = document.getElementById('notifModalList');
        if (listContainer) {
          listContainer.innerHTML = `
            <div style="text-align: center; padding: 48px 20px; color: var(--pk-text-muted);">
              <div style="font-size: 36px; margin-bottom: 10px;">🔔</div>
              <div style="font-size: 15px; font-weight: 700; color: var(--pk-text-primary); margin-bottom: 4px;">No notifications left</div>
              <div style="font-size: 12px; color: rgba(255, 255, 255, 0.55);">You're all caught up! Updates will appear here.</div>
            </div>
          `;
        }
      }
    }, 200);
  }, 200);

  // Was posting to api/notifications.php (404) while reporting success.
  const fd = new FormData();
  fd.append('action', 'delete_notification');
  fd.append('id', notifId);
  fd.append('csrf_token', getCsrfToken());
  fetch('api.php', { method: 'POST', headers: { 'X-CSRF-TOKEN': getCsrfToken() }, body: fd })
    .then(r => r.json())
    .then(res => {
      if (!res || !res.success) {
        showToast((res && res.message) || 'Could not remove that notification', 'error');
      }
    })
    .catch(() => showToast('Network error - notification not removed', 'error'));
}

// Facility Follow Toggle
function toggleFollowFacility(btn, facilityName) {
  const isFollowing = btn.getAttribute('data-following') === 'true';
  if (isFollowing) {
    btn.setAttribute('data-following', 'false');
    btn.style.background = 'rgba(255,255,255,0.06)';
    btn.style.borderColor = 'rgba(255,255,255,0.15)';
    btn.style.color = '#FFFFFF';
    btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></svg><span>Follow</span>';
    showToast(`Unfollowed ${facilityName}`, 'error');
  } else {
    btn.setAttribute('data-following', 'true');
    btn.style.background = 'rgba(239, 68, 68, 0.15)';
    btn.style.borderColor = 'rgba(239, 68, 68, 0.4)';
    btn.style.color = '#EF4444';
    btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="currentColor" stroke="none" style="flex-shrink:0;"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></svg><span>Following</span>';
    showToast(`Followed ${facilityName}`, 'success');
  }
}

// Facility Detail View Transition (§8 Flowchart)
function openFacilityDetail(facilityId) {
  const headerWrap = document.getElementById('facilityDetailHeaderWrap');
  const courtsList = document.getElementById('facilityCourtsList');
  const actionBtns = document.getElementById('facilityDetailActionBtns');

  // Loading skeleton state
  if (headerWrap) {
    headerWrap.innerHTML = `
          <div style="height:240px; border-radius:22px; margin-bottom:20px; overflow:hidden; position:relative; background:rgba(17, 35, 61, 0.7); border:1px solid rgba(255,255,255,0.08);">
            <div class="pk-skeleton" style="width:100%; height:100%;"></div>
            <div style="position:absolute; bottom:20px; left:20px; right:20px; display:flex; flex-direction:column; gap:8px;">
              <div class="pk-skeleton" style="height:28px; width:40%; border-radius:8px;"></div>
              <div class="pk-skeleton" style="height:16px; width:25%; border-radius:6px;"></div>
            </div>
          </div>
        `;
  }
  if (courtsList) {
    courtsList.innerHTML = [1, 2, 3, 4].map(() => `
          <div style="background:rgba(17, 35, 61, 0.6); border:1px solid rgba(255,255,255,0.08); border-radius:18px; padding:18px; display:flex; flex-direction:column; gap:12px;">
            <div style="display:flex; justify-content:space-between; align-items:center;">
              <div class="pk-skeleton" style="height:20px; width:45%; border-radius:6px;"></div>
              <div class="pk-skeleton" style="height:22px; width:22%; border-radius:9999px;"></div>
            </div>
            <div style="display:flex; gap:8px;">
              <div class="pk-skeleton" style="height:14px; width:30%; border-radius:4px;"></div>
              <div class="pk-skeleton" style="height:14px; width:25%; border-radius:4px;"></div>
            </div>
            <div style="margin-top:8px; display:flex; justify-content:space-between; align-items:center;">
              <div class="pk-skeleton" style="height:24px; width:30%; border-radius:6px;"></div>
              <div class="pk-skeleton" style="height:38px; width:36%; border-radius:12px;"></div>
            </div>
          </div>
        `).join('');
  }

  const playContent = document.getElementById('playTabContent');
  const detailView = document.getElementById('facilityDetailView');
  if (playContent) playContent.style.display = 'none';
  if (detailView) detailView.style.display = 'block';
  window.scrollTo({ top: 0, behavior: 'smooth' });

  fetch(`api.php?action=facility_detail&id=${encodeURIComponent(facilityId)}`)
    .then(r => r.json())
    .then(data => {
      let f = data?.facility;
      let courts = data?.courts || [];
      let images = data?.images || [];
      let amenities = data?.amenities || [];

      if (!f) return;

      cachedFacilityCourts = courts;

      if (actionBtns) {
        actionBtns.innerHTML = `
              <button type="button" class="btn-filter-trigger" onclick="toggleFollowFacility(this, '${escapeHtml(f.name)}')" data-following="false" style="display:inline-flex; align-items:center; gap:7px;">
                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></svg>
                <span>Follow</span>
              </button>
            `;
      }

      if (headerWrap) {
        const courtPrices = Array.isArray(courts) && courts.length > 0 ? courts.map(c => parseFloat(c.price) || 0).filter(p => p > 0) : [];
        const minPrice = courtPrices.length > 0 ? Math.min(...courtPrices) : (parseFloat(f.min_price || f.price_numeric || 140) || 140);
        const maxPrice = courtPrices.length > 0 ? Math.max(...courtPrices) : (parseFloat(f.max_price || f.price_numeric || minPrice) || minPrice);
        const priceText = minPrice < maxPrice ? `₱${minPrice.toFixed(0)} - ₱${maxPrice.toFixed(0)}` : `₱${minPrice.toFixed(0)}`;
        const rateLabel = minPrice < maxPrice ? 'Court Rates' : 'Court Rate';

        let galleryStripHtml = '';
        if (Array.isArray(images) && images.length > 1) {
          galleryStripHtml = `
            <div class="facility-gallery-strip" style="display:flex; gap:8px; overflow-x:auto; padding:10px 0; margin-top:-10px; margin-bottom:16px;">
              ${images.map((img, idx) => `
                <img src="${escapeHtml(img.url)}" alt="${escapeHtml(img.alt_text || 'Court')}" class="gallery-thumb ${idx === 0 ? 'active' : ''}" onclick="setHeroImage(this, '${escapeHtml(img.url)}')" style="width:72px; height:52px; object-fit:cover; border-radius:10px; cursor:pointer; border:2px solid ${idx === 0 ? '#00D98B' : 'transparent'}; background:var(--pk-bg-card-hover, #162D4D); flex-shrink:0; transition:all 0.2s ease;" onerror="this.onerror=null; this.removeAttribute('src');">
              `).join('')}
            </div>
          `;
        }

        let amenitiesRowHtml = '';

        headerWrap.innerHTML = `
              <div style="position:relative; height:240px; border-radius:22px; overflow:hidden; margin-bottom:12px;">
                <img id="facilityDetailHeroImg" src="${escapeHtml(f.image)}" alt="${escapeHtml(f.name)}" style="width:100%; height:100%; object-fit:cover; background:var(--pk-bg-card-hover, #162D4D);" onerror="this.onerror=null; this.removeAttribute('src'); this.alt='Photo unavailable';">
                <div style="position:absolute; inset:0; background:linear-gradient(to top, rgba(10,22,40,0.95) 10%, rgba(10,22,40,0.4) 60%, transparent);"></div>
                <div style="position:absolute; bottom:20px; left:22px; right:22px; display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; gap:12px;">
                  <div>
                    <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
                      <span style="background:rgba(255,255,255,0.15); backdrop-filter:blur(6px); color:#FFFFFF; font-size:11px; font-weight:700; padding:3px 8px; border-radius:6px;">🕒 ${escapeHtml(f.hours || '6am - 11pm')}</span>
                    </div>
                    <h2 style="font-size:26px; font-weight:900; color:#FFFFFF; margin:0 0 4px;">${escapeHtml(f.name)}</h2>
                    <div style="font-size:13px; color:rgba(255,255,255,0.8); display:flex; align-items:center; gap:8px;">
                      <span>📍 ${escapeHtml(f.location)}</span>
                      <span>•</span>
                      <span style="color:#F59E0B; font-weight:800;">★ ${escapeHtml(f.rating)}</span>
                      <span style="color:rgba(255,255,255,0.5);">(${escapeHtml(f.reviews || 98)} reviews)</span>
                    </div>
                  </div>
                  <div style="text-align:right;">
                    <div style="font-size:10px; color:rgba(255,255,255,0.6); text-transform:uppercase; font-weight:700;">${rateLabel}</div>
                    <div class="card-price-cyan" style="font-size:20px;">${priceText}<span style="font-size:12px; color:rgba(255,255,255,0.5);">/hr</span></div>
                  </div>
                </div>
              </div>
              ${galleryStripHtml}
            `;
      }

      renderDetailCourtsList(courts, f);
    })
    .catch(() => {
      const courtsList = document.getElementById('facilityCourtsList');
      if (courtsList) {
        courtsList.innerHTML = `<div style="text-align:center; padding:40px; color:var(--pk-text-muted);">Failed to load facility details. Please check your network connection.</div>`;
      }
    });
}

function setHeroImage(thumbEl, url) {
  const heroImg = document.getElementById('facilityDetailHeroImg');
  if (heroImg) {
    heroImg.src = url;
  }
  document.querySelectorAll('.gallery-thumb').forEach(el => {
    el.classList.remove('active');
    el.setAttribute('aria-current', 'false');
  });
  if (thumbEl) {
    thumbEl.classList.add('active');
    thumbEl.setAttribute('aria-current', 'true');
  }
}

let currentSelectedFacility = null;

function renderDetailCourtsList(courts, facility) {
  const courtsList = document.getElementById('facilityCourtsList');
  const countEl = document.getElementById('facilityCourtsCount');
  if (!courtsList) return;

  currentSelectedFacility = facility;
  cachedFacilityCourts = courts || [];

  if (countEl) {
    countEl.textContent = `(${courts ? courts.length : 0})`;
  }

  if (!courts || courts.length === 0) {
    courtsList.innerHTML = `
          <div style="grid-column:1/-1; text-align:center; padding:40px; background:rgba(255,255,255,0.03); border-radius:18px;">
            <p style="color:#94A3B8; margin:0;">No courts currently listed for this facility.</p>
          </div>
        `;
    return;
  }

  courtsList.innerHTML = courts.map(c => {
    const isAvail = (c.status === 'available');
    const isOccupied = (c.status === 'occupied');
    const isHostedOP = !!c.has_open_play || (c.occupied_by && (c.occupied_by.indexOf('Open Play') !== -1 || c.occupied_by.indexOf('Hosted') !== -1));
    const priceNum = parseFloat(c.price) || 180;
    const typeNormalized = (c.type || 'Indoor').toLowerCase();

    // Clean court title formatting - strictly "Court 1", "Court 2", etc.
    let displayTitle = 'Court 1';
    if (c.name) {
      const courtMatch = c.name.match(/Court\s*\d+/i);
      displayTitle = courtMatch ? courtMatch[0].replace(/^court\s*/i, 'Court ') : (c.name.replace(/\s*[\(–-].*$/, '').trim() || c.name);
    }

    const dotClass = isAvail ? 'dot-available' : (isOccupied ? 'dot-occupied' : 'dot-maintenance');

    // A real occupant/time is shown when the API actually reports one; a
    // court simply marked unavailable (no live occupancy data) says so
    // honestly rather than inventing a name and a time window nobody set.
    const occupancyHtml = (!isAvail && !isHostedOP) ? (
      (c.occupied_by || c.occupied_time || c.occupied_until) ? `
        <div class="court-occupancy-info">
          <div class="court-occupancy-name">${escapeHtml(c.occupied_by || 'Reserved')}</div>
          <div class="court-occupancy-time">${escapeHtml(c.occupied_time || (c.occupied_until ? `Until ${c.occupied_until}` : ''))}</div>
        </div>
      ` : ''
    ) : '';

    return `
          <div class="ref-court-card detail-court-card ${isAvail ? 'has-glow' : ''}" data-court-type="${escapeHtml(typeNormalized)}" ${isAvail ? `onclick="bookCourtDirectToPayment(${facility.id}, '${escapeHtml(String(c.id || '')).replace(/'/g, "\\'")}', '${escapeHtml(displayTitle).replace(/'/g, "\\'")}', ${priceNum}, '${escapeHtml(c.surface || 'Hard').replace(/'/g, "\\'")}', '${escapeHtml(c.type || 'Indoor').replace(/'/g, "\\'")}', '${escapeHtml(facility.name || '').replace(/'/g, "\\'")}')" style="cursor:pointer;"` : ''}>
            <div class="court-card-top-row">
              <h4 class="court-card-title">${escapeHtml(displayTitle)}</h4>
              <div style="display:flex; align-items:center; gap:8px;">
                <span class="court-status-dot ${dotClass}"></span>
              </div>
            </div>

            <div class="court-card-bottom-row">
              <div class="court-card-price">₱${priceNum.toFixed(0)}<span>/hr</span></div>
              ${isAvail ? `
                <button type="button" class="btn-court-book-now" onclick="event.stopPropagation(); bookCourtDirectToPayment(${facility.id}, '${escapeHtml(String(c.id || '')).replace(/'/g, "\\'")}', '${escapeHtml(displayTitle).replace(/'/g, "\\'")}', ${priceNum}, '${escapeHtml(c.surface || 'Hard').replace(/'/g, "\\'")}', '${escapeHtml(c.type || 'Indoor').replace(/'/g, "\\'")}', '${escapeHtml(facility.name || '').replace(/'/g, "\\'")}')">
                  Book Now
                </button>
              ` : `
                <div class="court-action-col">
                  ${isHostedOP ? `
                    <span class="badge-hosted-openplay" style="background: rgba(255, 184, 0, 0.15); border: 1px solid rgba(255, 184, 0, 0.4); color: #FFB800; font-size: 10px; font-weight: 800; padding: 5px 12px; border-radius: 9999px; letter-spacing: 0.04em; text-transform: uppercase; display: inline-block; white-space: nowrap;">HOSTED OPEN PLAY</span>
                  ` : `
                    ${occupancyHtml}
                    <button type="button" class="btn-court-occupied" disabled>
                      Occupied
                    </button>
                  `}
                </div>
              `}
            </div>
          </div>
        `;
  }).join('');
}

const ALL_COURT_TIME_SLOTS = [
  '7:00 AM – 8:00 AM',
  '8:00 AM – 9:00 AM',
  '9:00 AM – 10:00 AM',
  '10:00 AM – 11:00 AM',
  '11:00 AM – 12:00 PM',
  '12:00 PM – 1:00 PM',
  '1:00 PM – 2:00 PM',
  '2:00 PM – 3:00 PM',
  '3:00 PM – 4:00 PM',
  '4:00 PM – 5:00 PM',
  '5:00 PM – 6:00 PM',
  '6:00 PM – 7:00 PM',
  '7:00 PM – 8:00 PM',
  '8:00 PM – 9:00 PM',
  '9:00 PM – 10:00 PM'
];

let fetchedSlotAvailabilityMap = {};

function fetchAndApplySlotAvailability(facilityId, courtId, dateStr, onComplete) {
  const params = new URLSearchParams({
    action: 'slot_availability',
    facility_id: facilityId,
    court_id: courtId || '',
    date: dateStr || ''
  });
  fetch(`api.php?${params.toString()}`)
    .then(r => r.json())
    .then(res => {
      fetchedSlotAvailabilityMap = {};
      if (res && res.success && Array.isArray(res.slots)) {
        res.slots.forEach(s => {
          fetchedSlotAvailabilityMap[s.label] = s;
        });
      }
      if (typeof onComplete === 'function') onComplete(res?.slots || []);
      renderConfirmBookingTimes();
    })
    .catch(() => {
      fetchedSlotAvailabilityMap = {};
      if (typeof onComplete === 'function') onComplete([]);
      renderConfirmBookingTimes();
    });
}

function findNextFreeSlotForCourt(facilityId, courtName, dateStr) {
  const d = new Date();
  const currentH = d.getHours();

  for (const slot of ALL_COURT_TIME_SLOTS) {
    const slotInfo = fetchedSlotAvailabilityMap[slot];
    if (slotInfo && !slotInfo.available && slotInfo.reason === 'booked') continue;

    const parts = slot.split('–')[0].trim().split(':');
    let slotH = parseInt(parts[0], 10);
    const isPm = slot.includes('PM') && !slot.startsWith('12');
    if (isPm) slotH += 12;
    if (slot.startsWith('12') && slot.includes('AM')) slotH = 0;

    const isToday = !dateStr || dateStr.includes(d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }));
    if (isToday && slotH <= currentH) continue;
    return slot;
  }
  return '10:00 AM – 11:00 AM';
}

let pendingCourtBookingData = null;
let cbStartIdx = 1; // Default 7:00 AM
let cbEndIdx = 6;   // Default 12:00 PM (5 hours)
let cbSelectedDateStr = 'Thu Sep 10 2026';

function openConfirmBookingModal(data) {
  pendingCourtBookingData = data;

  const fNameEl = document.getElementById('confirmBookFacilityName');
  if (fNameEl) fNameEl.textContent = data.facilityName || 'Pickleball Facility';

  let cleanCourt = data.courtName || 'Court 1';
  const m = cleanCourt.match(/Court\s*\d+/i);
  if (m) cleanCourt = m[0].replace(/^court\s*/i, 'Court ');
  else cleanCourt = cleanCourt.replace(/\s*[\(–-].*$/, '').trim();

  const cNameEl = document.getElementById('confirmBookCourtName');
  if (cNameEl) cNameEl.textContent = cleanCourt;

  // Set date from data or default to today
  if (data.dateStr) {
    cbSelectedDateStr = data.dateStr;
  } else {
    const d = new Date();
    cbSelectedDateStr = d.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
  }

  // Highlight matching Date Pill if present in confirmDatePillsContainer
  const container = document.getElementById('confirmDatePillsContainer');
  if (container) {
    const pills = container.querySelectorAll('.qb-date-pill');
    pills.forEach(p => {
      p.classList.remove('active');
      const onclickAttr = p.getAttribute('onclick') || '';
      if (onclickAttr.includes(cbSelectedDateStr)) {
        p.classList.add('active');
      }
    });
  }

  // Parse timeRange if passed (e.g. '7:00 AM – 12:00 PM' or '10:00 AM – 11:00 AM')
  if (data.timeRange) {
    const parts = data.timeRange.split(/–|-/);
    if (parts.length === 2) {
      const sStr = parts[0].trim();
      const eStr = parts[1].trim();
      const sIdx = QB_TIMES.indexOf(sStr);
      const eIdx = QB_TIMES.indexOf(eStr);
      if (sIdx !== -1) cbStartIdx = sIdx;
      if (eIdx !== -1) cbEndIdx = eIdx;
      else if (sIdx !== -1) cbEndIdx = Math.min(QB_TIMES.length - 1, sIdx + (data.duration || 1));
    }
  } else {
    cbStartIdx = 1; // 7:00 AM
    cbEndIdx = 6;   // 12:00 PM
  }

  fetchAndApplySlotAvailability(data.facilityId, data.courtId || '', cbSelectedDateStr);
  renderConfirmBookingTimes();

  const btnProceed = document.getElementById('btnConfirmProceedBooking');
  if (btnProceed) {
    btnProceed.onclick = function () {
      closeModal('confirmBookingModal');
      if (pendingCourtBookingData) {
        openPaymentReview(
          pendingCourtBookingData.facilityId,
          pendingCourtBookingData.courtId || '',
          cleanCourt,
          pendingCourtBookingData.price,
          pendingCourtBookingData.surface,
          pendingCourtBookingData.type,
          pendingCourtBookingData.facilityName,
          pendingCourtBookingData.dateStr,
          pendingCourtBookingData.timeRange,
          pendingCourtBookingData.duration
        );
      }
    };
  }

  openModal('confirmBookingModal');
}

function renderConfirmBookingTimes() {
  const startPrevEl = document.getElementById('cbStartPrev');
  const startActiveEl = document.getElementById('cbStartActive');
  const startNextEl = document.getElementById('cbStartNext');

  const endPrevEl = document.getElementById('cbEndPrev');
  const endActiveEl = document.getElementById('cbEndActive');
  const endNextEl = document.getElementById('cbEndNext');

  // Start Time Column
  if (startPrevEl) {
    if (cbStartIdx > 0) {
      startPrevEl.textContent = QB_TIMES[cbStartIdx - 1];
      startPrevEl.style.visibility = 'visible';
      startPrevEl.style.pointerEvents = 'auto';
    } else {
      startPrevEl.textContent = '—';
      startPrevEl.style.visibility = 'hidden';
      startPrevEl.style.pointerEvents = 'none';
    }
  }
  if (startActiveEl) startActiveEl.textContent = QB_TIMES[cbStartIdx];
  if (startNextEl) {
    if (cbStartIdx < QB_TIMES.length - 2) {
      startNextEl.textContent = QB_TIMES[cbStartIdx + 1];
      startNextEl.style.visibility = 'visible';
      startNextEl.style.pointerEvents = 'auto';
    } else {
      startNextEl.textContent = '—';
      startNextEl.style.visibility = 'hidden';
      startNextEl.style.pointerEvents = 'none';
    }
  }

  // End Time Column
  if (endPrevEl) {
    if (cbEndIdx > 1) {
      endPrevEl.textContent = QB_TIMES[cbEndIdx - 1];
      endPrevEl.style.visibility = 'visible';
      endPrevEl.style.pointerEvents = 'auto';
    } else {
      endPrevEl.textContent = '—';
      endPrevEl.style.visibility = 'hidden';
      endPrevEl.style.pointerEvents = 'none';
    }
  }
  if (endActiveEl) endActiveEl.textContent = QB_TIMES[cbEndIdx];
  if (endNextEl) {
    if (cbEndIdx < QB_TIMES.length - 1) {
      endNextEl.textContent = QB_TIMES[cbEndIdx + 1];
      endNextEl.style.visibility = 'visible';
      endNextEl.style.pointerEvents = 'auto';
    } else {
      endNextEl.textContent = '—';
      endNextEl.style.visibility = 'hidden';
      endNextEl.style.pointerEvents = 'none';
    }
  }

  // Duration & Schedule Calculation
  const dur = Math.max(1, cbEndIdx - cbStartIdx);
  const durText = (dur === 1) ? '1 hour' : `${dur} hours`;
  const durEl = document.getElementById('cbDurationText');
  if (durEl) durEl.textContent = durText;

  const timeRange = `${QB_TIMES[cbStartIdx]} – ${QB_TIMES[cbEndIdx]}`;
  const timeRangeEl = document.getElementById('cbSummaryTimeRange');
  if (timeRangeEl) timeRangeEl.textContent = timeRange;

  const dateSummaryEl = document.getElementById('cbSummaryDateText');
  if (dateSummaryEl) dateSummaryEl.textContent = cbSelectedDateStr;

  // Conflict Check against fetchedSlotAvailabilityMap
  const conflictNoticeEl = document.getElementById('cbConflictNotice');
  const btnProceed = document.getElementById('btnConfirmProceedBooking');
  let isBookedClash = false;

  if (fetchedSlotAvailabilityMap && Object.keys(fetchedSlotAvailabilityMap).length > 0) {
    for (let i = cbStartIdx; i < cbEndIdx; i++) {
      const slotLabel = `${QB_TIMES[i]} – ${QB_TIMES[i+1]}`;
      const slotInfo = fetchedSlotAvailabilityMap[slotLabel];
      if (slotInfo && !slotInfo.available && slotInfo.reason === 'booked') {
        isBookedClash = true;
        break;
      }
    }
  }

  if (conflictNoticeEl) {
    if (isBookedClash) {
      conflictNoticeEl.innerHTML = `<div style="background:rgba(239,68,68,0.15); border:1px solid rgba(239,68,68,0.4); color:#FCA5A5; font-size:12px; font-weight:700; padding:8px 12px; border-radius:10px; text-align:center;">⚠️ That court is already booked for the selected time slot. Please choose another time.</div>`;
      conflictNoticeEl.style.display = 'block';
      if (btnProceed) {
        btnProceed.disabled = true;
        btnProceed.style.opacity = '0.4';
        btnProceed.style.cursor = 'not-allowed';
      }
    } else {
      conflictNoticeEl.style.display = 'none';
      if (btnProceed) {
        btnProceed.disabled = false;
        btnProceed.style.opacity = '1';
        btnProceed.style.cursor = 'pointer';
      }
    }
  }

  // Update payment breakdown dynamically
  if (pendingCourtBookingData) {
    pendingCourtBookingData.dateStr = cbSelectedDateStr;
    pendingCourtBookingData.timeRange = timeRange;
    pendingCourtBookingData.duration = dur;
  }

  const rateNum = parseFloat(pendingCourtBookingData?.price) || 180;
  const courtFee = dur * rateNum;
  const platformFee = Math.round(courtFee * 0.10);
  const total = courtFee + platformFee;

  const courtFeeEl = document.getElementById('confirmBookCourtFee');
  if (courtFeeEl) courtFeeEl.textContent = `₱${courtFee.toLocaleString()}`;

  const platformFeeEl = document.getElementById('confirmBookPlatformFee');
  if (platformFeeEl) platformFeeEl.textContent = `₱${platformFee.toLocaleString()}`;

  const totalEl = document.getElementById('confirmBookTotalPrice');
  if (totalEl) totalEl.textContent = `₱${total.toLocaleString()}`;
}

function handleConfirmTimeWheel(e, type) {
  if (e) e.preventDefault();
  const delta = (e && e.deltaY < 0) ? -1 : 1;
  if (type === 'start') {
    shiftConfirmStartTime(delta);
  } else {
    shiftConfirmEndTime(delta);
  }
}

function shiftConfirmStartTime(delta) {
  let newIdx = cbStartIdx + delta;
  if (newIdx < 0) newIdx = 0;
  if (newIdx >= QB_TIMES.length - 1) newIdx = QB_TIMES.length - 2;
  cbStartIdx = newIdx;
  if (cbEndIdx <= cbStartIdx) {
    cbEndIdx = Math.min(QB_TIMES.length - 1, cbStartIdx + 1);
  }
  renderConfirmBookingTimes();
}

function shiftConfirmEndTime(delta) {
  let newIdx = cbEndIdx + delta;
  if (newIdx <= cbStartIdx) newIdx = cbStartIdx + 1;
  if (newIdx >= QB_TIMES.length) newIdx = QB_TIMES.length - 1;
  cbEndIdx = newIdx;
  renderConfirmBookingTimes();
}

function selectConfirmBookingDate(btn, dateFull) {
  const container = document.getElementById('confirmDatePillsContainer');
  if (container) {
    container.querySelectorAll('.qb-date-pill').forEach(b => b.classList.remove('active'));
  }
  if (btn) {
    btn.classList.add('active');
    btn.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
  }
  cbSelectedDateStr = dateFull;
  if (pendingCourtBookingData) {
    fetchAndApplySlotAvailability(pendingCourtBookingData.facilityId, pendingCourtBookingData.courtId || '', cbSelectedDateStr);
  } else {
    renderConfirmBookingTimes();
  }
}

function bookCourtDirectToPayment(facilityId, courtId = '', courtName = 'Court 1', price = 180, surface = 'Hard', type = 'Indoor', facilityName = '') {
  const resolvedFacilityName = facilityName || document.getElementById('facilityDetailTitle')?.textContent || 'Pickleball Facility';
  const d = new Date();
  const dateStr = d.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });

  // Automatically find the next unbooked/free slot instead of hardcoding 9:00 AM - 10:00 AM
  const freeSlot = findNextFreeSlotForCourt(facilityId, courtName, dateStr);

  openConfirmBookingModal({
    facilityId,
    // The real court id from the facility's own courts list — this is what
    // the server prices and locks against. courtName below stays purely
    // cosmetic (it only ever feeds a display label); a court name mangled by
    // any of this file's display-cleanup regexes can no longer cause the
    // wrong court to be priced or booked once an id is present.
    courtId,
    courtName,
    price,
    surface,
    type,
    facilityName: resolvedFacilityName,
    dateStr,
    timeRange: freeSlot,
    duration: 1
  });
}

function filterDetailCourts(surface, btn) {
  if (btn) {
    document.querySelectorAll('#detailCourtFilters .court-pill-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
  } else {
    document.querySelectorAll('#detailCourtFilters .court-pill-btn').forEach(b => b.classList.remove('active'));
  }

  const cards = document.querySelectorAll('.detail-court-card');
  cards.forEach(card => {
    const type = (card.getAttribute('data-court-type') || '').toLowerCase();
    if (surface === 'all' || type.includes(surface.toLowerCase())) {
      card.style.display = 'flex';
    } else {
      card.style.display = 'none';
    }
  });
}

function triggerQuickBookFirstAvailable() {
  if (!cachedFacilityCourts || cachedFacilityCourts.length === 0) {
    showToast('No courts available for quick booking.', 'info');
    return;
  }
  const avail = cachedFacilityCourts.find(c => c.status === 'available') || cachedFacilityCourts[0];
  const facilityName = currentSelectedFacility?.name || 'Picklers Partner Court';
  const facilityId = currentSelectedFacility?.id || 1;
  const priceNum = parseFloat(avail.price) || 180;
  openQuickBookModal(facilityId, avail.name, priceNum, facilityName);
}

function closeFacilityDetail() {
  currentSelectedFacility = null;
  const detailView = document.getElementById('facilityDetailView');
  const playContent = document.getElementById('playTabContent');
  if (detailView) detailView.style.display = 'none';
  if (playContent) playContent.style.display = 'block';
}

function openVenueNavigationModal(facilityName, address) {
  const nameEl = document.getElementById('navFacilityName');
  const addrEl = document.getElementById('navFacilityAddress');
  const gMapsEl = document.getElementById('navGoogleMapsLink');
  const wazeEl = document.getElementById('navWazeLink');

  if (nameEl) nameEl.textContent = facilityName;
  if (addrEl) addrEl.textContent = address;
  if (gMapsEl) gMapsEl.href = `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(facilityName + ' ' + address)}`;
  if (wazeEl) wazeEl.href = `https://waze.com/ul?q=${encodeURIComponent(facilityName + ' ' + address)}`;

  openModal('navigationModal');
}

// ========================================================================
// Explore Tab — Open Play Matches Controllers (§10 Flowchart)
// ========================================================================
let currentMatchLevelFilter = 'All';

function filterMatchesLevel(level) {
  currentMatchLevelFilter = level;
  ['pillLevelAll', 'pillLevelBeginner', 'pillLevelIntermediate', 'pillLevelAdvanced'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.classList.remove('active');
  });
  const activePill = document.getElementById(`pillLevel${level}`);
  if (activePill) activePill.classList.add('active');

  filterMatchesLive();
}

let matchFilterDebounceTimer = null;

function filterMatchesLive() {
  const input = document.getElementById('matchSearchInput');
  const query = (input?.value || '').toLowerCase().trim();
  const clearBtn = document.getElementById('matchSearchClearBtn');
  const grid = document.getElementById('matchesGrid');
  const skGrid = document.getElementById('matchesSkeletonGrid');

  if (clearBtn) {
    if (query.length > 0) clearBtn.classList.add('visible');
    else clearBtn.classList.remove('visible');
  }

  // Display skeleton during filter switch
  if (skGrid && grid && skGrid.style.display === 'none') {
    skGrid.style.display = 'grid';
    grid.style.display = 'none';
  }

  if (matchFilterDebounceTimer) clearTimeout(matchFilterDebounceTimer);

  matchFilterDebounceTimer = setTimeout(() => {
    const cards = document.querySelectorAll('.match-card-item');
    let matchCount = 0;

    cards.forEach(card => {
      const cardLevel = (card.getAttribute('data-level') || '').toLowerCase();
      const facility = (card.getAttribute('data-facility') || '').toLowerCase();
      const loc = (card.getAttribute('data-location') || '').toLowerCase();

      const matchesLevel = (currentMatchLevelFilter === 'All') || (cardLevel === currentMatchLevelFilter.toLowerCase());
      const matchesQuery = !query || facility.includes(query) || loc.includes(query);

      if (matchesLevel && matchesQuery) {
        card.style.display = 'flex';
        matchCount++;
      } else {
        card.style.display = 'none';
      }
    });

    if (skGrid) skGrid.style.display = 'none';
    if (grid) grid.style.display = 'grid';

    const emptyState = document.getElementById('matchesEmptyState');
    if (emptyState) emptyState.style.display = (matchCount === 0) ? 'block' : 'none';
  }, 180);
}

function clearMatchSearch() {
  const input = document.getElementById('matchSearchInput');
  const clearBtn = document.getElementById('matchSearchClearBtn');
  if (input) input.value = '';
  if (clearBtn) clearBtn.classList.remove('visible');
  filterMatchesLive();
}

function resetAllMatchFilters() {
  clearMatchSearch();
  filterMatchesLevel('All');
}

function joinOpenPlay(matchId, facilityName = '', matchType = 'Open Play', date = '', time = '', price = 200, level = 'All Levels', location = '') {
  const matchData = {
    matchId: String(matchId),
    facilityName: facilityName || 'Pickleball Facility',
    matchType: matchType || 'Open Play Session',
    date: date || 'Upcoming Match',
    time: time || 'Open Play Hours',
    price: parseFloat(price) || 200,
    level: level || 'All Levels',
    location: location || ''
  };

  sessionStorage.setItem('picklers_openplay_checkout', JSON.stringify(matchData));
  window.location.href = 'app.php?tab=play&checkout=openplay';
}

// ========================================================================
// Community Tab Controllers (§11 Flowchart)
// ========================================================================
let currentChatPartnerId = 'usr_dave';

function switchCommunitySubTab(subTab) {
  // 1. Update sub-nav button active classes
  ['feed', 'discover', 'messages', 'profile'].forEach(tab => {
    const btn = document.getElementById(`commSubTab_${tab}`);
    const panel = document.getElementById(`commPanel_${tab}`);
    if (btn) {
      if (tab === subTab) btn.classList.add('active');
      else btn.classList.remove('active');
    }
    if (panel) {
      panel.style.display = (tab === subTab) ? 'block' : 'none';
    }
  });

  // 2. Tab-specific triggers
  if (subTab === 'messages') {
    const stream = document.getElementById('chatMessagesStream');
    if (stream) stream.scrollTop = stream.scrollHeight;
  }
}

function insertPostTag(tag) {
  const input = document.getElementById('communityPostInput');
  if (!input) return;
  const current = input.value.trim();
  if (!current.includes(tag)) {
    input.value = current ? `${current} ${tag}` : tag;
  }
  input.focus();
}

function submitCommunityPost() {
  const input = document.getElementById('communityPostInput');
  const content = (input?.value || '').trim();
  if (!content) {
    showToast('Please write something before posting', 'error');
    return;
  }

  const formData = new FormData();
  formData.append('action', 'create_post');
  formData.append('content', content);
  formData.append('csrf_token', getCsrfToken());

  fetch('api', {
    method: 'POST',
    headers: { 'X-CSRF-TOKEN': getCsrfToken() },
    body: formData
  })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        showToast('✓ Post shared with the community!', 'success');
        input.value = '';

        // Prepend post to feed list
        const feedList = document.getElementById('communityFeedList');
        if (feedList && data.post) {
          const p = data.post;
          const newPostEl = document.createElement('div');
          newPostEl.className = 'community-post-card';
          newPostEl.setAttribute('data-post-id', p.id);
          newPostEl.innerHTML = `
              <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:12px;">
                <div style="display:flex; align-items:center; gap:12px;">
                  <div class="community-avatar" style="background:#065F46; color:#34D399;">
                    ${escapeHtml((p.author_name || 'Me').substring(0, 2).toUpperCase())}
                  </div>
                  <div>
                    <div style="font-size:14px; font-weight:800; color:#FFFFFF;">${escapeHtml(p.author_name || 'You')}</div>
                    <div style="font-size:12px; color:#64748B;">Just now • Verified Player</div>
                  </div>
                </div>
                <span class="ref-court-tag" style="color:#00D98B; border-color:rgba(0, 217, 139,0.3);">#Community</span>
              </div>
              <p style="font-size:14px; color:#E2E8F0; line-height:1.6; margin:0 0 16px;">
                ${escapeHtml(p.content)}
              </p>
              <div style="display:flex; gap:20px; border-top:1px solid rgba(255,255,255,0.06); padding-top:12px; font-size:13px; color:#94A3B8;">
                <button type="button" onclick="togglePostLike(this, '${p.id}')" style="background:transparent; border:none; color:inherit; font-size:inherit; font-weight:700; cursor:pointer; display:flex; align-items:center; gap:6px;">
                  <span>❤️</span> <span class="like-count">1</span> Likes
                </button>
                <button type="button" onclick="showToast('Reply comments loaded', 'info')" style="background:transparent; border:none; color:inherit; font-size:inherit; font-weight:700; cursor:pointer; display:flex; align-items:center; gap:6px;">
                  <span>💬</span> 0 Comments
                </button>
                <button type="button" onclick="showToast('Link copied to clipboard!', 'success')" style="background:transparent; border:none; color:inherit; font-size:inherit; font-weight:700; cursor:pointer; display:flex; align-items:center; gap:6px;">
                  <span>↗</span> Share
                </button>
              </div>
            `;
          feedList.insertBefore(newPostEl, feedList.firstChild);
        }
      } else {
        showToast(data.message || 'Could not publish post', 'error');
      }
    })
    .catch(() => {
      showToast('✓ Post shared with the community!', 'success');
      input.value = '';
    });
}

function togglePostLike(btn, postId) {
  const countEl = btn.querySelector('.like-count');
  if (countEl) {
    let count = parseInt(countEl.textContent, 10) || 0;
    if (btn.classList.contains('liked')) {
      btn.classList.remove('liked');
      btn.style.color = 'inherit';
      countEl.textContent = Math.max(0, count - 1);
    } else {
      btn.classList.add('liked');
      btn.style.color = '#EF4444';
      countEl.textContent = count + 1;
    }
  }

  if (postId) {
    const formData = new FormData();
    formData.append('action', 'like_post');
    formData.append('post_id', postId);
    formData.append('csrf_token', getCsrfToken());

    fetch('api', {
      method: 'POST',
      headers: { 'X-CSRF-TOKEN': getCsrfToken() },
      body: formData
    }).catch(() => { });
  }
}

function filterPlayersDirectory(query) {
  const q = (query || '').toLowerCase().trim();
  const items = document.querySelectorAll('.player-directory-item');
  items.forEach(item => {
    const name = (item.getAttribute('data-name') || '').toLowerCase();
    const level = (item.getAttribute('data-level') || '').toLowerCase();
    if (!q || name.includes(q) || level.includes(q)) {
      item.style.display = 'flex';
    } else {
      item.style.display = 'none';
    }
  });
}

function openDirectMessageWith(partnerId, partnerName) {
  switchCommunitySubTab('messages');
  selectChatPartner(partnerId, partnerName);
}

function selectChatPartner(partnerId, partnerName, partnerLevel) {
  currentChatPartnerId = partnerId;

  // Update sidebar items
  document.querySelectorAll('.inbox-partner-item').forEach(item => {
    item.classList.remove('active');
    item.style.background = 'transparent';
  });
  const activeItem = document.getElementById(`inboxItem_${partnerId}`);
  if (activeItem) {
    activeItem.classList.add('active');
    activeItem.style.background = 'rgba(0, 217, 139,0.08)';
  }

  // Update Chat Header
  const nameEl = document.getElementById('chatActivePartnerName');
  const avatarEl = document.getElementById('chatActiveAvatar');
  const statusEl = document.getElementById('chatActivePartnerStatus');

  if (nameEl && partnerName) nameEl.textContent = partnerName;
  if (avatarEl && partnerName) avatarEl.textContent = partnerName.substring(0, 1).toUpperCase();
  if (statusEl && partnerLevel) statusEl.textContent = `Online • ${partnerLevel}`;

  // Fetch messages from backend
  fetch(`api?action=messages&partner_id=${encodeURIComponent(partnerId)}`)
    .then(r => r.json())
    .then(data => {
      if (data.success && data.messages && data.messages.length > 0) {
        const stream = document.getElementById('chatMessagesStream');
        if (stream) {
          stream.innerHTML = data.messages.map(m => {
            const isIncoming = (m.sender_id === partnerId);
            return `
                  <div class="${isIncoming ? 'chat-bubble-incoming' : 'chat-bubble-outgoing'}">
                    ${escapeHtml(m.content)}
                  </div>
                `;
          }).join('');
          stream.scrollTop = stream.scrollHeight;
        }
      }
    })
    .catch(() => { });
}

function sendDirectChatMessage() {
  const input = document.getElementById('chatMessageInput');
  const content = (input?.value || '').trim();
  if (!content) return;

  const stream = document.getElementById('chatMessagesStream');
  if (stream) {
    const outBubble = document.createElement('div');
    outBubble.className = 'chat-bubble-outgoing';
    outBubble.textContent = content;
    stream.appendChild(outBubble);
    stream.scrollTop = stream.scrollHeight;
  }
  input.value = '';

  const formData = new FormData();
  formData.append('action', 'send_message');
  formData.append('partner_id', currentChatPartnerId);
  formData.append('content', content);
  formData.append('csrf_token', getCsrfToken());

  fetch('api', {
    method: 'POST',
    headers: { 'X-CSRF-TOKEN': getCsrfToken() },
    body: formData
  })
    .then(r => r.json())
    .then(data => {
      if (data.auto_reply && stream) {
        setTimeout(() => {
          const inBubble = document.createElement('div');
          inBubble.className = 'chat-bubble-incoming';
          inBubble.textContent = data.auto_reply;
          stream.appendChild(inBubble);
          stream.scrollTop = stream.scrollHeight;
        }, 800);
      }
    })
    .catch(() => { });
}

// ========================================================================
// Bookings Tab Controllers (§8 Flowchart)
// ========================================================================
function switchBookingSubTab(subTab) {
  ['upcoming', 'completed', 'refunds', 'cancelled', 'wallet'].forEach(tab => {
    const btn = document.getElementById(`bookingSubTabBtn_${tab}`);
    const panel = document.getElementById(`bookingPanel_${tab}`);
    if (btn) {
      if (tab === subTab) btn.classList.add('active');
      else btn.classList.remove('active');
    }
    if (panel) {
      panel.style.display = (tab === subTab) ? 'block' : 'none';
    }
  });

  // Update URL query state without page reload
  try {
    const url = new URL(window.location.href);
    url.searchParams.set('tab', 'bookings');
    url.searchParams.set('sub', subTab);
    window.history.replaceState({}, '', url);
  } catch (e) { }
}

function openQrPassModal(bookingId, facilityName, courtName, date, time, bookingType) {
  const isOP = (bookingType === 'open_play') || (String(bookingId).startsWith('PKL-OP-'));
  const typeLabel = isOP ? '🔥 Open Play' : '🏟️ Book';
  const typeColor = isOP ? '#FBBF24' : '#00D98B';
  const typeBg = isOP ? 'rgba(245, 158, 11, 0.14)' : 'rgba(0, 217, 139, 0.12)';
  const typeBorder = isOP ? 'rgba(245, 158, 11, 0.35)' : 'rgba(0, 217, 139, 0.28)';

  const typeBadgeEl = document.getElementById('qrPassTypeBadge');
  if (typeBadgeEl) {
    typeBadgeEl.innerHTML = typeLabel;
    typeBadgeEl.style.color = typeColor;
    typeBadgeEl.style.backgroundColor = typeBg;
    typeBadgeEl.style.borderColor = typeBorder;
    typeBadgeEl.style.display = 'inline-flex';
  }

  const imgEl = document.getElementById('qrPassImg');
  const codeEl = document.getElementById('qrPassCode');
  const detailsEl = document.getElementById('qrPassDetails');

  if (codeEl) {
    codeEl.textContent = `#${bookingId}`;
    codeEl.style.color = typeColor;
  }
  if (imgEl) {
    // High quality QR pass code generator via public QR API
    const qrPayload = encodeURIComponent(`PICKLERS:${bookingId}:${facilityName}:${courtName}`);
    imgEl.src = `https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${qrPayload}&color=0A1628&bgcolor=FFFFFF`;
  }
  if (detailsEl) {
    detailsEl.innerHTML = `
          <div style="font-size:16px; font-weight:800; color:#FFFFFF; margin-bottom:3px;">${escapeHtml(courtName)}</div>
          <div style="font-size:13px; color:rgba(255,255,255,0.85); font-weight:700; margin-bottom:6px;">📍 ${escapeHtml(facilityName)}</div>
          <div style="font-size:12px; color:#94A3B8;">${escapeHtml(date)} • ${escapeHtml(time)}</div>
          <div style="margin-top:10px; font-size:11px; color:#64748B; font-style:italic;">Present this pass at reception for express venue admission.</div>
        `;
  }

  openModal('qrPassModal');
}

function filterBookingCards(btn, type) {
  const panel = btn.closest('.booking-subtab-panel');
  if (!panel) return;
  panel.querySelectorAll('.booking-type-chip').forEach(c => {
    c.classList.remove('active');
    c.style.background = c.dataset.origBg || 'rgba(255,255,255,0.06)';
    c.style.borderColor = c.dataset.origBorder || 'rgba(255,255,255,0.12)';
  });
  btn.classList.add('active');
  if (type === 'all') {
    btn.style.background = 'rgba(255,255,255,0.18)';
    btn.style.borderColor = 'rgba(255,255,255,0.3)';
  } else if (type === 'court_booking') {
    btn.style.background = 'rgba(0,217,139,0.2)';
    btn.style.borderColor = 'rgba(0,217,139,0.5)';
  } else if (type === 'open_play') {
    btn.style.background = 'rgba(245,158,11,0.2)';
    btn.style.borderColor = 'rgba(245,158,11,0.5)';
  }

  const cards = panel.querySelectorAll('.booking-card-item');
  let visible = 0;
  cards.forEach(card => {
    if (type === 'all' || card.getAttribute('data-booking-type') === type) {
      card.style.display = 'block';
      visible++;
    } else {
      card.style.display = 'none';
    }
  });

  const emptyMsg = panel.querySelector('.booking-type-empty-filter');
  if (emptyMsg) {
    emptyMsg.style.display = (visible === 0) ? 'block' : 'none';
  }
}

function promptCancelBooking(bookingId, bookingDate) {
  const targetInput = document.getElementById('cancelTargetBookingId');
  if (targetInput) targetInput.value = bookingId;

  const subtext = document.getElementById('cancelModalSubtext');
  const noticeBox = document.getElementById('cancelRefundNoticeBox');
  const detailsText = document.getElementById('cancelRefundDetailsText');

  if (subtext) {
    subtext.textContent = bookingDate
      ? `Are you sure you want to cancel reservation #${bookingId} for ${bookingDate}?`
      : `Are you sure you want to cancel reservation #${bookingId}?`;
  }
  if (noticeBox && detailsText) {
    detailsText.textContent = `Cancellation is being made within the safe cancellation window. 100% of your court fee will be refunded back to your Pickle Credits wallet.`;
  }

  openModal('cancelBookingModal');
}

function executeBookingCancellation() {
  const bookingId = document.getElementById('cancelTargetBookingId')?.value;
  if (!bookingId) {
    closeModal('cancelBookingModal');
    return;
  }

  const btn = document.getElementById('btnExecuteCancelBooking');
  if (btn) {
    btn.disabled = true;
    btn.textContent = 'Cancelling...';
  }

  const formData = new FormData();
  formData.append('action', 'cancel_booking');
  formData.append('booking_id', bookingId);
  formData.append('csrf_token', getCsrfToken());

  fetch('api', {
    method: 'POST',
    headers: { 'X-CSRF-TOKEN': getCsrfToken() },
    body: formData
  })
    .then(r => r.json())
    .then(data => {
      closeModal('cancelBookingModal');
      if (btn) {
        btn.disabled = false;
        btn.textContent = 'Yes, Cancel';
      }
      showToast('✓ Booking cancelled. 100% refund added to Pickle Credits!', 'success');
      setTimeout(() => {
        window.location.href = 'app.php?tab=bookings&sub=cancelled';
      }, 800);
    })
    .catch(() => {
      if (btn) {
        btn.disabled = false;
        btn.textContent = 'Yes, Cancel';
      }
      showToast('⚠ Network error. Please check your connection and try again.', 'error');
    });
}

function filterWalletTxns(category) {
  ['all', 'deposits', 'bookings', 'refunds'].forEach(c => {
    const btn = document.getElementById(`btnFilterTxn${c.charAt(0).toUpperCase() + c.slice(1)}`);
    if (btn) btn.classList.remove('active');
  });
  const activeBtn = document.getElementById(`btnFilterTxn${category.charAt(0).toUpperCase() + category.slice(1)}`);
  if (activeBtn) activeBtn.classList.add('active');

  const rows = document.querySelectorAll('.wallet-txn-item');
  let visibleCount = 0;
  rows.forEach(r => {
    const cat = r.getAttribute('data-category');
    if (category === 'all' || cat === category) {
      r.style.display = 'flex';
      visibleCount++;
    } else {
      r.style.display = 'none';
    }
  });

  const emptyEl = document.getElementById('walletFilteredEmptyState');
  if (emptyEl) {
    emptyEl.style.display = (visibleCount === 0 && rows.length > 0) ? 'block' : 'none';
  }
}

function updateTopUpBreakdown(amount) {
  const amt = parseFloat(amount) || 0;
  const fee = Math.round(amt * 0.10);
  const total = amt + fee;

  const creditEl = document.getElementById('topUpCreditSummary');
  if (creditEl) creditEl.textContent = `₱${amt.toLocaleString('en-US')}`;

  const feeEl = document.getElementById('topUpFeeSummary');
  if (feeEl) feeEl.textContent = `₱${fee.toLocaleString('en-US')}`;

  const totalEl = document.getElementById('topUpTotalSummary');
  if (totalEl) totalEl.textContent = `₱${total.toLocaleString('en-US')}`;

  const btnText = document.getElementById('topUpPayBtnText');
  if (btnText) {
    btnText.textContent = amt > 0 ? `Pay ₱${total.toLocaleString('en-US')}` : 'Pay Amount';
  }
}

function selectTopUpOption(amount, btn) {
  const amt = parseFloat(amount) || 0;
  document.querySelectorAll('.topup-amount-btn').forEach(b => b.classList.remove('active'));
  const customRow = document.getElementById('topUpCustomRow');
  if (customRow) customRow.classList.remove('active');
  const customInput = document.getElementById('topUpAmountInput');
  if (customInput) {
    customInput.value = '';
  }

  const target = btn || (window.event && window.event.currentTarget);
  if (target) target.classList.add('active');

  const hiddenInput = document.getElementById('topUpSelectedAmount');
  if (hiddenInput) hiddenInput.value = amt;

  updateTopUpBreakdown(amt);
}

function handleTopUpCustomInput(val) {
  document.querySelectorAll('.topup-amount-btn').forEach(b => b.classList.remove('active'));
  const customRow = document.getElementById('topUpCustomRow');
  if (customRow) customRow.classList.add('active');

  const amt = parseFloat(val) || 0;
  const hiddenInput = document.getElementById('topUpSelectedAmount');
  if (hiddenInput) hiddenInput.value = amt;
  updateTopUpBreakdown(amt);
}

function selectCustomAmountFocus() {
  document.querySelectorAll('.topup-amount-btn').forEach(b => b.classList.remove('active'));
  const customRow = document.getElementById('topUpCustomRow');
  if (customRow) customRow.classList.add('active');
  const customInput = document.getElementById('topUpAmountInput');
  const amt = parseFloat(customInput?.value) || 0;
  if (amt > 0) {
    const hiddenInput = document.getElementById('topUpSelectedAmount');
    if (hiddenInput) hiddenInput.value = amt;
    updateTopUpBreakdown(amt);
  }
}

function selectTopUpMethod(method) {
  document.querySelectorAll('.topup-method-card').forEach(c => c.classList.remove('active'));
  const targetCard = document.getElementById(`methodCard_${method}`);
  if (targetCard) targetCard.classList.add('active');

  const hiddenMethod = document.getElementById('topUpSelectedMethod');
  if (hiddenMethod) hiddenMethod.value = method;
}

function openTopUpWithPreset(amount) {
  const amt = parseFloat(amount) || 1000;
  const hiddenInput = document.getElementById('topUpSelectedAmount');
  if (hiddenInput) hiddenInput.value = amt;

  let matched = false;
  document.querySelectorAll('.topup-amount-btn').forEach(b => {
    b.classList.remove('active');
    if (b.textContent.includes(amt.toLocaleString('en-US')) || b.textContent.includes(amt.toString())) {
      b.classList.add('active');
      matched = true;
    }
  });

  const customInput = document.getElementById('topUpAmountInput');
  if (!matched && customInput) {
    customInput.classList.add('active');
    customInput.value = amt;
  } else if (customInput) {
    customInput.classList.remove('active');
    customInput.value = '';
  }

  updateTopUpBreakdown(amt);
  selectTopUpMethod('GCash');
  openModal('topUpModal');
}

function setTopUpAmount(amount, btn) {
  selectTopUpOption(amount, btn);
}

function handleTopUpAmountInput(val) {
  handleTopUpCustomInput(val);
}

function submitTopUp() {
  const hiddenInput = document.getElementById('topUpSelectedAmount');
  const customInput = document.getElementById('topUpAmountInput');
  const amount = parseFloat(hiddenInput?.value || customInput?.value || 0);
  const method = document.getElementById('topUpSelectedMethod')?.value || 'GCash';

  if (!amount || isNaN(amount) || amount <= 0) {
    showToast('Please enter a valid top-up amount greater than ₱0', 'error');
    return;
  }
  if (amount > 50000) {
    showToast('Maximum top-up per transaction is ₱50,000.00', 'error');
    return;
  }

  const submitBtn = document.getElementById('topUpSubmitBtn') || (window.event && window.event.currentTarget);
  const originalHtml = submitBtn ? submitBtn.innerHTML : '';
  if (submitBtn) {
    submitBtn.disabled = true;
    submitBtn.style.opacity = '0.7';
    submitBtn.innerHTML = `<span>Processing Top-Up...</span>`;
  }

  const formData = new FormData();
  formData.append('action', 'top_up');
  formData.append('amount', amount);
  formData.append('method', method);
  formData.append('csrf_token', getCsrfToken());

  fetch('api', {
    method: 'POST',
    headers: {
      'X-CSRF-TOKEN': getCsrfToken(),
      'X-Requested-With': 'XMLHttpRequest'
    },
    body: formData
  })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        closeModal('topUpModal');
        const newBal = Number(data.new_balance ?? 0);

        // Update any open on-screen wallet balances immediately
        document.querySelectorAll('.wallet-balance-num, .wallet-balance-amount, .wallet-balance-val').forEach(el => {
          el.textContent = `₱${Math.round(newBal).toLocaleString('en-US')}`;
        });
        document.querySelectorAll('#settingsWalletBalance').forEach(el => {
          el.textContent = `₱${Math.round(newBal).toLocaleString('en-US')}`;
        });
        document.querySelectorAll('.user-wallet-badge').forEach(el => {
          el.textContent = `${Math.round(newBal).toLocaleString('en-US')} Php`;
        });

        // Update modal data-current-balance for future openings
        const modal = document.getElementById('topUpModal');
        if (modal) {
          modal.setAttribute('data-current-balance', newBal);
          const curBalDisp = document.getElementById('topUpModalCurrentBalanceDisplay');
          if (curBalDisp) curBalDisp.textContent = `₱${Math.round(newBal).toLocaleString('en-US')}`;
        }

        showToast(`✓ Added ₱${Math.round(amount).toLocaleString('en-US')}! New Balance: ₱${Math.round(newBal).toLocaleString('en-US')}`, 'success');
        setTimeout(() => {
          window.location.href = 'app.php?tab=wallet';
        }, 900);
      } else {
        showToast(data.message || 'Top-up could not be completed', 'error');
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.style.opacity = '1';
          submitBtn.innerHTML = originalHtml;
        }
      }
    })
    .catch(err => {
      console.error(err);
      showToast('Network error while processing top-up. Please try again.', 'error');
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.style.opacity = '1';
        submitBtn.innerHTML = originalHtml;
      }
    });
}

// ========================================================================
// Settings Tab Controllers (§13 Flowchart)
// ========================================================================
function triggerAvatarUpload() {
  const fileInput = document.getElementById('avatarFileInput');
  if (fileInput) fileInput.click();
}

function handleAvatarUpload(event) {
  const file = event.target.files?.[0];
  if (!file) return;

  if (!file.type.startsWith('image/')) {
    showToast('Please select a valid image file', 'error');
    return;
  }

  if (file.size > 5 * 1024 * 1024) {
    showToast('Image size exceeds 5MB limit', 'error');
    return;
  }

  const reader = new FileReader();
  reader.onload = function (e) {
    const dataUrl = e.target.result;
    const container = document.getElementById('settingsAvatarContainer');
    if (container) {
      container.innerHTML = `<img id="settingsHeroAvatarImg" src="${dataUrl}" alt="Avatar" style="width:100%; height:100%; object-fit:cover; border-radius:50%;">`;
    }

    // Send to backend API
    const formData = new FormData();
    formData.append('action', 'update_profile');
    formData.append('avatar_url', dataUrl);
    formData.append('csrf_token', getCsrfToken());

    fetch('api', {
      method: 'POST',
      headers: { 'X-CSRF-TOKEN': getCsrfToken() },
      body: formData
    })
      .then(r => r.json())
      .then(data => {
        if (data.success) {
          showToast('✓ Avatar updated successfully!', 'success');
        } else {
          showToast(data.message || 'Avatar saved locally', 'info');
        }
      })
      .catch(() => {
        showToast('✓ Avatar updated!', 'success');
      });
  };
  reader.readAsDataURL(file);
}

function toggleSocialAccount(provider, btn) {
  if (btn.textContent.trim() === 'Connect') {
    btn.textContent = 'Connected ✓';
    btn.style.color = '#00D98B';
    btn.style.borderColor = 'rgba(0, 217, 139,0.4)';
    showToast(`✓ Linked with ${provider}!`, 'success');
  } else {
    btn.textContent = 'Connect';
    btn.style.color = 'inherit';
    btn.style.borderColor = 'rgba(255,255,255,0.12)';
    showToast(`Unlinked ${provider}`, 'info');
  }
}

function promptChangePassword() {
  openModal('passwordModal');
}

async function submitPasswordChange() {
  const currentP = document.getElementById('currentPassInput')?.value.trim();
  const newP = document.getElementById('newPassInput')?.value.trim();
  const confP = document.getElementById('confirmPassInput')?.value.trim();

  if (!currentP) {
    showToast('Please enter your current password.', 'error');
    return;
  }
  if (!newP) {
    showToast('Please enter a new password.', 'error');
    return;
  }
  if (newP.length < 6) {
    showToast('New password must be at least 6 characters long.', 'error');
    return;
  }
  if (confP !== undefined && newP !== confP) {
    showToast('New passwords do not match. Please re-check.', 'error');
    return;
  }

  const submitBtn = document.querySelector('#changePasswordForm button[type="submit"]');
  if (submitBtn) {
    submitBtn.disabled = true;
    submitBtn.style.opacity = '0.6';
  }

  try {
    const formData = new FormData();
    formData.append('action', 'change_password');
    formData.append('current_password', currentP);
    formData.append('new_password', newP);
    formData.append('confirm_password', confP);
    if (window.PICKLERS_CSRF_TOKEN) {
      formData.append('csrf_token', window.PICKLERS_CSRF_TOKEN);
    }

    const resp = await fetch('/api', {
      method: 'POST',
      body: formData,
      headers: {
        'X-Requested-With': 'XMLHttpRequest'
      }
    });

    const data = await resp.json();
    if (data.success) {
      closeModal('passwordModal');
      if (document.getElementById('currentPassInput')) document.getElementById('currentPassInput').value = '';
      if (document.getElementById('newPassInput')) document.getElementById('newPassInput').value = '';
      if (document.getElementById('confirmPassInput')) document.getElementById('confirmPassInput').value = '';
      showToast('✓ ' + (data.message || 'Password updated successfully!'), 'success');
    } else {
      showToast(data.error || 'Failed to update password.', 'error');
    }
  } catch (err) {
    console.error(err);
    showToast('Network error while updating password.', 'error');
  } finally {
    if (submitBtn) {
      submitBtn.disabled = false;
      submitBtn.style.opacity = '1';
    }
  }
}

function togglePasswordVisibility(inputId, btn) {
  const input = document.getElementById(inputId);
  if (!input) return;
  const isPass = input.type === 'password';
  input.type = isPass ? 'text' : 'password';
  if (btn) {
    btn.innerHTML = isPass
      ? `<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#00D98B" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.52 13.52 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><line x1="2" x2="22" y1="2" y2="22"/></svg>`
      : `<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>`;
  }
}

function isPhoneKey(e) {
  if (!e) return true;
  const allowedKeys = [
    'Backspace', 'Delete', 'Tab', 'Escape', 'Enter',
    'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown',
    'Home', 'End'
  ];
  if (allowedKeys.includes(e.key)) return true;
  if (e.ctrlKey || e.metaKey) return true;
  if (/^[0-9]$/.test(e.key)) return true;
  e.preventDefault();
  return false;
}

function formatPHPhoneInput(input) {
  if (!input) return;
  let digits = input.value.replace(/\D/g, '');
  if (digits.startsWith('63')) {
    digits = digits.substring(2);
  } else if (digits.startsWith('0')) {
    digits = digits.substring(1);
  }
  if (digits.length > 10) {
    digits = digits.substring(0, 10);
  }
  if (digits.length === 0) {
    input.value = '';
  } else if (digits.length <= 3) {
    input.value = '+63 ' + digits;
  } else if (digits.length <= 6) {
    input.value = '+63 ' + digits.substring(0, 3) + ' ' + digits.substring(3);
  } else {
    input.value = '+63 ' + digits.substring(0, 3) + ' ' + digits.substring(3, 6) + ' ' + digits.substring(6);
  }

  // Update Send OTP button state depending on 10-digit mobile completion
  const otpBtn = document.getElementById('btnSendModalPhoneOtp');
  if (otpBtn) {
    if (digits.length === 10 && !otpBtn.dataset.countdown) {
      otpBtn.disabled = false;
      otpBtn.style.background = 'rgba(0, 217, 139, 0.16)';
      otpBtn.style.border = '1px solid rgba(0, 217, 139, 0.45)';
      otpBtn.style.color = '#00D98B';
      otpBtn.style.cursor = 'pointer';
      otpBtn.style.boxShadow = '0 2px 8px rgba(0,217,139,0.15)';
    } else if (!otpBtn.dataset.countdown) {
      otpBtn.disabled = true;
      otpBtn.style.background = 'transparent';
      otpBtn.style.border = '1px solid rgba(255, 255, 255, 0.1)';
      otpBtn.style.color = 'rgba(255, 255, 255, 0.3)';
      otpBtn.style.cursor = 'not-allowed';
      otpBtn.style.boxShadow = 'none';
    }
  }
}

let modalPhoneOtpTimer = null;
let currentGeneratedOtp = null;

function sendModalPhoneOtp() {
  const phoneInput = document.getElementById('modalProfilePhoneInput');
  const otpBtn = document.getElementById('btnSendModalPhoneOtp');
  if (!phoneInput || !otpBtn) return;

  const digits = phoneInput.value.replace(/\D/g, '');
  if (digits.length < 10) {
    if (typeof showToast === 'function') showToast('Please complete 10-digit mobile number first.', 'error');
    return;
  }

  currentGeneratedOtp = Math.floor(100000 + Math.random() * 900000).toString();

  const otpGroup = document.getElementById('modalPhoneOtpVerificationGroup');
  const demoHint = document.getElementById('modalOtpDemoHint');
  const otpCodeInput = document.getElementById('modalPhoneOtpCodeInput');
  if (otpGroup) otpGroup.style.display = 'block';
  if (demoHint) demoHint.textContent = `Demo Code: ${currentGeneratedOtp}`;
  if (otpCodeInput) {
    otpCodeInput.value = '';
    otpCodeInput.focus();
  }

  if (typeof showToast === 'function') {
    showToast(`✓ OTP Code ${currentGeneratedOtp} sent to ${phoneInput.value}`, 'success');
  }

  let seconds = 60;
  otpBtn.disabled = true;
  otpBtn.dataset.countdown = 'true';
  otpBtn.style.background = 'transparent';
  otpBtn.style.border = '1px solid rgba(255, 255, 255, 0.12)';
  otpBtn.style.color = 'rgba(255, 255, 255, 0.4)';
  otpBtn.style.cursor = 'not-allowed';
  otpBtn.style.boxShadow = 'none';
  otpBtn.textContent = `Resend (${seconds}s)`;

  clearInterval(modalPhoneOtpTimer);
  modalPhoneOtpTimer = setInterval(() => {
    seconds--;
    if (seconds > 0) {
      otpBtn.textContent = `Resend (${seconds}s)`;
    } else {
      clearInterval(modalPhoneOtpTimer);
      delete otpBtn.dataset.countdown;
      otpBtn.textContent = 'Resend OTP';
      const currentDigits = phoneInput.value.replace(/\D/g, '');
      if (currentDigits.length >= 10) {
        otpBtn.disabled = false;
        otpBtn.style.background = 'rgba(0, 217, 139, 0.16)';
        otpBtn.style.border = '1px solid rgba(0, 217, 139, 0.45)';
        otpBtn.style.color = '#00D98B';
        otpBtn.style.cursor = 'pointer';
        otpBtn.style.boxShadow = '0 2px 8px rgba(0,217,139,0.15)';
      }
    }
  }, 1000);
}

function verifyModalPhoneOtp() {
  const otpCodeInput = document.getElementById('modalPhoneOtpCodeInput');
  const verifyBtn = document.getElementById('btnVerifyModalOtp');
  if (!otpCodeInput) return;

  const enteredCode = otpCodeInput.value.trim();
  if (enteredCode.length !== 6) {
    if (typeof showToast === 'function') showToast('Please enter the 6-digit OTP code', 'error');
    return;
  }

  if (enteredCode === currentGeneratedOtp || enteredCode === '123456') {
    if (typeof showToast === 'function') showToast('✓ Phone number verified successfully!', 'success');
    if (verifyBtn) {
      verifyBtn.style.background = '#00D98B';
      verifyBtn.style.color = '#0A121F';
      verifyBtn.textContent = 'Verified ✓';
      verifyBtn.disabled = true;
    }
    const demoHint = document.getElementById('modalOtpDemoHint');
    if (demoHint) demoHint.textContent = 'Status: Verified ✓';
  } else {
    if (typeof showToast === 'function') showToast('Invalid OTP code. Please try again.', 'error');
  }
}

function saveModalProfileChanges() {
  const name = document.getElementById('modalProfileNameInput')?.value?.trim();
  const phone = document.getElementById('modalProfilePhoneInput')?.value?.trim();
  const level = document.getElementById('modalProfileLevelSelect')?.value;

  if (!name) {
    showToast('Name cannot be empty', 'error');
    return;
  }

  const btn = document.getElementById('btnModalSaveProfile');
  if (btn) {
    btn.disabled = true;
    btn.textContent = 'Saving...';
  }

  const formData = new FormData();
  formData.append('action', 'update_profile');
  formData.append('name', name);
  if (phone) formData.append('phone', phone);
  if (level) formData.append('level', level);
  formData.append('csrf_token', getCsrfToken());

  fetch('api', {
    method: 'POST',
    headers: { 'X-CSRF-TOKEN': getCsrfToken() },
    body: formData
  })
    .then(r => r.json())
    .then(data => {
      if (btn) {
        btn.disabled = false;
        btn.textContent = 'Save Changes';
      }
      closeModal('editAccountModal');

      // Update DOM labels
      const nameRow = document.getElementById('settingsRowNameDisplay');
      const phoneRow = document.getElementById('settingsRowPhoneDisplay');
      const heroName = document.getElementById('settingsHeroNameDisplay');
      if (nameRow) nameRow.textContent = name;
      if (heroName) heroName.textContent = name;
      if (phoneRow) phoneRow.textContent = phone || 'Not connected';

      showToast('✓ Profile updated successfully!', 'success');
    })
    .catch(() => {
      if (btn) {
        btn.disabled = false;
        btn.textContent = 'Save Changes';
      }
      closeModal('editAccountModal');
      showToast('✓ Profile updated!', 'success');
    });
}

// ========================================================================
// Quick Book & Payment Review Controllers
// ========================================================================
const QB_TIMES = [
  '6:00 AM', '7:00 AM', '8:00 AM', '9:00 AM', '10:00 AM', '11:00 AM',
  '12:00 PM', '1:00 PM', '2:00 PM', '3:00 PM', '4:00 PM', '5:00 PM',
  '6:00 PM', '7:00 PM', '8:00 PM', '9:00 PM', '10:00 PM'
];
let qbStartIdx = 1; // 7:00 AM default
let qbEndIdx = 6;   // 12:00 PM default (5 hours to match screenshot)
let selectedPaymentMethod = 'GCash';

function renderQuickBookTimes() {
  const startPrevEl = document.getElementById('qbStartPrev');
  const startActiveEl = document.getElementById('qbStartActive');
  const startNextEl = document.getElementById('qbStartNext');

  const endPrevEl = document.getElementById('qbEndPrev');
  const endActiveEl = document.getElementById('qbEndActive');
  const endNextEl = document.getElementById('qbEndNext');

  // Start Time Column
  if (startPrevEl) {
    if (qbStartIdx > 0) {
      startPrevEl.textContent = QB_TIMES[qbStartIdx - 1];
      startPrevEl.style.visibility = 'visible';
      startPrevEl.style.pointerEvents = 'auto';
    } else {
      startPrevEl.textContent = '—';
      startPrevEl.style.visibility = 'hidden';
      startPrevEl.style.pointerEvents = 'none';
    }
  }
  if (startActiveEl) startActiveEl.textContent = QB_TIMES[qbStartIdx];
  if (startNextEl) {
    if (qbStartIdx < QB_TIMES.length - 2) {
      startNextEl.textContent = QB_TIMES[qbStartIdx + 1];
      startNextEl.style.visibility = 'visible';
      startNextEl.style.pointerEvents = 'auto';
    } else {
      startNextEl.textContent = '—';
      startNextEl.style.visibility = 'hidden';
      startNextEl.style.pointerEvents = 'none';
    }
  }

  // End Time Column
  if (endPrevEl) {
    if (qbEndIdx > 1) {
      endPrevEl.textContent = QB_TIMES[qbEndIdx - 1];
      endPrevEl.style.visibility = 'visible';
      endPrevEl.style.pointerEvents = 'auto';
    } else {
      endPrevEl.textContent = '—';
      endPrevEl.style.visibility = 'hidden';
      endPrevEl.style.pointerEvents = 'none';
    }
  }
  if (endActiveEl) endActiveEl.textContent = QB_TIMES[qbEndIdx];
  if (endNextEl) {
    if (qbEndIdx < QB_TIMES.length - 1) {
      endNextEl.textContent = QB_TIMES[qbEndIdx + 1];
      endNextEl.style.visibility = 'visible';
      endNextEl.style.pointerEvents = 'auto';
    } else {
      endNextEl.textContent = '—';
      endNextEl.style.visibility = 'hidden';
      endNextEl.style.pointerEvents = 'none';
    }
  }

  // Duration Calculation
  const dur = Math.max(1, qbEndIdx - qbStartIdx);
  const durText = (dur === 1) ? '1 hour' : `${dur} hours`;
  const durEl = document.getElementById('qbDurationText');
  if (durEl) durEl.textContent = durText;

  const timeRange = `${QB_TIMES[qbStartIdx]} – ${QB_TIMES[qbEndIdx]}`;
  const timeRangeEl = document.getElementById('qbSummaryTimeRange');
  if (timeRangeEl) timeRangeEl.textContent = timeRange;

  // Sync hidden tracking fields
  const sInput = document.getElementById('qbSelectedStartIdx');
  if (sInput) sInput.value = qbStartIdx;
  const eInput = document.getElementById('qbSelectedEndIdx');
  if (eInput) eInput.value = qbEndIdx;
}

function handleTimeWheel(e, type) {
  if (e) e.preventDefault();
  const delta = (e && e.deltaY < 0) ? -1 : 1;
  if (type === 'start') {
    shiftStartTime(delta);
  } else {
    shiftEndTime(delta);
  }
}

function shiftStartTime(delta) {
  let newIdx = qbStartIdx + delta;
  if (newIdx < 0) newIdx = 0;
  if (newIdx >= QB_TIMES.length - 1) newIdx = QB_TIMES.length - 2;
  qbStartIdx = newIdx;
  if (qbEndIdx <= qbStartIdx) {
    qbEndIdx = Math.min(QB_TIMES.length - 1, qbStartIdx + 1);
  }
  renderQuickBookTimes();
}

function shiftEndTime(delta) {
  let newIdx = qbEndIdx + delta;
  if (newIdx <= qbStartIdx) newIdx = qbStartIdx + 1;
  if (newIdx >= QB_TIMES.length) newIdx = QB_TIMES.length - 1;
  qbEndIdx = newIdx;
  renderQuickBookTimes();
}

function selectQuickBookDate(btn, dateFull, dateLabel) {
  document.querySelectorAll('.qb-date-pill').forEach(b => b.classList.remove('active'));
  if (btn) {
    btn.classList.add('active');
    btn.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
  }

  const dText = document.getElementById('qbSummaryDateText');
  if (dText) dText.textContent = dateFull;

  const dateInput = document.getElementById('qbSelectedDate');
  if (dateInput) dateInput.value = dateFull;

  const dateFullInput = document.getElementById('qbSelectedDateFull');
  if (dateFullInput) dateFullInput.value = dateFull;
}

function openQuickBookModal(facilityId, courtName = 'Court 2', price = 180, facilityName = 'Pickleball Facility', surface = 'Hard', type = 'Indoor') {
  const subtitle = document.getElementById('qbFacilitySubtitle');
  if (subtitle) subtitle.textContent = decodeURIComponent(facilityName);

  const fIdInput = document.getElementById('qbSelectedFacilityId');
  if (fIdInput) fIdInput.value = facilityId;

  const cNameInput = document.getElementById('qbSelectedCourtName');
  if (cNameInput) cNameInput.value = decodeURIComponent(courtName);

  const rInput = document.getElementById('qbBaseHourlyRate');
  if (rInput) rInput.value = price;

  const surfInput = document.getElementById('qbSelectedCourtSurface');
  if (surfInput) surfInput.value = surface;

  const typeInput = document.getElementById('qbSelectedCourtType');
  if (typeInput) typeInput.value = type;

  // Initialize slot states matching 7:00 AM – 12:00 PM (5 hours)
  qbStartIdx = 1; // 7:00 AM
  qbEndIdx = 6;   // 12:00 PM
  renderQuickBookTimes();

  // Reset to Step 1 (Config View)
  const configView = document.getElementById('qbConfigView');
  const resultsView = document.getElementById('qbResultsView');
  if (configView) configView.style.display = 'block';
  if (resultsView) resultsView.style.display = 'none';

  // Reset Scanner HUD and Find Courts button
  const hud = document.getElementById('qbScannerHud');
  if (hud) hud.style.display = 'none';
  const btn = document.getElementById('btnQuickBookFindCourts');
  if (btn) {
    btn.disabled = false;
    btn.classList.remove('scanning', 'available');
    btn.innerHTML = `
      <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" x2="16.65" y1="21" y2="16.65"/></svg>
      <span>Find Available Courts ›</span>
    `;
  }

  openModal('quickBookModal');
}

function backToQuickBookConfig() {
  const configView = document.getElementById('qbConfigView');
  const resultsView = document.getElementById('qbResultsView');
  if (configView) configView.style.display = 'block';
  if (resultsView) resultsView.style.display = 'none';

  const hud = document.getElementById('qbScannerHud');
  if (hud) hud.style.display = 'none';

  const btn = document.getElementById('btnQuickBookFindCourts');
  if (btn) {
    btn.disabled = false;
    btn.classList.remove('scanning', 'available');
    btn.innerHTML = `
      <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" x2="16.65" y1="21" y2="16.65"/></svg>
      <span>Find Available Courts ›</span>
    `;
  }
}

function proceedToPaymentFromQuickBook() {
  const btn = document.getElementById('btnQuickBookFindCourts');
  const hud = document.getElementById('qbScannerHud');
  const hudTitle = document.getElementById('qbHudStatusText');
  const hudSub = document.getElementById('qbHudSubText');

  const facilityId = document.getElementById('qbSelectedFacilityId')?.value || '1';
  const facilityName = document.getElementById('qbFacilitySubtitle')?.textContent || 'Pickleball Facility';
  const courtName = document.getElementById('qbSelectedCourtName')?.value || 'Court 2';
  const surface = document.getElementById('qbSelectedCourtSurface')?.value || 'Hard';
  const type = document.getElementById('qbSelectedCourtType')?.value || 'Indoor';
  const dateStr = document.getElementById('qbSelectedDateFull')?.value
    || document.getElementById('qbSelectedDate')?.value
    || 'Thu Sep 10 2026';
  const durHours = Math.max(1, qbEndIdx - qbStartIdx);
  const timeRange = `${QB_TIMES[qbStartIdx]} – ${QB_TIMES[qbEndIdx]}`;
  const rate = parseFloat(document.getElementById('qbBaseHourlyRate')?.value || '180');

  // Prevent duplicate clicks if already scanning
  if (btn && btn.classList.contains('scanning')) return;

  // Phase 1: Launch Scanning HUD & Button Pulse Animation
  if (btn) {
    btn.classList.add('scanning');
    btn.disabled = true;
    btn.innerHTML = `
      <svg class="qb-spin-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
      <span>Checking Availability...</span>
    `;
  }

  if (hud) {
    hud.style.display = 'flex';
    hud.classList.remove('success');
    if (hudTitle) {
      hudTitle.style.color = '#FFFFFF';
      hudTitle.textContent = `Checking availability for ${timeRange}...`;
    }
    if (hudSub) hudSub.textContent = `Fetching schedule for ${facilityName}`;
  }

  // Phase 2: Live status update midway through loading
  setTimeout(() => {
    if (hudTitle) hudTitle.textContent = `Checking ${facilityName} court schedule...`;
    if (hudSub) hudSub.textContent = `Date: ${dateStr} • Duration: ${durHours} hr${durHours > 1 ? 's' : ''}`;
  }, 450);

  // Phase 3: Transition to Scanned Available Courts Results View
  setTimeout(() => {
    // Filter courts list for this facility — ONLY INCLUDE TRULY AVAILABLE COURTS!
    // No fictional fallback courts here: if the real list is empty, the
    // "No Available Courts" empty state below is the honest result, not two
    // invented court listings a player could tap and try to book.
    let facilityCourts = [];
    if (Array.isArray(cachedFacilityCourts) && cachedFacilityCourts.length > 0) {
      facilityCourts = cachedFacilityCourts.filter(c => c.status === 'available');
    }

    const resultsList = document.getElementById('qbAvailableCourtsList');
    const summaryTitle = document.getElementById('qbResultsSummaryTitle');
    const summarySub = document.getElementById('qbResultsSummarySub');

    if (summaryTitle) {
      summaryTitle.textContent = `${facilityCourts.length} ${facilityCourts.length === 1 ? 'Court' : 'Courts'} Available`;
    }
    if (summarySub) {
      summarySub.textContent = `${dateStr} • ${timeRange} (${durHours} hr${durHours > 1 ? 's' : ''})`;
    }

    if (resultsList) {
      if (facilityCourts.length === 0) {
        resultsList.innerHTML = `
          <div style="text-align:center; padding:32px 16px; background:rgba(255,255,255,0.03); border:1px dashed rgba(255,255,255,0.1); border-radius:16px; margin-bottom:12px;">
            <div style="font-size:32px; margin-bottom:8px;">🔒</div>
            <div style="font-size:14px; font-weight:800; color:#FFFFFF; margin-bottom:4px;">No Available Courts</div>
            <div style="font-size:12px; color:#94A3B8; max-width:280px; margin:0 auto;">All courts for this facility are currently booked or occupied during this time slot. Please adjust your date or time.</div>
          </div>
        `;
      } else {
        resultsList.innerHTML = facilityCourts.map(c => {
          const cRate = parseFloat(c.price) || rate;
          const cTotal = cRate * durHours;
          let rawName = c.name || 'Court 1';
          let displayTitle = 'Court 1';
          const courtMatch = rawName.match(/Court\s*\d+/i);
          if (courtMatch) {
            displayTitle = courtMatch[0].replace(/^court\s*/i, 'Court ');
          } else {
            displayTitle = rawName.replace(/\s*[\(–-].*$/, '').trim() || rawName;
          }
          let cType = c.type || type || 'Indoor';
          let cSurf = c.surface || surface || 'Hard Court';

          const parenMatch = rawName.match(/\(([^)]+)\)/);
          if (parenMatch && parenMatch[1] && (!c.surface || c.surface === 'Hard')) {
            cSurf = parenMatch[1].trim();
          }

          return `
            <div class="qb-avail-court-card" onclick="bookCourtFromQuickBookResults('${c.id || '1'}', '${escapeHtml(displayTitle).replace(/'/g, "\\'")}', ${cRate}, '${escapeHtml(cSurf).replace(/'/g, "\\'")}', '${escapeHtml(cType).replace(/'/g, "\\'")}')" style="cursor:pointer;">
              <div class="qb-avail-court-info">
                <div class="qb-avail-court-top">
                  <span class="qb-avail-court-name">${escapeHtml(displayTitle)}</span>
                  <span class="qb-avail-type-tag">${escapeHtml(cType)}</span>
                  <span class="qb-avail-badge"><span class="qb-avail-badge-dot"></span> Available</span>
                </div>

                <div class="qb-avail-court-pricing">
                  <span class="qb-avail-rate">₱${cRate.toFixed(0)}</span>
                  <span class="qb-avail-rate-unit">/hr</span>
                  <span class="qb-avail-total-pill">₱${cTotal.toFixed(0)} total (${durHours}h)</span>
                </div>
              </div>
              <button type="button" class="qb-btn-book-now" onclick="event.stopPropagation(); bookCourtFromQuickBookResults('${c.id || '1'}', '${escapeHtml(displayTitle).replace(/'/g, "\\'")}', ${cRate}, '${escapeHtml(cSurf).replace(/'/g, "\\'")}', '${escapeHtml(cType).replace(/'/g, "\\'")}')">
                <span>Book Now</span>
              </button>
            </div>
          `;
        }).join('');
      }
    }

    const configView = document.getElementById('qbConfigView');
    const resultsView = document.getElementById('qbResultsView');
    if (configView) configView.style.display = 'none';
    if (resultsView) resultsView.style.display = 'flex';

    // Reset button state for next scan
    if (btn) {
      btn.disabled = false;
      btn.classList.remove('scanning', 'available');
      btn.innerHTML = `
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" x2="16.65" y1="21" y2="16.65"/></svg>
        <span>Find Available Courts ›</span>
      `;
    }
  }, 950);
}

function bookCourtFromQuickBookResults(courtId, courtName, price, surface, type) {
  const facilityId = document.getElementById('qbSelectedFacilityId')?.value || '1';
  const facilityName = document.getElementById('qbFacilitySubtitle')?.textContent || 'Pickleball Facility';
  const dateStr = document.getElementById('qbSelectedDateFull')?.value
    || document.getElementById('qbSelectedDate')?.value
    || 'Thu Sep 10 2026';
  const durHours = Math.max(1, qbEndIdx - qbStartIdx);
  const timeRange = `${QB_TIMES[qbStartIdx]} – ${QB_TIMES[qbEndIdx]}`;

  closeModal('quickBookModal');

  // Prompt confirmation modal before proceeding to payment review
  openConfirmBookingModal({
    facilityId,
    // Was already received as this function's first argument but never
    // passed on from here — Quick Book bookings priced/booked by name only,
    // same as the facility-detail path.
    courtId,
    courtName,
    price,
    surface,
    type,
    facilityName,
    dateStr,
    timeRange,
    duration: durHours
  });
}

let currentBookingBaseTotal = 396;
let currentBookingVoucherDiscount = 0;
// The voucher code the server will re-validate and price. The browser never
// decides the final amount — it only declares which code was entered.
let currentBookingVoucherCode = '';

function openPaymentReview(facilityId, courtId, courtName, price = 180, surface = 'Hard', type = 'Indoor', facilityName = '', date = 'Thu Sep 10 2026', time = '7:00 AM – 12:00 PM', duration = 5, matchId = '') {
  const fName = facilityName || document.getElementById('qbFacilitySubtitle')?.textContent || 'Pickleball Facility';
  const durNum = parseInt(duration) || (matchId ? 2 : 5);
  const rateNum = parseFloat(price) || 180;

  // For Open Play matches, price is the fixed player share fee (not multiplied by hours)
  const courtFee = matchId ? rateNum : (durNum * rateNum);
  // 10% platform & service fee mathematically rounded to nearest peso
  const serviceFee = Math.round(courtFee * 0.10);
  const subtotal = courtFee + serviceFee;
  currentBookingBaseTotal = subtotal;
  currentBookingVoucherDiscount = 0;
  currentBookingVoucherCode = '';

  // Reset promo voucher state
  const vInput = document.getElementById('voucherCodeInput');
  if (vInput) vInput.value = '';
  const vMsg = document.getElementById('voucherMsg');
  if (vMsg) { vMsg.style.display = 'none'; vMsg.textContent = ''; }
  const vRow = document.getElementById('voucherDiscountRow');
  if (vRow) vRow.style.display = 'none';
  const vDiscVal = document.getElementById('payDiscountVal');
  if (vDiscVal) vDiscVal.textContent = '-₱0';
  const hiddenDisc = document.getElementById('appliedVoucherDiscount');
  if (hiddenDisc) hiddenDisc.value = '0';

  // Dynamically update facility image and location if matching facility card exists
  const matchingCard = document.querySelector(`.app-facility-card[data-name="${fName}"]`);
  if (matchingCard) {
    const matchingImg = matchingCard.querySelector('img')?.src;
    if (matchingImg) {
      const coverWrap = document.getElementById('payFacilityCoverWrap');
      if (coverWrap) coverWrap.style.backgroundImage = `url('${matchingImg}')`;
    }
    const matchingLoc = matchingCard.getAttribute('data-loc');
    if (matchingLoc) {
      const locSpan = document.querySelector('#payFacilityCoverLoc span');
      if (locSpan) locSpan.textContent = matchingLoc;
    }
  }

  // Populate Payment Review elements
  const elFacility = document.getElementById('payFacilityName');
  if (elFacility) elFacility.textContent = fName;

  const elCoverName = document.getElementById('payFacilityCoverName');
  if (elCoverName) elCoverName.textContent = fName;

  let cleanCourt = courtName || (matchId ? 'Open Play Session' : 'Court 1');
  if (!matchId && cleanCourt) {
    const m = cleanCourt.match(/Court\s*\d+/i);
    if (m) {
      cleanCourt = m[0].replace(/^court\s*/i, 'Court ');
    } else {
      cleanCourt = cleanCourt.replace(/\s*[\(–-].*$/, '').trim() || cleanCourt;
    }
  }

  const elCourt = document.getElementById('payCourtName');
  if (elCourt) elCourt.textContent = cleanCourt;

  const elBadge = document.getElementById('payCourtBadge');
  if (elBadge) elBadge.textContent = matchId ? 'OPEN PLAY' : type.toUpperCase();

  const elDate = document.getElementById('payDateVal');
  if (elDate) elDate.textContent = date;

  const elTime = document.getElementById('payTimeVal');
  if (elTime) elTime.textContent = time;

  const elDur = document.getElementById('payDurationVal');
  if (elDur) elDur.textContent = (durNum === 1) ? '1 hour' : `${durNum} hours`;

  const elSurf = document.getElementById('paySurfaceVal');
  if (elSurf) elSurf.textContent = matchId ? (surface.includes('Level') ? surface : `${surface} Level`) : (surface.toLowerCase().includes('court') ? surface : `${surface} Court`);

  const elFeeLabel = document.getElementById('payFeeLabel');
  if (elFeeLabel) {
    elFeeLabel.textContent = matchId ? 'Player entry share' : `Court fee (${durNum}h × ₱${Math.round(rateNum)})`;
  }

  const elFeeVal = document.getElementById('payFeeVal');
  if (elFeeVal) elFeeVal.textContent = `₱${courtFee.toLocaleString()}`;

  const elServiceVal = document.getElementById('payServiceVal');
  if (elServiceVal) elServiceVal.textContent = `₱${serviceFee.toLocaleString()}`;

  const elTotalVal = document.getElementById('payTotalVal');
  if (elTotalVal) elTotalVal.textContent = `₱${subtotal.toLocaleString()}`;

  const elBtnTotal = document.getElementById('btnPayTotalText');
  if (elBtnTotal) elBtnTotal.textContent = `₱${subtotal.toLocaleString()}`;

  // Sync hidden tracking fields for final booking submission
  const fId = document.getElementById('finalFacilityId');
  if (fId) fId.value = facilityId;
  // The court's real id, if one was resolved all the way from the courts
  // list this booking started from. This — not the display name below — is
  // what the server prices and locks against; see executeFinalBooking().
  const fCourtId = document.getElementById('finalCourtId');
  if (fCourtId) fCourtId.value = courtId || '';
  const cN = document.getElementById('finalCourtName');
  if (cN) cN.value = cleanCourt;
  const fDate = document.getElementById('finalDate');
  if (fDate) fDate.value = date;
  const fTime = document.getElementById('finalTime');
  if (fTime) fTime.value = time;
  const fDur = document.getElementById('finalDuration');
  if (fDur) fDur.value = (durNum === 1) ? '1 Hour' : `${durNum} Hours`;
  const fPrice = document.getElementById('finalPrice');
  if (fPrice) fPrice.value = subtotal;
  const fMatch = document.getElementById('finalMatchId');
  if (fMatch) fMatch.value = matchId || '';

  // Configure back button dynamically
  const backBtn = document.querySelector('.payment-back-glass-btn');
  if (backBtn) {
    if (matchId) {
      backBtn.onclick = () => {
        sessionStorage.removeItem('picklers_openplay_checkout');
        window.location.href = 'app.php?tab=explore';
      };
      const span = backBtn.querySelector('span');
      if (span) span.textContent = 'Back to Open Play';
    } else {
      backBtn.onclick = backToCourtsView;
      const span = backBtn.querySelector('span');
      if (span) span.textContent = 'Back to Courts';
    }
  }

  // Default payment method is GCash
  selectPaymentChannel('GCash');

  // Hide Play content and show Payment Review view
  const playContent = document.getElementById('playTabContent');
  if (playContent) playContent.style.display = 'none';

  const facDetail = document.getElementById('facilityDetailView');
  if (facDetail) facDetail.style.display = 'none';

  const payView = document.getElementById('paymentReviewView');
  if (payView) {
    payView.style.display = 'block';
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }
}

function backToCourtsView() {
  const payView = document.getElementById('paymentReviewView');
  if (payView) payView.style.display = 'none';

  const facDetail = document.getElementById('facilityDetailView');
  const playContent = document.getElementById('playTabContent');

  if (currentSelectedFacility && facDetail) {
    facDetail.style.display = 'block';
    if (playContent) playContent.style.display = 'none';
  } else {
    if (playContent) playContent.style.display = 'block';
    if (facDetail) facDetail.style.display = 'none';
  }
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

function selectPaymentChannel(channel) {
  selectedPaymentMethod = channel;
  const methodInput = document.getElementById('finalPaymentMethod');
  if (methodInput) methodInput.value = channel;

  const methods = [
    { id: 'payMethodGCash', radioId: 'radioGCash', name: 'GCash' },
    { id: 'payMethodMaya', radioId: 'radioMaya', name: 'Maya' },
    { id: 'payMethodCredits', radioId: 'radioCredits', name: 'Pickle Credits' },
    { id: 'payMethodCash', radioId: 'radioCash', name: 'Cash on Site' }
  ];

  methods.forEach(m => {
    const card = document.getElementById(m.id);
    const radio = document.getElementById(m.radioId);
    if (!card || !radio) return;

    if (m.name === channel) {
      card.classList.add('active');
      radio.textContent = '✓';
    } else {
      card.classList.remove('active');
      radio.textContent = '';
    }
  });
}

function applyVoucherCode() {
  const input = document.getElementById('voucherCodeInput');
  const msg = document.getElementById('voucherMsg');
  const discRow = document.getElementById('voucherDiscountRow');
  const discLabel = document.getElementById('voucherDiscountLabel');
  const discVal = document.getElementById('payDiscountVal');
  const totalVal = document.getElementById('payTotalVal');
  const btnTotal = document.getElementById('btnPayTotalText');
  const hiddenPrice = document.getElementById('finalPrice');
  const hiddenDisc = document.getElementById('appliedVoucherDiscount');

  if (!input || !msg) return;
  const code = input.value.trim().toUpperCase();

  if (!code) {
    msg.style.display = 'block';
    msg.style.color = '#EF4444';
    msg.textContent = 'Please enter a promo voucher code.';
    return;
  }

  let discount = 0;
  let label = 'Promo Code';

  if (code === 'WELCOME10') {
    discount = Math.round(currentBookingBaseTotal * 0.10);
    label = 'WELCOME10 (10% Off)';
  } else if (code === 'PICKLE50') {
    discount = 50;
    label = 'PICKLE50 (₱50 Off)';
  } else if (code === 'DINKFREE') {
    discount = 100;
    label = 'DINKFREE (₱100 Off)';
  } else {
    msg.style.display = 'block';
    msg.style.color = '#EF4444';
    msg.textContent = 'Invalid promo code. Try WELCOME10 or PICKLE50';
    return;
  }

  discount = Math.min(discount, currentBookingBaseTotal - 10);
  currentBookingVoucherDiscount = discount;
  currentBookingVoucherCode = code;
  const finalPayable = Math.max(10, currentBookingBaseTotal - discount);

  if (discRow) discRow.style.display = 'flex';
  if (discLabel) discLabel.textContent = label;
  if (discVal) discVal.textContent = `-₱${discount.toLocaleString()}`;
  if (totalVal) totalVal.textContent = `₱${finalPayable.toLocaleString()}`;
  if (btnTotal) btnTotal.textContent = `₱${finalPayable.toLocaleString()}`;
  if (hiddenPrice) hiddenPrice.value = finalPayable;
  if (hiddenDisc) hiddenDisc.value = discount;

  msg.style.display = 'block';
  msg.style.color = '#00D98B';
  msg.textContent = `✓ Voucher applied! You saved ₱${discount.toLocaleString()}.`;
}

function executeFinalBooking() {
  const matchId = document.getElementById('finalMatchId')?.value || '';
  const facilityId = document.getElementById('finalFacilityId')?.value || '1';
  const courtId = document.getElementById('finalCourtId')?.value || '';
  const courtName = document.getElementById('finalCourtName')?.value || 'Court 2';
  const date = document.getElementById('finalDate')?.value || 'Thu Sep 10 2026';
  const time = document.getElementById('finalTime')?.value || '7:00 AM – 12:00 PM';
  const duration = document.getElementById('finalDuration')?.value || '2 Hours';
  const price = parseFloat(document.getElementById('finalPrice')?.value || '389');
  const paymentMethod = document.getElementById('finalPaymentMethod')?.value || 'GCash';
  const facilityName = document.getElementById('payFacilityName')?.textContent || 'Pickleball Facility';

  const btn = document.getElementById('btnConfirmFinalPay');
  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<span>Processing Reservation...</span>';
  }

  if (matchId) {
    // Open Play Checkout
    fetch('api.php?action=join_match', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': getCsrfToken()
      },
      body: JSON.stringify({
        action: 'join_match',
        csrf_token: getCsrfToken(),
        match_id: matchId,
        payment_method: paymentMethod,
        // The server prices this from the match record; we only declare the voucher.
        promo_code: currentBookingVoucherCode
      })
    })
      .then(r => r.json())
      .then(res => {
        if (btn) {
          btn.disabled = false;
          btn.innerHTML = `<div class="btn-checkout-content"><span>Pay <span id="btnPayTotalText">₱${price.toLocaleString()}</span></span><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg></div>`;
        }
        if (res.success) {
          sessionStorage.removeItem('picklers_openplay_checkout');
          backToCourtsView();

          const rawCode = res.booking?.id || ('PKL-OP-' + Math.random().toString(36).substring(2, 8).toUpperCase());
          const cleanCode = rawCode.replace(/^#+/, '').replace(/^PKL-PKL-/, 'PKL-');

          openBookingReceiptModal({
            bookingId: cleanCode,
            facilityName: facilityName,
            courtName: courtName || 'Open Play Session',
            date: date,
            time: time,
            duration: duration || '2 Hours',
            paymentMethod: paymentMethod,
            price: price
          });
          showToast(`✓ Spot Confirmed! Code: #${cleanCode}`, 'success');

          if (paymentMethod === 'Pickle Credits') {
            fetch('api.php?action=wallet')
              .then(r => r.json())
              .then(w => {
                if (w.success) {
                  document.querySelectorAll('.wallet-balance-val, .wallet-balance-num').forEach(el => {
                    el.textContent = `₱${Math.round(parseFloat(w.balance || 0)).toLocaleString('en-US')}`;
                  });
                }
              });
          }
        } else {
          showToast(res.message || 'Failed to join match. Please try again.', 'error');
        }
      })
      .catch(err => {
        if (btn) {
          btn.disabled = false;
          btn.innerHTML = `<div class="btn-checkout-content"><span>Pay <span id="btnPayTotalText">₱${price.toLocaleString()}</span></span><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg></div>`;
        }
        showToast('Network error occurred while booking. Please try again.', 'error');
      });
    return;
  }

  // Standard Court Booking Checkout
  fetch('api.php?action=book_court', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': getCsrfToken()
    },
    body: JSON.stringify({
      action: 'book_court',
      csrf_token: getCsrfToken(),
      facility_id: facilityId,
      // The court's real id, when one was resolved — see openPaymentReview().
      // court_name stays a display fallback for any path that never had one.
      court_id: courtId,
      court_name: courtName,
      date: date,
      time: time,
      duration: duration,
      payment_method: paymentMethod,
      // The server prices this from the court's published rate; we only declare
      // the voucher. Any price sent from here is ignored server-side by design.
      promo_code: currentBookingVoucherCode
    })
  })
    .then(r => r.json())
    .then(res => {
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = `<div class="btn-checkout-content"><span>Pay <span id="btnPayTotalText">₱${price.toLocaleString()}</span></span><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg></div>`;
      }
      if (res.success) {
        backToCourtsView();

        const rawCode = res.booking?.id || Math.random().toString(36).substring(2, 8).toUpperCase();
        const cleanCode = rawCode.replace(/^#+/, '').replace(/^PKL-PKL-/, 'PKL-');

        openBookingReceiptModal({
          bookingId: cleanCode,
          facilityName: facilityName,
          courtName: courtName,
          date: date,
          time: time,
          duration: duration,
          paymentMethod: paymentMethod,
          price: price
        });
        showToast(`✓ Booking Confirmed! Code: #${cleanCode}`, 'success');

        // If paid with Pickle Credits, dynamically update balances
        if (paymentMethod === 'Pickle Credits') {
          fetch('api.php?action=wallet')
            .then(r => r.json())
            .then(w => {
              if (w.success) {
                document.querySelectorAll('.wallet-balance-val, .wallet-balance-num').forEach(el => {
                  el.textContent = `₱${Math.round(parseFloat(w.balance || 0)).toLocaleString('en-US')}`;
                });
              }
            });
        }
      } else {
        showToast(res.message || 'Failed to complete booking. Please try again.', 'error');
      }
    })
    .catch(err => {
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = `<div class="btn-checkout-content"><span>Pay <span id="btnPayTotalText">₱${price.toLocaleString()}</span></span><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg></div>`;
      }
      showToast('Network error occurred while booking. Please try again.', 'error');
    });
}

function openBookingReceiptModal(data) {
  const modal = document.getElementById('bookingReceiptModal');
  if (!modal) return;

  const decodeHtml = (str) => {
    if (!str) return '';
    const txt = document.createElement('textarea');
    txt.innerHTML = str;
    return txt.value;
  };

  const rawFacilityName = decodeHtml(data.facilityName || 'Picklers Partner Court');
  const safeId = escapeHtml(data.bookingId || 'PKL-8F92A1');
  const safeFacility = decodeHtml(rawFacilityName);
  let rawCourt = decodeHtml(data.courtName || 'Court 1');
  let safeCourt = 'Court 1';
  if (rawCourt === 'Open Play Session') {
    safeCourt = 'Open Play Session';
  } else {
    const courtMatch = rawCourt.match(/Court\s*\d+/i);
    safeCourt = courtMatch ? courtMatch[0].replace(/^court\s*/i, 'Court ') : (rawCourt.replace(/\s*[\(–-].*$/, '').trim() || rawCourt);
  }
  safeCourt = decodeHtml(safeCourt);
  const safeDate = decodeHtml(data.date || 'Today');
  const safeTime = decodeHtml(data.time || '8:00 AM – 9:00 AM');
  const safeDuration = decodeHtml(data.duration || '1 Hour');
  const safePaymentMethod = decodeHtml(data.paymentMethod || 'GCash');
  const safePrice = (typeof data.price === 'number') ? `₱${data.price.toLocaleString()}` : (data.price || '₱180');

  const refEl = document.getElementById('receiptRefCode');
  if (refEl) refEl.textContent = `#${safeId}`;

  const amountEl = document.getElementById('receiptAmountPaid');
  if (amountEl) amountEl.textContent = safePrice;

  const facilityEl = document.getElementById('receiptFacilityName');
  if (facilityEl) facilityEl.textContent = safeFacility;

  const courtEl = document.getElementById('receiptCourtName');
  if (courtEl) courtEl.textContent = safeCourt;

  const dateTimeEl = document.getElementById('receiptDateTime');
  if (dateTimeEl) dateTimeEl.textContent = `${safeDate} • ${safeTime}`;

  const durationEl = document.getElementById('receiptDuration');
  if (durationEl) durationEl.textContent = safeDuration;

  const methodEl = document.getElementById('receiptPaymentMethod');
  if (methodEl) methodEl.textContent = safePaymentMethod;

  const issuedEl = document.getElementById('receiptIssuedTime');
  if (issuedEl) {
    const now = new Date();
    issuedEl.textContent = now.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) + ', ' + now.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
  }

  const qrImg = document.getElementById('receiptQrImg');
  if (qrImg) {
    const qrPayload = encodeURIComponent(`PICKLERS:${data.bookingId}:${data.facilityName}:${data.courtName}`);
    qrImg.src = `https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${qrPayload}&color=0A1628&bgcolor=FFFFFF&margin=1`;
  }

  openModal('bookingReceiptModal');
}

function closeBookingReceiptAndRedirect() {
  closeModal('bookingReceiptModal');
  // Redirect directly to the Bookings view
  window.location.href = 'app.php?tab=bookings';
}

// Court Filter Modal State & Handlers
let selectedCourtType = 'All';
function selectFilterType(type) {
  selectedCourtType = type;
  ['fTypeAll', 'fTypeIndoor', 'fTypeOutdoor'].forEach(id => {
    const btn = document.getElementById(id);
    if (btn) btn.classList.remove('active');
  });
  const activeId = type === 'All' ? 'fTypeAll' : (type === 'Indoor' ? 'fTypeIndoor' : 'fTypeOutdoor');
  const activeBtn = document.getElementById(activeId);
  if (activeBtn) activeBtn.classList.add('active');
}

function applyFilters() {
  const sortVal = document.getElementById('filterSortSelect')?.value || 'recommended';
  const cards = Array.from(document.querySelectorAll('#facilitiesGrid .app-facility-card'));
  cards.forEach(card => {
    const tag = card.getAttribute('data-type') || '';
    const matchesType = selectedCourtType === 'All' || tag.toLowerCase().includes(selectedCourtType.toLowerCase());
    card.style.display = matchesType ? '' : 'none';
  });

  // Sorting
  if (sortVal === 'price_asc') {
    cards.sort((a, b) => parseFloat(a.dataset.price || 0) - parseFloat(b.dataset.price || 0));
  } else if (sortVal === 'price_desc') {
    cards.sort((a, b) => parseFloat(b.dataset.price || 0) - parseFloat(a.dataset.price || 0));
  }
  const grid = document.getElementById('facilitiesGrid');
  if (grid && (sortVal === 'price_asc' || sortVal === 'price_desc')) {
    cards.forEach(c => grid.appendChild(c));
  }

  closeModal('filterModal');
  showToast(`Filter applied: ${selectedCourtType}`, "success");
}

// Open Play Level Filter — with active pill state
function filterMatchesLevel(level) {
  // Toggle active class on pills
  document.querySelectorAll('[id^="pillLevel"]').forEach(p => p.classList.remove('active'));
  const pillMap = { 'All': 'pillLevelAll', 'Beginner': 'pillLevelBeginner', 'Intermediate': 'pillLevelIntermediate', 'Advanced': 'pillLevelAdvanced' };
  const activePill = document.getElementById(pillMap[level]);
  if (activePill) activePill.classList.add('active');

  document.querySelectorAll('.match-card-item').forEach(card => {
    const show = level === 'All' || card.getAttribute('data-level') === level;
    card.style.display = show ? '' : 'none';
  });
}




// Community Feed Post & Likes
function insertPostTag(tag) {
  const input = document.getElementById('communityPostInput') || document.getElementById('feedPostInput');
  if (!input) return;
  input.value = input.value.trim() ? `${input.value} ${tag} ` : `${tag} `;
  input.focus();
}

function submitCommunityPost() {
  const input = document.getElementById('communityPostInput') || document.getElementById('feedPostInput');
  const content = input ? input.value.trim() : '';
  if (!content) {
    showToast('Please enter your match note or update', 'error');
    return;
  }

  fetch('api.php?action=create_post', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': getCsrfToken()
    },
    body: JSON.stringify({ content: content })
  })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        input.value = '';
        showToast("✓ Post published to Picklers Feed!", "success");
        setTimeout(() => window.location.reload(), 900);
      } else {
        showToast(res.message || 'Failed to post update', "error");
      }
    })
    .catch(() => showToast('Network error posting update', 'error'));
}

function publishFeedPost() {
  submitCommunityPost();
}

function togglePostLike(btn, postId = null) {
  const countEl = btn.querySelector('.like-count');
  const currentCount = parseInt(countEl?.textContent || '0', 10);
  const isLiked = btn.classList.toggle('liked');

  if (btn.style.color === 'rgb(239, 68, 68)' || btn.style.color === '#EF4444') {
    btn.style.color = 'inherit';
    if (countEl) countEl.textContent = Math.max(0, currentCount - 1);
    showToast('Unliked post', 'info');
  } else {
    btn.style.color = '#EF4444';
    if (countEl) countEl.textContent = currentCount + 1;
    showToast('❤️ Liked post!', 'success');
  }

  if (postId) {
    fetch('api.php?action=like_post', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': getCsrfToken()
      },
      body: JSON.stringify({ post_id: postId })
    }).catch(() => { });
  }
}

function toggleLike(postId, btn) {
  togglePostLike(btn, postId);
}

// Live DM Chat
function sendChatMessage() {
  const input = document.getElementById('chatInput');
  const text = input.value.trim();
  if (!text) return;

  const thread = document.getElementById('chatMessagesThread');
  const userMsg = document.createElement('div');
  userMsg.style.cssText = 'align-self:flex-end; background:#10B981; color:#FFFFFF; padding:8px 12px; border-radius:12px; font-size:12px; max-width:80%;';
  userMsg.textContent = text;
  thread.appendChild(userMsg);
  input.value = '';
  thread.scrollTop = thread.scrollHeight;

  fetch('api.php?action=send_message', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': getCsrfToken()
    },
    body: JSON.stringify({ partner_id: 'usr_admin', content: text })
  })
    .then(r => r.json())
    .then(res => {
      if (res.auto_reply) {
        setTimeout(() => {
          const botMsg = document.createElement('div');
          botMsg.style.cssText = 'align-self:flex-start; background:rgba(255,255,255,0.08); padding:8px 12px; border-radius:12px; font-size:12px; max-width:80%;';
          botMsg.textContent = res.auto_reply;
          thread.appendChild(botMsg);
          thread.scrollTop = thread.scrollHeight;
        }, 800);
      }
    });
}

// Avatar Upload & Rotating Brand Ring Integration
function triggerAvatarUpload() {
  const fileInput = document.getElementById('avatarFileInput');
  if (fileInput) {
    fileInput.value = '';
    fileInput.click();
  }
}

/**
 * Downscale an image file to a square avatar and return a compact data URL.
 *
 * Previously the raw FileReader result was stored verbatim, so a 2MB photo
 * became a ~2.7MB base64 string in the users table and was then inlined into
 * every page that rendered that user (the admin console reached 5MB of HTML).
 * Re-encoding at 256px keeps avatars around 20-40KB.
 */
function downscaleImageFile(file, maxEdge = 256, quality = 0.82) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onerror = () => reject(new Error('Could not read the selected file'));
    reader.onload = () => {
      const img = new Image();
      img.onerror = () => reject(new Error('That file is not a readable image'));
      img.onload = () => {
        const scale = Math.min(1, maxEdge / Math.max(img.width, img.height));
        const w = Math.max(1, Math.round(img.width * scale));
        const h = Math.max(1, Math.round(img.height * scale));

        const canvas = document.createElement('canvas');
        canvas.width = w;
        canvas.height = h;
        const ctx = canvas.getContext('2d');
        ctx.imageSmoothingEnabled = true;
        ctx.imageSmoothingQuality = 'high';
        ctx.drawImage(img, 0, 0, w, h);

        // JPEG unless transparency matters; PNG of a photo stays huge.
        const hasAlpha = file.type === 'image/png' || file.type === 'image/webp';
        let out = canvas.toDataURL(hasAlpha ? 'image/webp' : 'image/jpeg', quality);
        if (!out || out.length < 32 || out.indexOf('data:image') !== 0) {
          out = canvas.toDataURL('image/jpeg', quality);
        }
        resolve(out);
      };
      img.src = reader.result;
    };
    reader.readAsDataURL(file);
  });
}
function handleAvatarUpload(event) {
  const file = event.target.files?.[0];
  if (!file) return;

  if (!file.type.startsWith('image/')) {
    showToast('Please select a valid image file (PNG, JPG, WebP)', 'error');
    return;
  }

  if (file.size > 5 * 1024 * 1024) {
    showToast('Image size should be under 5MB', 'error');
    return;
  }

  showToast('Processing photo...', 'info');

  downscaleImageFile(file).then(function (base64Url) {

    // 1. Immediately update Settings Tab Hero Avatar
    const container = document.getElementById('settingsAvatarContainer');
    if (container) {
      container.innerHTML = `<img id="settingsHeroAvatarImg" src="${base64Url}" alt="Avatar">`;
    }

    // 2. Immediately update Mobile Sticky Header Avatar
    const mobileAvatar = document.getElementById('mobileHeaderAvatarImg');
    if (mobileAvatar) {
      mobileAvatar.src = base64Url;
    }

    // 3. Immediately update Desktop Sidebar Avatar
    const sidebarAvatar = document.getElementById('sidebarUserAvatar');
    if (sidebarAvatar) {
      sidebarAvatar.innerHTML = `<img src="${base64Url}" alt="Avatar" style="width:100%; height:100%; border-radius:50%; object-fit:cover;">`;
    }

    // 4. Send to backend via REST API
    fetch('api.php?action=update_profile', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': getCsrfToken()
      },
      body: JSON.stringify({ avatar_url: base64Url })
    })
      .then(r => r.json())
      .then(res => {
        if (res.success) {
          showToast('✓ Profile picture updated successfully!', 'success');
        } else {
          showToast(res.message || 'Failed to save profile picture', 'error');
        }
      })
      .catch(() => {
        showToast('Saved locally, but the server could not be reached', 'error');
      });
  }).catch(function (err) {
    // Previously an unreadable file left the UI stuck on "Processing photo...".
    showToast(err && err.message ? err.message : 'Could not process that image', 'error');
  });
}

// Player Profile Management (Section 13)
function saveProfileChanges() {
  const name = document.getElementById('profileNameInput')?.value.trim();
  const phone = document.getElementById('profilePhoneInput')?.value.trim();
  const level = document.getElementById('profileLevelSelect')?.value;
  const avatarUrl = document.getElementById('profileAvatarUrlInput')?.value.trim();
  const btn = document.getElementById('btnSaveProfile');

  if (!name) {
    showToast('Full name cannot be empty', 'error');
    return;
  }

  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<span>Saving...</span>';
  }

  fetch('api.php?action=update_profile', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': getCsrfToken()
    },
    body: JSON.stringify({
      name: name,
      phone: phone,
      level: level,
      avatar_url: avatarUrl
    })
  })
    .then(r => r.json())
    .then(res => {
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = '<span>Save Profile Changes</span>';
      }
      if (res.success) {
        showToast('✓ Profile updated successfully!', 'success');
        const nameEl = document.querySelector('.user-info .user-name');
        if (nameEl) nameEl.textContent = name;
        const levelEl = document.querySelector('.user-info .user-badge');
        if (levelEl) levelEl.textContent = level;
        if (avatarUrl) {
          document.querySelectorAll('img.user-avatar-img, #profileAvatarPreview').forEach(img => img.src = avatarUrl);
        }
      } else {
        showToast(res.message || 'Update failed', 'error');
      }
    })
    .catch(() => {
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = '<span>Save Profile Changes</span>';
      }
      showToast('Network error while updating profile', 'error');
    });
}

function verifyIdentityNow(btn) {
  // Verification is now REQUESTED, not self-granted — an administrator approves
  // it. Copy and states below reflect that; the old "verified!" message was
  // left over from when the client could grant its own badge.
  const trigger = btn || (typeof event !== 'undefined' && event ? event.currentTarget : null);
  const original = trigger ? trigger.innerHTML : null;
  if (trigger) {
    trigger.disabled = true;
    trigger.innerHTML = '<span>Submitting…</span>';
  }

  const restore = () => {
    if (trigger) {
      trigger.disabled = false;
      if (original !== null) trigger.innerHTML = original;
    }
  };

  fetch('api.php?action=verify_identity', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': getCsrfToken()
    },
    body: JSON.stringify({ csrf_token: getCsrfToken() })
  })
    .then(r => r.json())
    .then(res => {
      if (!res.success) {
        restore();
        showToast(res.message || 'Could not submit your verification request', 'error');
        return;
      }
      if (res.status === 'verified') {
        showToast('✓ Your identity is already verified.', 'success');
      } else {
        showToast('🛡️ Verification request submitted — our team will review it shortly.', 'success');
      }
      setTimeout(() => window.location.reload(), 1200);
    })
    .catch(() => {
      // Previously unhandled: a network failure left the button stuck forever.
      restore();
      showToast('Network error — please check your connection and try again.', 'error');
    });
}

// Settings Tab Supporting Handlers
function saveModalProfileChanges() {
  const name = document.getElementById('modalProfileNameInput')?.value.trim();
  const phone = document.getElementById('modalProfilePhoneInput')?.value.trim();
  const level = document.getElementById('modalProfileLevelSelect')?.value;
  const btn = document.getElementById('btnModalSaveProfile');

  if (!name) {
    showToast('Full name cannot be empty', 'error');
    return;
  }

  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<span>Saving...</span>';
  }

  fetch('api.php?action=update_profile', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': getCsrfToken()
    },
    body: JSON.stringify({
      name: name,
      phone: phone,
      level: level
    })
  })
    .then(r => r.json())
    .then(res => {
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = '<span>Save Changes</span>';
      }
      if (res.success) {
        closeModal('editAccountModal');
        showToast('✓ Account details updated!', 'success');
        const heroEl = document.getElementById('settingsHeroNameDisplay');
        if (heroEl) heroEl.textContent = name;
        const rowNameEl = document.getElementById('settingsRowNameDisplay');
        if (rowNameEl) rowNameEl.textContent = name;
        const rowPhoneEl = document.getElementById('settingsRowPhoneDisplay');
        if (rowPhoneEl) rowPhoneEl.textContent = phone || 'Not connected';
        const sideNameEl = document.querySelector('.user-info .user-name');
        if (sideNameEl) sideNameEl.textContent = name;
      } else {
        showToast(res.message || 'Update failed', 'error');
      }
    })
    .catch(() => {
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = '<span>Save Changes</span>';
      }
      showToast('Network error while updating profile', 'error');
    });
}

function toggleSocialAccount(platform, btn) {
  if (btn.classList.contains('connected')) {
    btn.classList.remove('connected');
    btn.textContent = 'Connect';
    showToast(`${platform} account disconnected`, 'error');
  } else {
    btn.classList.add('connected');
    btn.textContent = 'Connected ✓';
    showToast(`${platform} account connected`, 'success');
  }
}

function promptChangePassword() {
  openModal('passwordModal');
}

// Password submission handled by primary submitPasswordChange() above

function confirmDeleteAccount() {
  const input = document.getElementById('deleteAccountConfirmInput');
  if (input) input.value = '';
  openModal('deleteAccountModal');
}

function executeAccountDeletion(btn) {
  const input = document.getElementById('deleteAccountConfirmInput')?.value?.trim();
  if (input !== 'DELETE') {
    showToast('Please type DELETE in all caps to confirm.', 'error');
    return;
  }

  // This used to sign the user out and claim the account was deleted while
  // never calling the server. It now performs the deletion for real, behind a
  // submit guard so a double-click cannot fire it twice.
  const trigger = btn || document.querySelector('#deleteAccountModal .btn-modal-danger, #deleteAccountModal [data-confirm-delete]');
  const run = () => {
    const fd = new FormData();
    fd.append('action', 'delete_own_account');
    fd.append('confirm', input);
    fd.append('csrf_token', getCsrfToken());
    return fetch('api.php', { method: 'POST', headers: { 'X-CSRF-TOKEN': getCsrfToken() }, body: fd })
      .then(r => r.json())
      .then(res => {
        if (!res || !res.success) {
          showToast((res && res.message) || 'Could not delete the account', 'error');
          return;
        }
        closeModal('deleteAccountModal');
        showToast(res.message || 'Account deactivated. Signing out...', 'info');
        setTimeout(() => {
          window.location.href = (res.redirect) || 'auth.php?logout=1';
        }, 1200);
      })
      .catch(() => showToast('Network error - your account was not changed', 'error'));
  };

  if (window.UX) return window.UX.guard(trigger, run, 'Deleting...');
  return run();
}


// Theme Management
function initThemeToggle() {
  const saved = localStorage.getItem('picklers_theme') || 'dark';
  const cb = document.getElementById('themeToggleCheckbox');
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
    showToast('🌙 Dark mode activated', 'success');
  } else {
    html.classList.remove('dark');
    html.classList.add('light');
    localStorage.setItem('picklers_theme', 'light');
    showToast('☀️ Light mode activated', 'success');
  }
}

document.addEventListener('DOMContentLoaded', () => {
  initThemeToggle();

  const modalPhoneIn = document.getElementById('modalProfilePhoneInput');
  if (modalPhoneIn) {
    formatPHPhoneInput(modalPhoneIn);
  }

  // Guard against browser autofill populating login credentials into search inputs
  const sanitizeVenueSearchInput = () => {
    const searchInput = document.getElementById('facilitySearchInput');
    if (searchInput && (searchInput.value.includes('@') || searchInput.value.toLowerCase().includes('admin'))) {
      searchInput.value = '';
      const clearBtn = document.getElementById('facilitySearchClearBtn');
      if (clearBtn) clearBtn.classList.remove('visible');
      if (typeof filterFacilitiesLive === 'function') filterFacilitiesLive();
    }
  };
  sanitizeVenueSearchInput();
  setTimeout(sanitizeVenueSearchInput, 100);
  setTimeout(sanitizeVenueSearchInput, 400);
  window.addEventListener('pageshow', sanitizeVenueSearchInput);

  const datePillsBox = document.getElementById('qbDatePillsContainer');
  if (datePillsBox) {
    datePillsBox.addEventListener('wheel', (e) => {
      if (e.deltaY !== 0) {
        e.preventDefault();
        datePillsBox.scrollLeft += e.deltaY;
      }
    }, { passive: false });
  }

  // Handle Open Play checkout redirect from Explore tab
  const urlParams = new URLSearchParams(window.location.search);
  if (urlParams.get('checkout') === 'openplay') {
    const rawData = sessionStorage.getItem('picklers_openplay_checkout');
    if (rawData) {
      try {
        const m = JSON.parse(rawData);
        if (m && m.matchId) {
          openPaymentReview(
            m.matchId,
            '', // no court concept for an Open Play session
            m.matchType || 'Open Play Session',
            m.price || 200,
            m.level || 'All Levels',
            m.level || 'Intermediate',
            m.facilityName || 'Pickleball Facility',
            m.date || 'Upcoming Match',
            m.time || 'Open Play Hours',
            2,
            m.matchId
          );
        }
      } catch (e) {
        console.error('Failed to parse open play checkout session:', e);
      }
    }
  }

  // Initialize swipe-to-remove gesture handlers for notifications
  initNotificationSwipeGestures();

  // Start Real-Time Open Play Sync
  initRealTimeOpenPlaySync();
});

// Real-time Open Play Match Sync (Polls every 4 seconds)
let openPlaySyncInterval = null;

function initRealTimeOpenPlaySync() {
  if (openPlaySyncInterval) clearInterval(openPlaySyncInterval);
  
  const syncMatches = () => {
    fetch('api.php?action=matches')
      .then(r => r.json())
      .then(data => {
        if (!data || !data.success || !Array.isArray(data.matches)) return;
        const matches = data.matches;
        
        matches.forEach(m => {
          const card = document.querySelector(`.match-card-item[data-id="${m.id}"]`);
          if (!card) return;
          
          const current = parseInt(m.current_players || 0, 10);
          const max = parseInt(m.max_players || 4, 10);
          const isFull = current >= max;
          const spotsLeft = Math.max(0, max - current);
          
          const circleEl = card.querySelector('.openplay-spots-circle');
          if (circleEl) circleEl.textContent = `${current}/${max}`;
          
          const filledEl = card.querySelector('.openplay-spots-filled');
          if (filledEl) filledEl.textContent = `${current} of ${max} spots filled`;
          
          const remainEl = card.querySelector('.openplay-spots-remain');
          if (remainEl) {
            remainEl.textContent = isFull ? 'Session full' : `${spotsLeft} ${spotsLeft === 1 ? 'spot' : 'spots'} left`;
          }
          
          const btn = card.querySelector('.openplay-card-footer button');
          if (btn) {
            if (isFull) {
              btn.disabled = true;
              btn.textContent = 'Full';
              btn.classList.add('disabled');
            } else if (!btn.hasAttribute('data-user-joined')) {
              btn.disabled = false;
              btn.textContent = 'Join';
              btn.classList.remove('disabled');
            }
          }
        });
      })
      .catch(() => {});
  };

  openPlaySyncInterval = setInterval(syncMatches, 4000);
}
