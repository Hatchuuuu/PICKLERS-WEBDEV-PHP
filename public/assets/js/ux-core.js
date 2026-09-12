/* ==========================================================================
   PICKLERS — Shared UX Core
   Dialog mechanics, live-region announcements, and submit guards.

   Loaded before app.js / owner.js / the admin console so every surface
   delegates to one implementation instead of three divergent copies.
   ========================================================================== */
(function (window, document) {
  'use strict';

  var MOTION_QUERY = window.matchMedia
    ? window.matchMedia('(prefers-reduced-motion: reduce)')
    : { matches: false };

  /* ---------------------------------------------------------------------
     Scroll lock — reference counted.
     The previous implementation reset body.overflow on ANY close, so
     dismissing a stacked dialog unlocked scrolling while another was still
     open. Counting keeps the lock correct for nested dialogs.
     --------------------------------------------------------------------- */
  var lockCount = 0;
  var savedScrollY = 0;

  function lockScroll() {
    if (lockCount === 0) {
      savedScrollY = window.scrollY || window.pageYOffset || 0;
      document.body.style.overflow = 'hidden';
      // iOS Safari ignores overflow:hidden on body; pinning the position is
      // what actually stops the page scrolling underneath the sheet.
      document.body.style.position = 'fixed';
      document.body.style.width = '100%';
      document.body.style.top = '-' + savedScrollY + 'px';
    }
    lockCount++;
  }

  function unlockScroll() {
    lockCount = Math.max(0, lockCount - 1);
    if (lockCount === 0) {
      document.body.style.overflow = '';
      document.body.style.position = '';
      document.body.style.width = '';
      document.body.style.top = '';
      window.scrollTo(0, savedScrollY);
    }
  }

  /* ---------------------------------------------------------------------
     Focus management
     --------------------------------------------------------------------- */
  var FOCUSABLE = [
    'a[href]',
    'button:not([disabled])',
    'input:not([disabled]):not([type="hidden"])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    '[tabindex]:not([tabindex="-1"])'
  ].join(',');

  function focusableWithin(root) {
    return Array.prototype.filter.call(
      root.querySelectorAll(FOCUSABLE),
      function (el) {
        var r = el.getBoundingClientRect();
        return r.width > 0 && r.height > 0 && getComputedStyle(el).visibility !== 'hidden';
      }
    );
  }

  /* ---------------------------------------------------------------------
     Dialog stack
     --------------------------------------------------------------------- */
  var stack = [];

  function topDialog() {
    return stack.length ? stack[stack.length - 1] : null;
  }

  function open(id, options) {
    var el = typeof id === 'string' ? document.getElementById(id) : id;
    if (!el) return null;
    for (var s = 0; s < stack.length; s++) {
      if (stack[s].el === el) return el;
    }

    var opts = options || {};

    // Announce as a dialog. No existing modal carried these attributes, so
    // assistive tech treated them as ordinary page content.
    if (!el.getAttribute('role')) el.setAttribute('role', 'dialog');
    el.setAttribute('aria-modal', 'true');
    if (!el.getAttribute('aria-label') && !el.getAttribute('aria-labelledby')) {
      var heading = el.querySelector('h1,h2,h3,h4,.modal-title,.app-modal-title');
      if (heading) {
        if (!heading.id) heading.id = (el.id || 'dlg') + '-title';
        el.setAttribute('aria-labelledby', heading.id);
      }
    }

    var entry = {
      el: el,
      restoreFocus: document.activeElement,
      dismissible: opts.dismissible !== false,
      downOnBackdrop: false
    };

    el.style.display = 'flex';
    el.classList.remove('ux-closing');
    // 'open' supports surfaces whose CSS gates visibility on a class rather
    // than on display (the admin console). Inert everywhere else.
    el.classList.add('ux-open', 'open');
    lockScroll();
    stack.push(entry);

    // Move focus inside so keyboard and screen-reader users are not stranded
    // behind the backdrop.
    // setTimeout rather than rAF: rAF does not fire in background/throttled
    // tabs, which would leave focus stranded behind the backdrop.
    window.setTimeout(function () {
      var preferred = el.querySelector('[data-autofocus]');
      var targets = focusableWithin(el);
      var target = preferred || targets[0] || el;
      if (target === el && !el.hasAttribute('tabindex')) el.setAttribute('tabindex', '-1');
      try {
        target.focus({ preventScroll: true });
      } catch (err) {
        /* older browsers ignore the options object */
      }
    }, 0);

    return el;
  }

  function close(id) {
    var el = typeof id === 'string' ? document.getElementById(id) : id;
    var index = -1;
    for (var i = stack.length - 1; i >= 0; i--) {
      if (!el || stack[i].el === el) { index = i; break; }
    }

    if (index === -1) {
      // Not tracked (opened before this module loaded) — close defensively.
      if (el) {
        el.style.display = 'none';
        el.classList.remove('ux-open', 'open');
      }
      return;
    }

    var entry = stack[index];
    stack.splice(index, 1);
    var node = entry.el;

    var finish = function () {
      node.style.display = 'none';
      node.classList.remove('ux-open', 'ux-closing', 'open');
      unlockScroll();
      if (entry.restoreFocus && document.contains(entry.restoreFocus)) {
        try {
          entry.restoreFocus.focus({ preventScroll: true });
        } catch (err) { /* ignore */ }
      }
    };

    // Dialogs used to fade IN but vanish instantly. Play the exit unless the
    // user asked for reduced motion.
    if (MOTION_QUERY.matches) {
      finish();
      return;
    }

    node.classList.remove('ux-open', 'open');
    node.classList.add('ux-closing');

    var done = false;
    var onEnd = function (evt) {
      if (evt && evt.target !== node) return;
      if (done) return;
      done = true;
      node.removeEventListener('animationend', onEnd);
      finish();
    };
    node.addEventListener('animationend', onEnd);
    window.setTimeout(onEnd, 260); // fallback if the animation never fires
  }

  function closeTop() {
    var top = topDialog();
    if (top && top.dismissible) close(top.el);
  }

  // Escape closes the topmost dialog; Tab is trapped inside it.
  document.addEventListener('keydown', function (e) {
    var top = topDialog();
    if (!top) return;

    if (e.key === 'Escape' || e.key === 'Esc') {
      e.preventDefault();
      closeTop();
      return;
    }

    if (e.key === 'Tab') {
      var items = focusableWithin(top.el);
      if (!items.length) { e.preventDefault(); return; }
      var first = items[0];
      var last = items[items.length - 1];
      var active = document.activeElement;

      if (!top.el.contains(active)) {
        e.preventDefault();
        first.focus();
      } else if (e.shiftKey && active === first) {
        e.preventDefault();
        last.focus();
      } else if (!e.shiftKey && active === last) {
        e.preventDefault();
        first.focus();
      }
    }
  }, true);

  // Backdrop dismissal requires press AND release on the backdrop, so a drag
  // that ends outside the panel does not close the dialog unexpectedly.
  document.addEventListener('mousedown', function (e) {
    var top = topDialog();
    if (top && top.dismissible && e.target === top.el) top.downOnBackdrop = true;
  }, true);

  document.addEventListener('click', function (e) {
    var top = topDialog();
    if (!top || !top.dismissible) return;
    if (e.target === top.el && top.downOnBackdrop) {
      top.downOnBackdrop = false;
      close(top.el);
    }
  }, true);

  /* ---------------------------------------------------------------------
     Live region — toasts were entirely silent to screen readers.
     --------------------------------------------------------------------- */
  var politeRegion = null;
  var assertiveRegion = null;

  function ensureRegions() {
    if (politeRegion) return;
    var make = function (live) {
      var n = document.createElement('div');
      n.setAttribute('aria-live', live);
      n.setAttribute('aria-atomic', 'true');
      n.setAttribute('role', live === 'assertive' ? 'alert' : 'status');
      n.className = 'ux-visually-hidden';
      document.body.appendChild(n);
      return n;
    };
    politeRegion = make('polite');
    assertiveRegion = make('assertive');
  }

  function announce(message, kind) {
    if (!message) return;
    ensureRegions();
    var region = (kind === 'error' || kind === 'assertive') ? assertiveRegion : politeRegion;
    // Clearing first guarantees the reader re-announces an identical message.
    region.textContent = '';
    window.setTimeout(function () {
      region.textContent = String(message);
    }, 30);
  }

  /* ---------------------------------------------------------------------
     Submit guard — prevents double charges and duplicate bookings.
     --------------------------------------------------------------------- */
  function guard(button, work, busyLabel) {
    var btn = typeof button === 'string' ? document.querySelector(button) : button;
    if (btn && btn.getAttribute('data-ux-busy') === '1') return Promise.resolve(null);

    var originalHtml = btn ? btn.innerHTML : null;
    if (btn) {
      btn.setAttribute('data-ux-busy', '1');
      btn.disabled = true;
      btn.setAttribute('aria-busy', 'true');
      if (busyLabel) {
        btn.innerHTML = '<span class="ux-spinner" aria-hidden="true"></span><span>' + busyLabel + '</span>';
      }
    }

    var release = function () {
      if (!btn) return;
      btn.removeAttribute('data-ux-busy');
      btn.disabled = false;
      btn.removeAttribute('aria-busy');
      if (originalHtml !== null) btn.innerHTML = originalHtml;
    };

    var result;
    try {
      result = work();
    } catch (err) {
      release();
      throw err;
    }

    return Promise.resolve(result).then(
      function (value) { release(); return value; },
      function (err) { release(); throw err; }
    );
  }

  window.UX = {
    openDialog: open,
    closeDialog: close,
    closeTopDialog: closeTop,
    announce: announce,
    guard: guard,
    lockScroll: lockScroll,
    unlockScroll: unlockScroll,
    prefersReducedMotion: function () { return MOTION_QUERY.matches; }
  };
})(window, document);

