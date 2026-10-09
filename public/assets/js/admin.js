/* ==========================================================================
   PICKLERS — Admin console behaviour (progressive enhancement)
   Every link and filter form works without JavaScript (server-rendered via
   Symfony/Twig). This script upgrades them to in-place panel loads with
   history, accessible dialogs for details and actions, row menus, the activity
   popover, and duplicate-submission protection.
   ========================================================================== */
(function () {
  'use strict';

  var body = document.body;
  var cfg = {
    csrf: body.dataset.csrf,
    apiUrl: body.dataset.apiUrl,
    panelUrl: body.dataset.panelUrl,
    detailUrl: body.dataset.detailUrl,
    consoleUrl: body.dataset.consoleUrl,
    appUrl: body.dataset.appUrl
  };
  if (!cfg.apiUrl) return;

  var panel = document.getElementById('adminPanel');
  var titles = {};
  document.querySelectorAll('[data-tab-link]').forEach(function (a) { titles[a.dataset.tabLink] = a.textContent.trim().replace(/\s+\d+.*$/, ''); });

  /* ---------------------------------------------------------------- utils */
  function announce(msg, kind) { if (window.UX) window.UX.announce(msg, kind); }

  function toast(msg, type) {
    var box = document.getElementById('toastContainer');
    if (!box) return;
    var t = document.createElement('div');
    t.className = 'pk-toast' + (type === 'error' ? ' pk-toast--error' : '');
    t.setAttribute('role', type === 'error' ? 'alert' : 'status');
    t.textContent = msg;
    box.appendChild(t);
    while (box.children.length > 3) box.removeChild(box.firstChild);
    window.setTimeout(function () { if (t.parentNode) t.parentNode.removeChild(t); }, type === 'error' ? 8000 : 4500);
  }

  function uuid() {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    var b = new Uint8Array(16); (window.crypto || window.msCrypto).getRandomValues(b);
    return Array.prototype.map.call(b, function (x) { return ('0' + x.toString(16)).slice(-2); }).join('');
  }

  function jsonHeaders() {
    return { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json', 'X-CSRF-Token': cfg.csrf };
  }

  /** POST an admin action. Resolves {ok, status, data}; never throws for HTTP errors. */
  function api(action, params) {
    var fd = new FormData();
    fd.append('action', action);
    fd.append('csrf_token', cfg.csrf);
    Object.keys(params || {}).forEach(function (k) { if (params[k] !== undefined && params[k] !== null) fd.append(k, params[k]); });
    return fetch(cfg.apiUrl, { method: 'POST', body: fd, headers: jsonHeaders(), credentials: 'same-origin' })
      .then(function (res) {
        return res.json().catch(function () { return { success: false, message: 'Unexpected response from the server (HTTP ' + res.status + ').' }; })
          .then(function (data) { return { ok: res.ok && data.success, status: res.status, data: data }; });
      });
  }

  function apiGet(action, params) {
    var url = new URL(cfg.apiUrl, window.location.href);
    url.searchParams.set('action', action);
    Object.keys(params || {}).forEach(function (k) { url.searchParams.set(k, params[k]); });
    return fetch(url.toString(), { headers: jsonHeaders(), credentials: 'same-origin' })
      .then(function (res) { return res.json().then(function (data) { return { ok: res.ok && data.success, status: res.status, data: data }; }); });
  }

  function handleAuthLoss(status) {
    if (status === 401) {
      toast('Your session ended. Taking you to sign in…', 'error');
      window.setTimeout(function () { window.location.reload(); }, 1200);
      return true;
    }
    return false;
  }

  /* -------------------------------------------------- panel navigation */
  var currentPanelUrl = window.location.href;

  function panelFetchUrl(href) {
    var url = new URL(href, window.location.href);
    var tab = url.searchParams.get('tab') || 'overview';
    url.searchParams.delete('tab');
    var qs = url.searchParams.toString();
    return { tab: tab, fetchUrl: cfg.panelUrl.replace('__TAB__', encodeURIComponent(tab)) + (qs ? '?' + qs : '') };
  }

  function setActiveNav(tab) {
    document.querySelectorAll('[data-tab-link]').forEach(function (a) {
      var on = a.dataset.tabLink === tab;
      a.classList.toggle('is-active', on);
      if (on) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current');
    });
  }

  function syncSearch(scope, href) {
    var wrap = document.getElementById('topSearch');
    var input = document.getElementById('topSearchInput');
    var label = document.getElementById('topSearchLabel');
    if (!wrap || !input) return;
    wrap.hidden = !scope;
    input.placeholder = scope || 'Search';
    if (label) label.textContent = scope || 'Search';
    input.value = new URL(href, window.location.href).searchParams.get('q') || '';
  }

  function updateBadges(header) {
    if (!header) return;
    try {
      var badges = JSON.parse(header);
      Object.keys(badges).forEach(function (tab) {
        var el = document.querySelector('[data-badge="' + tab + '"]');
        if (!el) return;
        el.hidden = badges[tab] <= 0;
        el.innerHTML = '';
        el.appendChild(document.createTextNode(String(badges[tab])));
        var sr = document.createElement('span'); sr.className = 'pk-sr'; sr.textContent = tab === 'moderation' ? ' open cases' : ' pending';
        el.appendChild(sr);
      });
    } catch (e) { /* ignore malformed header */ }
  }

  /**
   * Load a panel in place. opts.push: add a history entry; opts.focus: move
   * focus to the panel heading (navigation) rather than keep it (refresh).
   */
  function loadPanel(href, opts) {
    opts = opts || {};
    var target = panelFetchUrl(href);
    panel.classList.add('is-loading');
    panel.setAttribute('aria-busy', 'true');
    return fetch(target.fetchUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
      .then(function (res) {
        if (handleAuthLoss(res.status)) throw new Error('auth');
        if (!res.ok) throw new Error('HTTP ' + res.status);
        updateBadges(res.headers.get('X-Admin-Badges'));
        var scope = res.headers.get('X-Search-Scope') || '';
        return res.text().then(function (html) { return { html: html, scope: scope }; });
      })
      .then(function (r) {
        panel.innerHTML = r.html;
        panel.dataset.tab = target.tab;
        setActiveNav(target.tab);
        syncSearch(r.scope, href);
        currentPanelUrl = new URL(href, window.location.href).toString();
        if (opts.push) window.history.pushState({ adminPanel: currentPanelUrl }, '', currentPanelUrl);
        else if (opts.replace) window.history.replaceState({ adminPanel: currentPanelUrl }, '', currentPanelUrl);
        document.title = (titles[target.tab] || 'Admin Console') + ' — PICKLERS';
        if (opts.focus) {
          var h = panel.querySelector('[data-panel-heading]');
          if (h) h.focus({ preventScroll: true });
          window.scrollTo(0, 0);
          var count = panel.querySelector('.pk-pager__count');
          announce((titles[target.tab] || 'Panel') + ' loaded. ' + (count ? count.textContent : ''));
        }
      })
      .catch(function (err) {
        if (err && err.message === 'auth') return;
        toast('Could not load that view. Check your connection and try again.', 'error');
        if (opts.push) window.location.href = href; // fall back to a normal page load
      })
      .finally(function () {
        panel.classList.remove('is-loading');
        panel.removeAttribute('aria-busy');
      });
  }

  function refreshPanel() { return loadPanel(currentPanelUrl, {}); }

  document.addEventListener('click', function (e) {
    var link = e.target.closest('a[data-panel-link], a[data-tab-link]');
    if (!link || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    var url = new URL(link.href, window.location.href);
    if (url.pathname !== new URL(cfg.consoleUrl, window.location.href).pathname) return;
    e.preventDefault();
    closeNav();
    closeDialogById('detailDialog');
    loadPanel(link.href, { push: true, focus: true });
  });

  function submitPanelForm(form) {
    var url = new URL(form.action, window.location.href);
    new FormData(form).forEach(function (v, k) { if (String(v) !== '') url.searchParams.set(k, v); });
    // The top search box belongs to this form (form="panelFilters").
    url.searchParams.delete('page');
    loadPanel(url.toString(), { push: true });
  }

  document.addEventListener('submit', function (e) {
    var form = e.target.closest('form[data-panel-form]');
    if (!form) return;
    e.preventDefault();
    submitPanelForm(form);
  });

  document.addEventListener('change', function (e) {
    var el = e.target;
    if (el.matches('[data-autosubmit]') && el.form && el.form.matches('[data-panel-form]')) {
      submitPanelForm(el.form);
    } else if (el.matches('[data-page-size]')) {
      var url = new URL(el.dataset.base, window.location.href);
      url.searchParams.set('per_page', el.value);
      loadPanel(url.toString(), { push: true });
    } else if (el.matches('[data-toggle-password]')) {
      var pw = el.closest('form').querySelector('input[name="new_password"]');
      if (pw) pw.type = el.checked ? 'text' : 'password';
    }
  });

  window.addEventListener('popstate', function () {
    loadPanel(window.location.href, { focus: true });
  });
  window.history.replaceState({ adminPanel: window.location.href }, '', window.location.href);

  /* ---------------------------------------------------------- dialogs */
  function openDialogById(id) { if (window.UX) window.UX.openDialog(id); }
  function closeDialogById(id) {
    var el = document.getElementById(id);
    if (el && (el.classList.contains('ux-open') || el.classList.contains('open')) && window.UX) window.UX.closeDialog(id);
  }
  document.addEventListener('click', function (e) {
    var closer = e.target.closest('[data-close-dialog]');
    if (closer) { var dlg = closer.closest('.pk-modal'); if (dlg) closeDialogById(dlg.id); }
  });

  /* ---------------------------------------------------------- details */
  var detailState = null;

  function openDetail(type, id) {
    var box = document.getElementById('detailBody');
    detailState = { type: type, id: id };
    box.innerHTML = '<p class="pk-muted" role="status" id="detailTitle">Loading…</p>';
    openDialogById('detailDialog');
    return loadDetail();
  }

  function loadDetail() {
    if (!detailState) return Promise.resolve();
    var box = document.getElementById('detailBody');
    var url = cfg.detailUrl.replace('__TYPE__', encodeURIComponent(detailState.type)).replace('__ID__', encodeURIComponent(detailState.id));
    return fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
      .then(function (res) {
        if (handleAuthLoss(res.status)) throw new Error('auth');
        return res.text().then(function (html) { return { ok: res.ok, status: res.status, html: html }; });
      })
      .then(function (r) {
        if (!r.ok) {
          var msg = r.status === 404 ? 'This record no longer exists.' : (r.status === 403 ? 'You do not have permission to view this record.' : 'Could not load this record.');
          box.innerHTML = '';
          var p = document.createElement('p'); p.id = 'detailTitle'; p.className = 'pk-notice pk-notice--danger'; p.textContent = msg; box.appendChild(p);
          if (r.status >= 500) {
            var b = document.createElement('button'); b.type = 'button'; b.className = 'pk-btn pk-btn--ghost'; b.textContent = 'Retry';
            b.addEventListener('click', loadDetail); box.appendChild(b);
          }
          return;
        }
        box.innerHTML = r.html;
        var title = box.querySelector('#detailTitle');
        if (title) { title.setAttribute('tabindex', '-1'); title.focus({ preventScroll: true }); }
      })
      .catch(function (err) {
        if (err && err.message === 'auth') return;
        box.innerHTML = '<p class="pk-notice pk-notice--danger" id="detailTitle">Network problem while loading this record.</p>';
      });
  }

  document.addEventListener('click', function (e) {
    var trigger = e.target.closest('[data-detail]');
    if (!trigger) return;
    e.preventDefault();
    closeMenus();
    openDetail(trigger.dataset.detail, trigger.dataset.id);
  });

  /* ---------------------------------------------------------- actions */
  var actionState = null;
  var actionDialog = document.getElementById('actionDialog');
  var actionForm = document.getElementById('actionForm');
  var actionError = document.getElementById('actionError');
  var actionSubmit = document.getElementById('actionSubmit');

  function showActionError(message, field) {
    actionError.textContent = message;
    actionError.hidden = false;
    announce(message, 'error');
    actionForm.querySelectorAll('[aria-invalid]').forEach(function (el) { el.removeAttribute('aria-invalid'); });
    if (field) {
      var input = actionForm.querySelector('[name="' + field + '"]');
      if (input) { input.setAttribute('aria-invalid', 'true'); input.setAttribute('aria-describedby', 'actionError'); input.focus(); }
    }
  }

  function openAction(btn) {
    var tpl = document.getElementById('form-' + btn.dataset.form);
    if (!tpl) { toast('This action is not available.', 'error'); return; }
    var params = {};
    try { params = JSON.parse(btn.dataset.params || '{}'); } catch (e) { params = {}; }
    actionState = {
      btn: btn,
      action: btn.dataset.action,
      params: params,
      after: btn.dataset.after || '',
      key: tpl.hasAttribute('data-idempotent') ? uuid() : null,
      twoStep: tpl.dataset.twoStep || null,
      previewCount: null,
      submitLabel: tpl.dataset.submit || 'Confirm'
    };
    document.getElementById('actionTitle').textContent = btn.dataset.title || tpl.dataset.title || 'Confirm';
    var subject = document.getElementById('actionSubject');
    subject.textContent = btn.dataset.subject || '';
    subject.hidden = !btn.dataset.subject;
    document.getElementById('actionDesc').innerHTML = '';
    var fields = document.getElementById('actionFields');
    fields.innerHTML = '';
    fields.appendChild(tpl.content.cloneNode(true));
    var prefill = btn.nextElementSibling && btn.nextElementSibling.matches('template[data-prefill]') ? btn.nextElementSibling : null;
    if (prefill) {
      try {
        var values = JSON.parse(prefill.content.textContent);
        Object.keys(values).forEach(function (k) { var f = fields.querySelector('[name="' + k + '"]'); if (f && values[k] !== null) f.value = values[k]; });
      } catch (e) { /* no prefill */ }
    }

    var rejectRadios = fields.querySelectorAll('input[name="reject_preset"]');
    var reasonTextarea = fields.querySelector('textarea[name="reason"]');
    if (rejectRadios.length && reasonTextarea) {
      var syncReason = function () {
        var checked = fields.querySelector('input[name="reject_preset"]:checked');
        if (!checked) return;
        if (checked.value === 'custom') {
          reasonTextarea.value = '';
          reasonTextarea.focus();
        } else {
          reasonTextarea.value = checked.value;
        }
      };
      syncReason();
      rejectRadios.forEach(function (r) { r.addEventListener('change', syncReason); });
    }

    actionError.hidden = true;
    actionError.textContent = '';
    actionSubmit.textContent = actionState.submitLabel;
    actionSubmit.disabled = false;
    actionDialog.classList.toggle('pk-modal--danger', tpl.dataset.tone === 'danger');
    var first = fields.querySelector('input:not([type=hidden]):not([type=radio]), textarea, select');
    if (first) first.setAttribute('data-autofocus', '');
    else actionSubmit.setAttribute('data-autofocus', '');
    closeMenus();
    openDialogById('actionDialog');
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-action][data-form]');
    if (!btn || btn.disabled) return;
    e.preventDefault();
    openAction(btn);
  });

  function collectFields() {
    var out = {};
    new FormData(actionForm).forEach(function (v, k) { out[k] = typeof v === 'string' ? v.trim() : v; });
    return out;
  }

  function validateForm() {
    var invalid = Array.prototype.filter.call(actionForm.querySelectorAll('input, textarea, select'), function (el) { return !el.checkValidity(); });
    if (!invalid.length) return true;
    var el = invalid[0];
    var label = el.closest('label, fieldset');
    var name = label ? (label.querySelector('span, legend') || label).textContent.replace(/\(.*?\)/g, '').trim() : el.name;
    showActionError((name || 'This field') + ': ' + el.validationMessage, el.name);
    return false;
  }

  actionForm.addEventListener('submit', function (e) {
    e.preventDefault();
    if (!actionState || actionSubmit.getAttribute('data-ux-busy') === '1') return;
    if (!validateForm()) return;
    var payload = Object.assign({}, actionState.params, collectFields());
    if (actionState.key) payload.idempotency_key = actionState.key;

    // Broadcast: first show the exact audience, then send against that count.
    if (actionState.twoStep && actionState.previewCount === null) {
      var previewBox = actionForm.querySelector('[data-preview]');
      window.UX.guard(actionSubmit, function () {
        return api(actionState.twoStep, payload).then(function (r) {
          if (!r.ok) { showActionError(r.data.message || 'Preview failed.', r.data.field); return; }
          var p = r.data.preview;
          actionState.previewCount = p.count;
          previewBox.hidden = false;
          previewBox.innerHTML = '';
          var strong = document.createElement('strong'); strong.textContent = p.count + ' recipient(s) · ' + p.label;
          var t = document.createElement('p'); t.textContent = '“' + p.title + '” — ' + p.body;
          previewBox.appendChild(strong); previewBox.appendChild(t);
          actionForm.querySelectorAll('input, textarea, select').forEach(function (el) { if (!el.closest('[data-preview]')) el.readOnly = true; if (el.tagName === 'SELECT') el.disabled = true; });
          actionState.submitLabel = p.count > 0 ? 'Send to ' + p.count + ' account(s)' : 'Nobody to send to';
          actionError.hidden = true;
          announce('Preview ready: ' + p.count + ' recipients.');
        });
      }, 'Counting…').then(function () {
        actionSubmit.textContent = actionState.submitLabel;
        actionSubmit.disabled = actionState.previewCount === 0;
      });
      return;
    }
    if (actionState.twoStep) {
      var sel = actionForm.querySelector('select[name="role_filter"]');
      if (sel) payload.role_filter = sel.value;
      payload.confirm_count = actionState.previewCount;
    }

    var state = actionState;
    window.UX.guard(actionSubmit, function () {
      return api(state.action, payload).then(function (r) {
        if (handleAuthLoss(r.status)) return;
        if (!r.ok) {
          showActionError(r.data.message || 'The action failed.', r.data.field);
          if (r.status === 409) { refreshPanel(); }
          return;
        }
        closeDialogById('actionDialog');
        toast(r.data.message || 'Done.', 'success');
        announce(r.data.message || 'Done.');
        if (state.after === 'impersonate' && r.data.destination) {
          var dest = new URL(cfg.appUrl, window.location.href);
          if (r.data.destination === 'owner') dest.pathname = dest.pathname.replace(/app$/, 'owner');
          window.setTimeout(function () { window.location.href = dest.toString(); }, 600);
          return;
        }
        if (r.data.affected_bookings && r.data.affected_bookings.length) {
          toast(r.data.affected_bookings.length + ' upcoming booking(s) were left unchanged — they are listed in the facility details.', 'success');
        }
        refreshPanel();
        if (detailState && document.getElementById('detailDialog').classList.contains('ux-open')) {
          if (/delete/.test(state.action) && r.data.user_id) closeDialogById('detailDialog'); else loadDetail();
        }
      }).catch(function () {
        showActionError('The server could not be reached, so the result is unknown. Refresh to check the current state before trying again' + (state.key ? ' (retrying this same form is safe).' : '.'));
      });
    }, 'Working…');
  });

  actionDialog.addEventListener('animationend', function () {
    actionForm.querySelectorAll('[data-autofocus]').forEach(function (el) { el.removeAttribute('data-autofocus'); });
  });

  /* ---------------------------------------------------------- row menus */
  var openMenu = null;

  function positionFloating(el, anchor) {
    el.hidden = false;
    var r = anchor.getBoundingClientRect();
    var w = el.offsetWidth, h = el.offsetHeight;
    var left = Math.min(window.innerWidth - w - 12, Math.max(12, r.right - w));
    var top = r.bottom + 6;
    if (top + h > window.innerHeight - 12) top = Math.max(12, r.top - h - 6);
    el.style.left = left + 'px';
    el.style.top = top + 'px';
  }

  function closeMenus(returnFocus) {
    if (!openMenu) return;
    openMenu.list.hidden = true;
    openMenu.button.setAttribute('aria-expanded', 'false');
    if (returnFocus) openMenu.button.focus();
    openMenu = null;
  }

  function menuItems(list) { return Array.prototype.filter.call(list.querySelectorAll('[role^="menuitem"]'), function (i) { return !i.disabled; }); }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-menu-button]');
    if (btn) {
      e.preventDefault();
      var list = btn.parentElement.querySelector('[role="menu"]');
      var wasOpen = openMenu && openMenu.button === btn;
      closeMenus();
      if (wasOpen) return;
      positionFloating(list, btn);
      btn.setAttribute('aria-expanded', 'true');
      openMenu = { button: btn, list: list };
      var items = menuItems(list);
      if (items[0]) items[0].focus();
      return;
    }
    if (openMenu && !openMenu.list.contains(e.target)) closeMenus();
  });

  document.addEventListener('keydown', function (e) {
    if (openMenu) {
      var items = menuItems(openMenu.list);
      var idx = items.indexOf(document.activeElement);
      if (e.key === 'ArrowDown') { e.preventDefault(); (items[idx + 1] || items[0]).focus(); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); (items[idx - 1] || items[items.length - 1]).focus(); }
      else if (e.key === 'Home') { e.preventDefault(); items[0].focus(); }
      else if (e.key === 'End') { e.preventDefault(); items[items.length - 1].focus(); }
      else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); closeMenus(true); }
      else if (e.key === 'Tab') { closeMenus(); }
      return;
    }
    if (e.key === 'Escape' && activityOpen) { closeActivity(true); }
    if (e.key === 'Escape' && document.getElementById('adminSidebar').classList.contains('is-open')) { closeNav(true); }
  });
  window.addEventListener('resize', function () { closeMenus(); closeActivity(); });
  document.addEventListener('scroll', function () { closeMenus(); }, true);

  /* ---------------------------------------------------------- activity */
  var activityBtn = document.getElementById('activityBtn');
  var activityPanel = document.getElementById('activityPanel');
  var activityOpen = false;

  function closeActivity(returnFocus) {
    if (!activityOpen) return;
    activityPanel.hidden = true;
    activityOpen = false;
    activityBtn.setAttribute('aria-expanded', 'false');
    if (returnFocus) activityBtn.focus();
  }

  if (activityBtn) {
    activityBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      if (activityOpen) { closeActivity(); return; }
      positionFloating(activityPanel, activityBtn);
      activityOpen = true;
      activityBtn.setAttribute('aria-expanded', 'true');
      var list = document.getElementById('activityList');
      var stateEl = document.getElementById('activityState');
      stateEl.textContent = 'Loading…';
      apiGet('admin_get_activity').then(function (r) {
        if (!r.ok) { stateEl.textContent = 'Could not load activity.'; return; }
        list.innerHTML = '';
        var unseen = r.data.unseen || 0;
        stateEl.textContent = unseen > 0 ? unseen + ' new' : 'Up to date';
        if (!r.data.items.length) {
          var li = document.createElement('li'); li.textContent = 'No platform activity yet.'; list.appendChild(li);
        }
        r.data.items.forEach(function (item, i) {
          var li = document.createElement('li');
          var a = document.createElement('a');
          var url = new URL(cfg.consoleUrl, window.location.href);
          url.searchParams.set('tab', item.tab);
          Object.keys(item.query || {}).forEach(function (k) { url.searchParams.set(k, item.query[k]); });
          a.href = url.toString();
          a.setAttribute('data-panel-link', '');
          a.textContent = item.title;
          if (i < unseen) a.className = 'is-new';
          var meta = document.createElement('p'); meta.className = 'pk-muted pk-small'; meta.textContent = item.detail + ' · ' + item.at;
          li.appendChild(a); li.appendChild(meta); list.appendChild(li);
        });
        positionFloating(activityPanel, activityBtn);
        if (unseen > 0) {
          api('admin_mark_activity_seen', {}).then(function (m) {
            if (m.ok) {
              document.getElementById('activityDot').hidden = true;
              activityBtn.setAttribute('aria-label', 'Recent activity');
            }
          });
        }
      }).catch(function () { stateEl.textContent = 'Network problem — try again.'; });
    });
    document.addEventListener('click', function (e) {
      if (activityOpen && !activityPanel.contains(e.target) && e.target !== activityBtn) closeActivity();
      if (activityOpen && e.target.closest('#activityPanel a')) closeActivity();
    });
  }

  /* ---------------------------------------------------------- nav drawer */
  var sidebar = document.getElementById('adminSidebar');
  var overlay = document.getElementById('sidebarOverlay');
  var navOpener = document.querySelector('[data-open-nav]');

  function openNav() {
    sidebar.classList.add('is-open');
    overlay.hidden = false;
    navOpener.setAttribute('aria-expanded', 'true');
    var current = sidebar.querySelector('.pk-nav-item.is-active') || sidebar.querySelector('.pk-nav-item');
    if (current) current.focus();
  }
  function closeNav(returnFocus) {
    if (!sidebar.classList.contains('is-open')) return;
    sidebar.classList.remove('is-open');
    overlay.hidden = true;
    navOpener.setAttribute('aria-expanded', 'false');
    if (returnFocus) navOpener.focus();
  }
  if (navOpener) navOpener.addEventListener('click', openNav);
  document.addEventListener('click', function (e) { if (e.target.closest('[data-close-nav]')) closeNav(true); });

  /* ---------------------------------------------------------- misc */
  document.addEventListener('click', function (e) {
    var copy = e.target.closest('[data-copy]');
    if (copy) {
      var text = copy.dataset.copy;
      (navigator.clipboard ? navigator.clipboard.writeText(text) : Promise.reject())
        .then(function () { toast('Copied ' + text + ' to the clipboard.'); })
        .catch(function () { toast('Copy failed — select the code and copy it manually.', 'error'); });
    }
    var refresh = e.target.closest('[data-refresh-kpis]');
    if (refresh) {
      var status = document.getElementById('kpiStatus');
      window.UX.guard(refresh, function () {
        return apiGet('admin_stats').then(function (r) {
          if (!r.ok) { status.textContent = ' Could not refresh.'; return; }
          document.querySelectorAll('[data-kpi]').forEach(function (el) {
            var v = r.data[el.dataset.kpi];
            if (v === undefined) return;
            if (el.dataset.kpi === 'booking_value_confirmed') {
              el.textContent = '₱' + Number(v).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            } else if (el.dataset.kpi === 'cancellation_rate') {
              el.textContent = v === null ? 'Not available' : v + '%';
            } else {
              el.textContent = Number(v).toLocaleString('en-PH');
            }
          });
          status.textContent = ' Updated ' + new Date().toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' }) + '.';
        });
      }, 'Refreshing…');
    }
  });
})();