/* ==========================================================================
   PICKLERS — Cross-Surface Sync
   Polls api.php?action=sync for a small set of version counters and calls
   back only the channels that actually moved since the last poll. This is
   what makes "an owner lists a court" reach a player's Discover feed, and
   "a player books a court" reach the owner's request queue, without a
   manual page refresh — at the cost of one small GET every ~12s rather than
   a held-open connection per tab.

   Both app.js (player) and owner.js (owner) subscribe to the channels they
   each care about via PickSync.on(channel, callback); neither needs to know
   the other exists.
   ========================================================================== */
(function (window) {
  'use strict';

  var known = null;       // null until the first successful poll
  var timer = null;
  var subscribers = {};   // channel -> [callbacks]
  var started = false;

  function pollInterval() {
    // A hidden tab still catches up (a stale mobile browser tab shouldn't
    // show week-old data when it's finally reopened), just far less often.
    return (typeof document !== 'undefined' && document.hidden) ? 60000 : 12000;
  }

  function notify(channel, value) {
    var list = subscribers[channel];
    if (!list) return;
    for (var i = 0; i < list.length; i++) {
      try {
        // The new value is passed straight through — unread_notifications'
        // subscriber needs the count itself, not just "it changed", and
        // channels that are pure change-signals (facilities/courts/bookings)
        // simply ignore the argument.
        list[i](value);
      } catch (err) {
        // One subscriber's own bug must never stop the poll loop or any
        // other subscriber on the same channel from being notified.
        if (window.console && window.console.error) {
          window.console.error('[PickSync] subscriber for "' + channel + '" threw:', err);
        }
      }
    }
  }

  function tick() {
    fetch('api.php?action=sync', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        // unread_notifications rides the same channel/version comparison as
        // everything else in `versions` — it's just another number a
        // subscriber wants to know changed, not a special case.
        var current = (data && data.versions) || {};
        if (data && typeof data.unread_notifications === 'number') {
          current = Object.assign({}, current, { unread_notifications: data.unread_notifications });
        }

        if (known === null) {
          // First poll only establishes the baseline — nothing "changed"
          // relative to a page that only just loaded its own fresh data.
          known = current;
          return;
        }
        Object.keys(current).forEach(function (channel) {
          if (known[channel] !== current[channel]) {
            known[channel] = current[channel];
            notify(channel, current[channel]);
          }
        });
      })
      .catch(function () {
        // Offline, or mid-deploy — skip this tick silently. The loop keeps
        // running; the next poll picks up wherever the server actually is.
      })
      .finally(function () {
        timer = window.setTimeout(tick, pollInterval());
      });
  }

  if (typeof document !== 'undefined') {
    document.addEventListener('visibilitychange', function () {
      if (!started || document.hidden) return;
      // Coming back into view: re-poll immediately rather than waiting out
      // whatever fraction of the 60s hidden-tab interval remains.
      if (timer) { window.clearTimeout(timer); }
      tick();
    });
  }

  window.PickSync = {
    /** Register a callback for when `channel`'s version changes. */
    on: function (channel, callback) {
      if (!subscribers[channel]) subscribers[channel] = [];
      subscribers[channel].push(callback);
    },
    /** Begin polling. Safe to call more than once — only the first counts. */
    start: function () {
      if (started) return;
      started = true;
      tick();
    }
  };
})(window);
