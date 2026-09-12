/* ==========================================================================
   PICKLERS — Tournament Bracket Console
   Drives /app/owner/tournaments/{id}

   The server is the single source of truth: every mutation posts to api.php,
   which returns the complete re-derived tournament, and the whole canvas is
   re-rendered from it. Nothing about bracket progression is inferred client
   side, so a stale tab can never advance a team the server did not advance.
   ========================================================================== */
(function () {
  'use strict';

  var root = document.getElementById('tbRoot');
  if (!root) return;

  // --------------------------------------------------------------------------
  // Geometry
  // --------------------------------------------------------------------------
  var CARD_W = 236;
  var CARD_H = 92;
  var FOOT_H = 23;
  var COL_GAP = 118;
  var ROW_GAP = 26;
  var COL_PITCH = CARD_W + COL_GAP;
  var ROW_PITCH = CARD_H + ROW_GAP;
  var BAND_GAP = 108;      // vertical space between the winners and losers bands
  var LABEL_BAND = 46;     // room above a band for its section + round labels
  var GUTTER_X = 84;       // left margin the loser-drop rails run down
  var ZOOM_MIN = 0.3;
  var ZOOM_MAX = 1.6;

  var ROW_Y = [(CARD_H - FOOT_H) * 0.25, (CARD_H - FOOT_H) * 0.75];

  // --------------------------------------------------------------------------
  // State
  // --------------------------------------------------------------------------
  var cfg = window.__TB_CONFIG__ || {};
  var state = {
    tournament: window.__TOURNAMENT__ || null,
    show: { W: true, L: true },
    zoom: 1,
    positions: {},
    layout: { width: 0, height: 0 },
    activeMatchId: null,
    pickedWinner: null,
    busy: false
  };

  var el = {
    stage: document.getElementById('tbStage'),
    sizer: document.getElementById('tbSizer'),
    canvas: document.getElementById('tbCanvas'),
    links: null, // built inside the canvas on every render, see render()
    toasts: document.getElementById('tbToasts'),
    progressFill: document.getElementById('tbProgressFill'),
    progressText: document.getElementById('tbProgressText'),
    statusPill: document.getElementById('tbStatusPill'),
    teamCount: document.getElementById('tbTeamCount'),
    crownBtn: document.getElementById('tbCrownBtn')
  };

  var reduceMotion = window.matchMedia &&
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // --------------------------------------------------------------------------
  // Small utilities
  // --------------------------------------------------------------------------
  function esc(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function initials(name) {
    var parts = String(name || '').trim().split(/\s+/).filter(Boolean);
    if (!parts.length) return '?';
    return parts[0].charAt(0).toUpperCase();
  }

  function byId(list, id) {
    for (var i = 0; i < list.length; i++) {
      if (list[i].id === id) return list[i];
    }
    return null;
  }

  function isRealTeam(value) {
    return typeof value === 'string' && value !== '' && value !== '__BYE__';
  }

  function teamOf(teamId) {
    var t = state.tournament;
    if (!t || !isRealTeam(teamId)) return null;
    return (t.team_lookup && t.team_lookup[teamId]) || byId(t.teams || [], teamId);
  }

  function teamName(teamId) {
    var team = teamOf(teamId);
    return team ? team.name : '';
  }

  function toast(message, kind) {
    if (!el.toasts) return;
    var node = document.createElement('div');
    node.className = 'tb-toast tb-toast--' + (kind || 'success');
    node.setAttribute('role', kind === 'error' ? 'alert' : 'status');
    node.textContent = message;
    el.toasts.appendChild(node);
    setTimeout(function () {
      node.classList.add('is-leaving');
      setTimeout(function () {
        if (node.parentNode) node.parentNode.removeChild(node);
      }, 280);
    }, kind === 'error' ? 4200 : 2800);
  }

  // --------------------------------------------------------------------------
  // API
  // --------------------------------------------------------------------------
  function api(action, payload) {
    var body = Object.assign({ action: action, csrf_token: cfg.csrfToken }, payload || {});
    return fetch(cfg.apiUrl + '?action=' + encodeURIComponent(action), {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': cfg.csrfToken,
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: JSON.stringify(body)
    }).then(function (res) {
      return res.json().catch(function () {
        throw new Error('The server sent a response we could not read.');
      }).then(function (data) {
        if (!res.ok || !data || data.success !== true) {
          throw new Error((data && data.message) || 'Request failed (' + res.status + ').');
        }
        return data;
      });
    });
  }

  /**
   * Run a mutation, swap in the server's tournament, and animate whatever
   * actually changed. Errors surface where the user is looking: in the open
   * modal if there is one, otherwise as a toast.
   */
  function mutate(action, payload, opts) {
    opts = opts || {};
    if (state.busy) return Promise.resolve(null);
    state.busy = true;

    var button = opts.button;
    var restore = button ? button.innerHTML : null;
    if (button) {
      button.disabled = true;
      button.innerHTML = '<span class="tb-spinner" aria-hidden="true"></span>' + (opts.busyLabel || 'Working…');
    }

    var before = snapshotResults();

    return api(action, payload).then(function (data) {
      state.tournament = data.tournament || state.tournament;
      var changed = diffResults(before, snapshotResults());
      render({ animate: changed });
      if (opts.successMessage !== false) {
        toast(opts.successMessage || data.message || 'Saved.', 'success');
      }
      if (typeof opts.onSuccess === 'function') opts.onSuccess(data);
      return data;
    }).catch(function (err) {
      var message = err && err.message ? err.message : 'Something went wrong.';
      if (opts.alertEl) showAlert(opts.alertEl, message);
      else toast(message, 'error');
      if (typeof opts.onError === 'function') opts.onError(err);
      return null;
    }).finally(function () {
      state.busy = false;
      if (button) {
        button.disabled = false;
        if (restore !== null) button.innerHTML = restore;
      }
    });
  }

  function snapshotResults() {
    var map = {};
    var matches = (state.tournament && state.tournament.matches) || [];
    for (var i = 0; i < matches.length; i++) {
      map[matches[i].id] = (matches[i].winner || '') + '|' + (matches[i].team1 || '') + '|' + (matches[i].team2 || '');
    }
    return map;
  }

  function diffResults(before, after) {
    var changed = {};
    Object.keys(after).forEach(function (id) {
      if (before[id] !== after[id]) changed[id] = true;
    });
    return changed;
  }

  // --------------------------------------------------------------------------
  // Layout
  // --------------------------------------------------------------------------
  function roundsOf(bracket) {
    var t = state.tournament;
    if (!t || !t.rounds || !t.rounds[bracket]) return [];
    return t.rounds[bracket];
  }

  /**
   * Position every match. Elimination rounds are centred on the pair of matches
   * that feed them, which is what makes the connector lines read as a bracket
   * rather than as a grid.
   */
  function computeLayout() {
    var pos = {};
    var t = state.tournament;
    if (!t) return { positions: pos, width: 0, height: 0, bands: {} };

    var bands = {};
    var cursorY = 0;
    var maxX = 0;

    function placeElimination(bracket, topY) {
      var rounds = roundsOf(bracket);
      if (!rounds.length) return { top: topY, bottom: topY, height: 0 };

      var prevYs = [];
      var bottom = topY;

      for (var r = 0; r < rounds.length; r++) {
        var matches = rounds[r].matches || [];
        var x = GUTTER_X + r * COL_PITCH;
        var ys = [];

        for (var i = 0; i < matches.length; i++) {
          var y;
          if (r === 0) {
            // The opening round sets the rhythm. A losers round 1 covers two
            // winners round 1 matches, so it spreads wider than the column it
            // drains without leaving the band as tall as the winners bracket.
            var pitch = (bracket === 'L') ? ROW_PITCH * 1.6 : ROW_PITCH;
            y = topY + i * pitch;
          } else if (prevYs.length === matches.length) {
            // Same width as the previous round (a losers "major" round): the
            // survivor stays on its own line and waits for a dropdown.
            y = prevYs[i];
          } else {
            var a = prevYs[i * 2];
            var b = prevYs[i * 2 + 1];
            if (a == null) a = topY + i * ROW_PITCH;
            if (b == null) b = a;
            y = (a + b) / 2;
          }

          pos[matches[i].id] = { x: x, y: y, bracket: bracket, round: r };
          ys.push(y);
          if (y + CARD_H > bottom) bottom = y + CARD_H;
          if (x + CARD_W > maxX) maxX = x + CARD_W;
        }

        prevYs = ys;
      }

      return { top: topY, bottom: bottom, height: bottom - topY };
    }

    if (t.format === 'round_robin') {
      var rrRounds = roundsOf('R');
      var top = LABEL_BAND;
      var rrBottom = top;
      for (var r = 0; r < rrRounds.length; r++) {
        var ms = rrRounds[r].matches || [];
        for (var i = 0; i < ms.length; i++) {
          var x = GUTTER_X + r * COL_PITCH;
          var y = top + i * ROW_PITCH;
          pos[ms[i].id] = { x: x, y: y, bracket: 'R', round: r };
          if (y + CARD_H > rrBottom) rrBottom = y + CARD_H;
          if (x + CARD_W > maxX) maxX = x + CARD_W;
        }
      }
      bands.R = { top: top, bottom: rrBottom, label: 'Fixtures' };
      return {
        positions: pos,
        width: maxX + 40,
        height: rrBottom + 40,
        bands: bands
      };
    }

    // Winners band
    var wTop = LABEL_BAND;
    var w = placeElimination('W', wTop);
    bands.W = { top: wTop, bottom: w.bottom, label: 'Winners Bracket' };
    cursorY = w.bottom;

    // Losers band, stacked underneath
    var lRounds = roundsOf('L');
    if (lRounds.length) {
      var lTop = cursorY + BAND_GAP;
      var l = placeElimination('L', lTop);
      bands.L = { top: lTop, bottom: l.bottom, label: 'Losers Bracket' };
      cursorY = l.bottom;
    }

    // Final(s) sit to the right of everything, centred between the two bands.
    var finals = roundsOf('F');
    if (finals.length) {
      var finalX = maxX + COL_GAP;
      var wFinalY = bands.W ? (bands.W.top + bands.W.bottom - CARD_H) / 2 : LABEL_BAND;
      var lFinalY = bands.L ? (bands.L.top + bands.L.bottom - CARD_H) / 2 : wFinalY;
      var centreY = (wFinalY + lFinalY) / 2;

      for (var f = 0; f < finals.length; f++) {
        var fMatches = finals[f].matches || [];
        for (var k = 0; k < fMatches.length; k++) {
          var m = fMatches[k];
          if (m.active === false) continue;
          pos[m.id] = { x: finalX, y: centreY, bracket: 'F', round: f };
          if (finalX + CARD_W > maxX) maxX = finalX + CARD_W;
          finalX += COL_PITCH;
        }
      }
      bands.F = { x: pos.GF ? pos.GF.x : finalX, y: centreY };
    }

    return {
      positions: pos,
      width: maxX + 60,
      height: cursorY + 90,
      bands: bands
    };
  }

  // --------------------------------------------------------------------------
  // Rendering
  // --------------------------------------------------------------------------
  function render(opts) {
    opts = opts || {};
    var t = state.tournament;
    if (!t) return;

    renderHeader();

    if (!t.matches || !t.matches.length) {
      renderEmpty();
      return;
    }

    var layout = computeLayout();
    state.positions = layout.positions;
    state.layout = layout;

    el.canvas.innerHTML = '';
    el.canvas.style.width = layout.width + 'px';
    el.canvas.style.height = layout.height + 'px';

    // Rebuilt inside the canvas on every render so the connectors inherit the
    // same scale transform as the cards they join — a links layer outside it
    // drifts away from the bracket the moment the zoom is not exactly 1.
    el.links = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    el.links.setAttribute('class', 'tb-links');
    el.links.setAttribute('aria-hidden', 'true');
    el.canvas.appendChild(el.links);

    renderBandLabels(layout);
    renderMatches(layout, opts.animate || {});
    renderLinks(layout, opts.animate || {});
    renderChampionTag(layout);
    applyZoom();
    renderStandings();

    maybeCelebrate(opts.animate || {});
  }

  function renderHeader() {
    var t = state.tournament;

    if (el.statusPill) {
      el.statusPill.setAttribute('data-status', t.status);
      el.statusPill.innerHTML = (t.status === 'ongoing' ? '<span class="tb-live-dot"></span>' : '') +
        esc(statusLabel(t.status));
    }

    if (el.teamCount) {
      el.teamCount.textContent = t.teams_registered + (t.teams_registered === 1 ? ' Team' : ' Teams');
    }

    // A corrected result can un-decide a finished event, so this cannot stay as
    // whatever the server rendered when the page loaded.
    if (el.crownBtn) el.crownBtn.hidden = !t.champion_team_id;

    var p = t.progress || { completed: 0, total: 0, percent: 0 };
    if (el.progressFill) el.progressFill.style.width = p.percent + '%';
    if (el.progressText) {
      el.progressText.textContent = p.total
        ? p.completed + ' of ' + p.total + ' matches played'
        : 'Bracket not drawn yet';
    }

    // Section toggles only make sense when both sections exist.
    var hasLosers = roundsOf('L').length > 0;
    document.querySelectorAll('.tb-toggle').forEach(function (btn) {
      var bracket = btn.getAttribute('data-bracket');
      var exists = bracket === 'W' ? roundsOf('W').length > 0 : hasLosers;
      btn.hidden = !exists;
      btn.setAttribute('aria-pressed', state.show[bracket] ? 'true' : 'false');
    });
  }

  function statusLabel(status) {
    return {
      ongoing: 'Active',
      upcoming: 'Registration Open',
      completed: 'Completed',
      cancelled: 'Cancelled'
    }[status] || status;
  }

  function renderEmpty() {
    var t = state.tournament;
    var enough = t.teams_registered >= 2;
    var poolMode = t.pairing_mode === 'mix';

    var message;
    if (poolMode && t.teams_registered === 0) {
      message = t.pool_size
        ? 'You have ' + t.pool_size + ' players in the draw pool. Draw partners to form teams, then generate the bracket.'
        : 'Add players to the draw pool, draw partners to form teams, then generate the bracket.';
    } else if (!enough) {
      message = 'Add at least 2 teams to this tournament and the bracket will draw itself — seeded, byes and all.';
    } else {
      message = t.teams_registered + ' teams are registered. Generating the bracket seeds the draw and sets the tournament live.';
    }

    el.canvas.style.width = '';
    el.canvas.style.height = '';
    el.canvas.innerHTML =
      '<div class="tb-empty">' +
        '<div class="tb-empty-icon">' + trophySvg(28) + '</div>' +
        '<h2>' + (enough ? 'Ready to draw the bracket' : 'No bracket yet') + '</h2>' +
        '<p>' + esc(message) + '</p>' +
        '<div class="tb-empty-actions">' +
          '<button type="button" class="tb-btn" data-action="open-teams">Manage Teams</button>' +
          (enough
            ? '<button type="button" class="tb-btn tb-btn--primary" data-action="generate">Generate Bracket</button>'
            : '') +
        '</div>' +
      '</div>';
    applyZoom();
    renderStandings();
  }

  function renderBandLabels(layout) {
    Object.keys(layout.bands).forEach(function (bracket) {
      var band = layout.bands[bracket];
      if (bracket === 'F' || !band.label) return;
      if (!state.show[bracket] && (bracket === 'W' || bracket === 'L')) return;

      var label = document.createElement('div');
      label.className = 'tb-section-label';
      label.setAttribute('data-bracket', bracket);
      label.textContent = band.label;
      label.style.left = GUTTER_X + 'px';
      label.style.top = (band.top - LABEL_BAND) + 'px';
      el.canvas.appendChild(label);

      var rounds = roundsOf(bracket);
      for (var r = 0; r < rounds.length; r++) {
        var first = (rounds[r].matches || [])[0];
        if (!first || !layout.positions[first.id]) continue;
        var rl = document.createElement('div');
        rl.className = 'tb-round-label';
        rl.textContent = rounds[r].name;
        rl.style.left = layout.positions[first.id].x + 'px';
        rl.style.top = (band.top - 20) + 'px';
        el.canvas.appendChild(rl);
      }
    });

    // Championship column heading
    if (layout.bands.F && state.positions.GF) {
      var cl = document.createElement('div');
      cl.className = 'tb-round-label';
      cl.style.color = 'var(--tb-gold)';
      cl.textContent = '★ Championship ★';
      cl.style.left = state.positions.GF.x + 'px';
      cl.style.top = (state.positions.GF.y - 22) + 'px';
      el.canvas.appendChild(cl);
    }
  }

  function renderMatches(layout, animate) {
    var t = state.tournament;
    var delay = 0;

    (t.matches || []).forEach(function (match) {
      var p = layout.positions[match.id];
      if (!p) return;
      if ((p.bracket === 'W' || p.bracket === 'L') && !state.show[p.bracket]) return;
      if (match.active === false) return;

      var card = document.createElement(isPlayable(match) ? 'button' : 'div');
      card.className = 'tb-match';
      card.setAttribute('data-match', match.id);
      card.setAttribute('data-state', match.status);
      card.setAttribute('data-bracket', p.bracket);
      if (match.is_final) card.setAttribute('data-final', '1');
      card.style.left = p.x + 'px';
      card.style.top = p.y + 'px';

      if (!reduceMotion) {
        card.style.setProperty('--tb-delay', Math.min(delay, 520) + 'ms');
        delay += 14;
      }

      if (isPlayable(match)) {
        card.type = 'button';
        card.setAttribute('data-playable', '1');
        card.setAttribute('aria-label', matchAria(match));
      }

      card.innerHTML =
        teamRow(match, 1, animate) +
        teamRow(match, 2, animate) +
        matchFoot(match);

      if (animate[match.id] && match.winner) card.classList.add('tb-match--just-won');

      el.canvas.appendChild(card);
    });
  }

  function isPlayable(match) {
    if (match.active === false) return false;
    if (match.status === 'bye' || match.status === 'void') return false;
    return isRealTeam(match.team1) && isRealTeam(match.team2);
  }

  function matchAria(match) {
    var a = teamName(match.team1) || 'TBD';
    var b = teamName(match.team2) || 'TBD';
    var verb = match.winner ? 'Edit result for' : 'Report result for';
    return verb + ' ' + match.name + ': ' + a + ' versus ' + b;
  }

  function teamRow(match, slot, animate) {
    var teamId = slot === 1 ? match.team1 : match.team2;
    var score = slot === 1 ? match.score1 : match.score2;
    var classes = ['tb-team'];
    var label;
    var avatars = '';
    var seed = '';

    if (isRealTeam(teamId)) {
      var team = teamOf(teamId);
      label = esc(team ? team.name : 'Unknown team');
      if (team) {
        seed = team.seed ? '<span class="tb-team-seed">#' + esc(team.seed) + '</span>' : '';
        avatars = '<span class="tb-avatars"><span class="tb-avatar">' + esc(initials(team.player1)) + '</span>' +
          (team.player2 ? '<span class="tb-avatar">' + esc(initials(team.player2)) + '</span>' : '') + '</span>';
      }
      if (match.winner === teamId) classes.push('tb-team--winner');
      else if (match.winner) classes.push('tb-team--loser');
      if (animate[match.id]) {
        classes.push(match.winner === teamId ? 'tb-team--flash' : 'tb-team--arrived');
      }
    } else if (teamId === '__BYE__') {
      classes.push('tb-team--empty');
      label = 'Bye';
      avatars = '<span class="tb-avatars"><span class="tb-avatar">–</span></span>';
    } else {
      classes.push('tb-team--empty');
      label = esc(sourceLabel(match, slot));
      avatars = '<span class="tb-avatars"><span class="tb-avatar">?</span></span>';
    }

    var scoreCell = (score === null || score === undefined)
      ? ''
      : '<span class="tb-team-score">' + esc(score) + '</span>';

    return '<span class="' + classes.join(' ') + '">' + avatars +
      '<span class="tb-team-name">' + label + '</span>' + seed + scoreCell + '</span>';
  }

  /** "Winner of W1-2" reads better than an empty slot while a round is pending. */
  function sourceLabel(match, slot) {
    var source = slot === 1 ? match.team1_source : match.team2_source;
    if (!source || !source.match) return 'TBD';
    var feeder = byId((state.tournament.matches || []), source.match);
    var where = feeder ? feeder.name + ' #' + feeder.position : source.match;
    return (source.type === 'loser' ? 'Loser of ' : 'Winner of ') + where;
  }

  function matchFoot(match) {
    var badge, badgeClass;
    if (match.status === 'completed') {
      badge = match.score ? match.score : 'Final';
      badgeClass = 'tb-match-badge';
    } else if (match.status === 'bye') {
      badge = 'Walkover';
      badgeClass = 'tb-match-badge tb-match-badge--bye';
    } else if (match.status === 'void') {
      badge = 'No contest';
      badgeClass = 'tb-match-badge tb-match-badge--wait';
    } else if (isPlayable(match)) {
      badge = 'Report result';
      badgeClass = 'tb-match-badge';
    } else {
      badge = 'Awaiting teams';
      badgeClass = 'tb-match-badge tb-match-badge--wait';
    }

    return '<span class="tb-match-foot"><span>' + esc(match.name) + '</span>' +
      '<span class="' + badgeClass + '">' + esc(badge) + '</span></span>';
  }

  function renderLinks(layout, animate) {
    if (!el.links) return;
    el.links.setAttribute('width', layout.width);
    el.links.setAttribute('height', layout.height);
    el.links.setAttribute('viewBox', '0 0 ' + layout.width + ' ' + layout.height);

    var matches = state.tournament.matches || [];
    var gutterLane = 0;

    matches.forEach(function (match) {
      var from = layout.positions[match.id];
      if (!from) return;
      if ((from.bracket === 'W' || from.bracket === 'L') && !state.show[from.bracket]) return;

      [['next_win', 'win'], ['next_lose', 'lose']].forEach(function (pair) {
        var target = match[pair[0]];
        if (!target || !target.match) return;

        var to = layout.positions[target.match];
        if (!to) return;
        if ((to.bracket === 'W' || to.bracket === 'L') && !state.show[to.bracket]) return;

        var resolved = !!match.winner &&
          (pair[1] === 'win' ? isRealTeam(match.winner) : isRealTeam(match.loser));

        var startX, startY, path;
        var endX = to.x;
        var endY = to.y + ROW_Y[(target.slot === 2) ? 1 : 0];

        if (to.x > from.x) {
          // Forward link: a clean elbow through the gap between columns.
          startX = from.x + CARD_W;
          startY = from.y + CARD_H / 2;
          path = elbow(startX, startY, startX + COL_GAP / 2, endX, endY);
        } else {
          // Backward link (a winners-bracket loser dropping into a losers round
          // that sits further left): route out of the left edge and down a
          // dedicated rail so these never cross the forward lines.
          startX = from.x;
          startY = from.y + CARD_H / 2;
          gutterLane = (gutterLane + 1) % 5;
          var rail = Math.min(from.x, to.x) - 26 - gutterLane * 11;
          path = elbow(startX, startY, rail, endX, endY);
        }

        var node = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        node.setAttribute('d', path);
        node.setAttribute('class', 'tb-link' + (resolved ? ' tb-link--' + pair[1] : ' tb-link--dim'));

        if (resolved && animate[match.id] && !reduceMotion) {
          var length = node.getTotalLength ? 0 : 0;
          node.classList.add('tb-link--animate');
          el.links.appendChild(node);
          try {
            length = node.getTotalLength();
            node.style.setProperty('--tb-len', Math.ceil(length));
          } catch (e) {
            node.style.setProperty('--tb-len', '900');
          }
          return;
        }

        el.links.appendChild(node);
      });
    });
  }

  /** Orthogonal connector with rounded corners. */
  function elbow(x1, y1, mx, x2, y2) {
    if (Math.abs(y1 - y2) < 1.5) {
      return 'M ' + x1 + ' ' + y1 + ' L ' + x2 + ' ' + y2;
    }

    var dirV = y2 > y1 ? 1 : -1;
    var dirH1 = mx > x1 ? 1 : -1;
    var dirH2 = x2 > mx ? 1 : -1;
    var r = Math.min(11, Math.abs(mx - x1), Math.abs(x2 - mx), Math.abs(y2 - y1) / 2);
    if (!isFinite(r) || r < 0) r = 0;

    return [
      'M', x1, y1,
      'L', mx - dirH1 * r, y1,
      'Q', mx, y1, mx, y1 + dirV * r,
      'L', mx, y2 - dirV * r,
      'Q', mx, y2, mx + dirH2 * r, y2,
      'L', x2, y2
    ].join(' ');
  }

  function renderChampionTag(layout) {
    var t = state.tournament;
    if (!t.champion_team_id || !t.champion) return;

    var anchorId = state.positions.GF2 ? 'GF2' : (state.positions.GF ? 'GF' : null);
    if (!anchorId) {
      var wRounds = roundsOf('W');
      var last = wRounds[wRounds.length - 1];
      if (last && last.matches && last.matches[0]) anchorId = last.matches[0].id;
    }
    var anchor = anchorId ? layout.positions[anchorId] : null;
    if (!anchor) return;

    var tag = document.createElement('div');
    tag.className = 'tb-champion-tag';
    tag.style.left = anchor.x + 'px';
    tag.style.top = (anchor.y + CARD_H + 16) + 'px';
    tag.innerHTML = trophySvg(13) + '<span>Champion: ' + esc(t.champion) + '</span>';
    tag.style.setProperty('--tb-delay', '380ms');
    el.canvas.appendChild(tag);
  }

  function renderStandings() {
    var host = document.getElementById('tbStandings');
    if (!host) return;
    var t = state.tournament;

    if (t.format !== 'round_robin' || !t.standings || !t.standings.length) {
      host.innerHTML = '';
      host.hidden = true;
      return;
    }

    host.hidden = false;
    var rows = t.standings.map(function (row) {
      return '<tr>' +
        '<td>' + esc(row.rank) + '</td>' +
        '<td>' + esc(row.team_name) + '</td>' +
        '<td>' + esc(row.played) + '</td>' +
        '<td>' + esc(row.wins) + '</td>' +
        '<td>' + esc(row.losses) + '</td>' +
        '<td>' + esc(row.points_for) + '</td>' +
        '<td>' + (row.diff > 0 ? '+' : '') + esc(row.diff) + '</td>' +
        '</tr>';
    }).join('');

    host.innerHTML =
      '<div class="tb-standings">' +
        '<h3>Standings</h3>' +
        '<table><thead><tr>' +
          '<th>#</th><th>Team</th><th>P</th><th>W</th><th>L</th><th>PF</th><th>Diff</th>' +
        '</tr></thead><tbody>' + rows + '</tbody></table>' +
      '</div>';
  }

  // --------------------------------------------------------------------------
  // Zoom & pan
  // --------------------------------------------------------------------------
  function applyZoom() {
    if (!el.canvas || !el.sizer) return;
    el.canvas.style.transform = 'scale(' + state.zoom + ')';

    var width = state.layout.width || el.canvas.scrollWidth;
    var height = state.layout.height || el.canvas.scrollHeight;
    if (state.tournament && (!state.tournament.matches || !state.tournament.matches.length)) {
      el.sizer.style.width = '';
      el.sizer.style.height = '';
      el.canvas.style.transform = '';
      return;
    }
    el.sizer.style.width = (width * state.zoom) + 'px';
    el.sizer.style.height = (height * state.zoom) + 'px';
  }

  function setZoom(next) {
    state.zoom = Math.max(ZOOM_MIN, Math.min(ZOOM_MAX, Math.round(next * 100) / 100));
    applyZoom();
  }

  function fitToScreen() {
    if (!el.stage || !state.layout.width) return;
    var padding = 48;
    var scaleX = (el.stage.clientWidth - padding) / state.layout.width;
    var scaleY = (el.stage.clientHeight - padding) / state.layout.height;
    setZoom(Math.min(scaleX, scaleY, 1));
    el.stage.scrollTo({ left: 0, top: 0, behavior: reduceMotion ? 'auto' : 'smooth' });
  }

  function initPan() {
    if (!el.stage) return;
    var dragging = false;
    var startX = 0, startY = 0, scrollX = 0, scrollY = 0;

    el.stage.addEventListener('pointerdown', function (e) {
      // Never hijack a click that was aimed at a control.
      if (e.target.closest('.tb-match, button, a, input, select, textarea')) return;
      if (e.button !== 0) return;
      dragging = true;
      startX = e.clientX;
      startY = e.clientY;
      scrollX = el.stage.scrollLeft;
      scrollY = el.stage.scrollTop;
      el.stage.classList.add('is-panning');
      el.stage.setPointerCapture(e.pointerId);
    });

    el.stage.addEventListener('pointermove', function (e) {
      if (!dragging) return;
      el.stage.scrollLeft = scrollX - (e.clientX - startX);
      el.stage.scrollTop = scrollY - (e.clientY - startY);
    });

    ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (evt) {
      el.stage.addEventListener(evt, function (e) {
        if (!dragging) return;
        dragging = false;
        el.stage.classList.remove('is-panning');
        try { el.stage.releasePointerCapture(e.pointerId); } catch (err) { /* already released */ }
      });
    });

    // Ctrl/⌘ + wheel zooms, like every other canvas tool.
    el.stage.addEventListener('wheel', function (e) {
      if (!e.ctrlKey && !e.metaKey) return;
      e.preventDefault();
      setZoom(state.zoom + (e.deltaY > 0 ? -0.08 : 0.08));
    }, { passive: false });
  }

  // --------------------------------------------------------------------------
  // Match result modal
  // --------------------------------------------------------------------------
  var matchModal = {
    root: document.getElementById('tbMatchModal'),
    title: document.getElementById('tbMatchTitle'),
    sub: document.getElementById('tbMatchSub'),
    picks: document.getElementById('tbMatchPicks'),
    scores: document.getElementById('tbMatchScores'),
    score1: document.getElementById('tbScore1'),
    score2: document.getElementById('tbScore2'),
    confirm: document.getElementById('tbMatchConfirm'),
    reset: document.getElementById('tbMatchReset'),
    alert: document.getElementById('tbMatchAlert')
  };

  function openMatchModal(matchId) {
    var match = byId(state.tournament.matches || [], matchId);
    if (!match || !isPlayable(match)) return;

    state.activeMatchId = matchId;
    state.pickedWinner = match.winner && isRealTeam(match.winner) ? match.winner : null;

    var editing = !!match.winner;
    matchModal.title.textContent = editing ? 'Edit Match Result' : 'Select Winner';
    matchModal.sub.textContent = match.name.toUpperCase();
    matchModal.confirm.textContent = editing ? 'Update Winner' : 'Confirm Winner';
    matchModal.reset.hidden = !editing;

    matchModal.picks.innerHTML = [match.team1, match.team2].map(function (teamId, index) {
      var team = teamOf(teamId) || { name: 'Unknown', player1: '', player2: '', seed: index + 1 };
      var players = [team.player1, team.player2].filter(Boolean).join(' & ');
      return '<button type="button" class="tb-pick" data-team="' + esc(teamId) + '" aria-pressed="false">' +
        '<span class="tb-pick-avatars"><span class="tb-avatar">' + esc(initials(team.player1)) + '</span>' +
        (team.player2 ? '<span class="tb-avatar">' + esc(initials(team.player2)) + '</span>' : '') + '</span>' +
        '<span>' +
          '<span class="tb-pick-seed">Seed #' + esc(team.seed || (index + 1)) + '</span>' +
          '<span class="tb-pick-name">' + esc(team.name) + '</span>' +
          (players ? '<span class="tb-pick-players">' + esc(players) + '</span>' : '') +
        '</span>' +
        '<span class="tb-pick-check" aria-hidden="true">' + checkSvg(14) + '</span>' +
      '</button>';
    }).join('');

    matchModal.score1.value = match.score1 === null || match.score1 === undefined ? '' : match.score1;
    matchModal.score2.value = match.score2 === null || match.score2 === undefined ? '' : match.score2;

    syncPicks();
    hideAlert(matchModal.alert);
    openModal(matchModal.root);
  }

  function syncPicks() {
    var picked = state.pickedWinner;
    matchModal.picks.querySelectorAll('.tb-pick').forEach(function (btn) {
      btn.setAttribute('aria-pressed', btn.getAttribute('data-team') === picked ? 'true' : 'false');
    });
    matchModal.confirm.disabled = !picked;
  }

  function submitMatchResult(button) {
    var match = byId(state.tournament.matches || [], state.activeMatchId);
    if (!match || !state.pickedWinner) return;

    var raw1 = matchModal.score1.value.trim();
    var raw2 = matchModal.score2.value.trim();

    if ((raw1 === '') !== (raw2 === '')) {
      showAlert(matchModal.alert, 'Enter both scores, or leave both blank to record the winner only.');
      return;
    }

    var payload = {
      tournament_id: state.tournament.id,
      match_id: match.id,
      winner_team_id: state.pickedWinner
    };

    if (raw1 !== '') {
      var s1 = Number(raw1);
      var s2 = Number(raw2);
      if (!Number.isInteger(s1) || !Number.isInteger(s2) || s1 < 0 || s2 < 0) {
        showAlert(matchModal.alert, 'Scores must be whole numbers of 0 or more.');
        return;
      }
      if (s1 === s2) {
        showAlert(matchModal.alert, 'A match cannot end level. Enter a decisive score.');
        return;
      }
      var leader = s1 > s2 ? match.team1 : match.team2;
      if (leader !== state.pickedWinner) {
        showAlert(matchModal.alert, 'The score says ' + teamName(leader) + ' won. Fix the score or pick the other team.');
        return;
      }
      payload.score1 = s1;
      payload.score2 = s2;
    }

    hideAlert(matchModal.alert);

    mutate('report_tournament_match', payload, {
      button: button,
      busyLabel: 'Saving…',
      alertEl: matchModal.alert,
      successMessage: teamName(state.pickedWinner) + ' advances.',
      onSuccess: function () { closeModal(matchModal.root); }
    });
  }

  function resetMatchResult(button) {
    mutate('reset_tournament_match', {
      tournament_id: state.tournament.id,
      match_id: state.activeMatchId
    }, {
      button: button,
      busyLabel: 'Clearing…',
      alertEl: matchModal.alert,
      successMessage: 'Match result cleared.',
      onSuccess: function () { closeModal(matchModal.root); }
    });
  }

  // --------------------------------------------------------------------------
  // Manage teams modal
  // --------------------------------------------------------------------------
  var teamsModal = {
    root: document.getElementById('tbTeamsModal'),
    body: document.getElementById('tbTeamsBody'),
    alert: document.getElementById('tbTeamsAlert')
  };

  function openTeamsModal() {
    renderTeamsModal();
    openModal(teamsModal.root);
  }

  function renderTeamsModal() {
    var t = state.tournament;
    if (!teamsModal.body) return;

    var locked = t.has_bracket;
    var mix = t.pairing_mode === 'mix';
    var singles = t.is_singles;

    var html = '';

    if (locked) {
      html += '<p class="tb-hint" style="margin-bottom:14px;">The bracket is drawn, so the roster is locked. ' +
        'Reset the bracket to register or remove teams — every reported result will be cleared.</p>';
    }

    // --- Draw pool (mix mode only) ---------------------------------------
    if (mix) {
      html += '<div class="tb-section-head"><h4>Draw Pool (' + t.pool_size + ')</h4>' +
        (locked ? '' : '<button type="button" class="tb-btn tb-btn--ghost" data-action="mix-draw">🎲 Draw Partners</button>') +
        '</div>';

      html += t.pool_size
        ? '<div class="tb-pool">' + (t.players_pool || []).map(function (p, i) {
            return '<span class="tb-pool-chip" style="--tb-delay:' + (i * 18) + 'ms">' + esc(p.name) +
              (locked ? '' : '<button type="button" data-action="remove-player" data-player="' + esc(p.id) +
                '" aria-label="Remove ' + esc(p.name) + ' from the pool">&times;</button>') +
              '</span>';
          }).join('') + '</div>'
        : '<div class="tb-roster-empty">No players in the pool yet. Search below to add them.</div>';

      if (t.unpaired_players && t.unpaired_players.length) {
        html += '<p class="tb-hint">Still without a partner: ' + esc(t.unpaired_players.join(', ')) +
          '. Add one more player and draw again to include them.</p>';
      }

      html += '<div class="tb-divider"></div>';
    }

    // --- Registered teams -------------------------------------------------
    html += '<div class="tb-section-head"><h4>Teams (' + t.teams_registered + ' / ' + t.max_teams + ')</h4></div>';

    html += t.teams_registered
      ? '<div class="tb-roster">' + (t.teams || []).map(function (team, i) {
          var players = [team.player1, team.player2].filter(Boolean).join(' & ');
          return '<div class="tb-roster-row" style="--tb-delay:' + (i * 22) + 'ms">' +
            '<span class="tb-roster-seed">' + esc(team.seed) + '</span>' +
            '<span class="tb-roster-main">' +
              '<span class="tb-roster-name">' + esc(team.name) + '</span>' +
              '<span class="tb-roster-players">' + esc(players || 'No players listed') + '</span>' +
            '</span>' +
            (locked ? '' :
              '<button type="button" class="tb-icon-btn" data-action="rename-team" data-team="' + esc(team.id) +
                '" aria-label="Rename ' + esc(team.name) + '">' + pencilSvg(14) + '</button>' +
              '<button type="button" class="tb-icon-btn tb-icon-btn--danger" data-action="remove-team" data-team="' + esc(team.id) +
                '" aria-label="Remove ' + esc(team.name) + '">' + trashSvg(14) + '</button>') +
          '</div>';
        }).join('') + '</div>'
      : '<div class="tb-roster-empty">' +
          (mix ? 'No teams yet — draw partners from the pool above.' : 'No teams registered yet.') +
        '</div>';

    // --- Add entrant ------------------------------------------------------
    if (!locked && (mix ? t.pool_size < t.max_teams * 2 : t.teams_registered < t.max_teams)) {
      html += '<div class="tb-divider"></div>';
      html += '<div class="tb-section-head"><h4>' +
        (mix ? 'Add Player to Draw Pool' : (singles ? 'Add Player' : 'Add Fixed Team')) +
        '</h4><span class="tb-hint" style="margin:0; color:#FFFFFF;">Search registered players</span></div>';

      html += '<div class="tb-search-wrap" style="margin-top:10px;">' +
        '<label class="tb-label" for="tbPlayer1">' + (mix || singles ? 'Player name' : 'Player 1') + '</label>' +
        '<input type="text" id="tbPlayer1" class="tb-input" autocomplete="off" placeholder="Type a name to search…">' +
        '<div class="tb-suggestions" id="tbSuggest1" role="listbox"></div>' +
        '</div>';

      if (!mix && !singles) {
        html += '<div class="tb-search-wrap" style="margin-top:12px;">' +
          '<label class="tb-label" for="tbPlayer2">Player 2 (partner)</label>' +
          '<input type="text" id="tbPlayer2" class="tb-input" autocomplete="off" placeholder="Type a name to search…">' +
          '<div class="tb-suggestions" id="tbSuggest2" role="listbox"></div>' +
          '</div>';

        html += '<div style="margin-top:12px;">' +
          '<label class="tb-label" for="tbTeamName">Team display name (optional)</label>' +
          '<input type="text" id="tbTeamName" class="tb-input" placeholder="e.g. Metro Manila Picklers">' +
          '</div>';
      }

      html += '<button type="button" class="tb-btn tb-btn--primary" data-action="add-entrant" ' +
        'style="width:100%;margin-top:14px;">' + (mix ? 'Add to Draw Pool' : 'Register Team') + '</button>';
    }

    // --- Bracket controls --------------------------------------------------
    html += '<div class="tb-divider"></div>';
    if (locked) {
      html += '<button type="button" class="tb-btn tb-btn--danger" data-action="reset-bracket" style="width:100%;">' +
        'Reset Bracket &amp; Reopen Registration</button>';
    } else {
      html += '<label class="tb-label" style="display:flex;align-items:center;gap:9px;cursor:pointer;">' +
        '<input type="checkbox" id="tbRandomSeeds" style="accent-color:var(--tb-win);width:16px;height:16px;">' +
        '<span>Randomise seeding before drawing</span></label>';
      html += '<button type="button" class="tb-btn tb-btn--primary" data-action="generate" style="width:100%;margin-top:10px;"' +
        (t.teams_registered >= 2 ? '' : ' disabled') + '>Generate Bracket</button>';
      if (t.teams_registered < 2) {
        html += '<p class="tb-hint">At least 2 teams are needed to draw a bracket.</p>';
      }
    }

    teamsModal.body.innerHTML = html;
    hideAlert(teamsModal.alert);
    wirePlayerSearch();
  }

  function addEntrant(button) {
    var t = state.tournament;
    var mix = t.pairing_mode === 'mix';
    var singles = t.is_singles;

    var p1 = (document.getElementById('tbPlayer1') || {}).value || '';
    var p2 = (document.getElementById('tbPlayer2') || {}).value || '';
    var name = (document.getElementById('tbTeamName') || {}).value || '';

    p1 = p1.trim();
    p2 = p2.trim();

    if (!p1) {
      showAlert(teamsModal.alert, 'Enter a player name first.');
      return;
    }
    if (!mix && !singles && !p2) {
      showAlert(teamsModal.alert, 'A doubles team needs both players.');
      return;
    }

    mutate('add_tournament_entrant', {
      tournament_id: t.id,
      name: p1,
      player1: p1,
      player2: p2,
      team_name: name.trim()
    }, {
      button: button,
      busyLabel: 'Adding…',
      alertEl: teamsModal.alert,
      successMessage: mix ? p1 + ' joined the draw pool.' : 'Team registered.',
      onSuccess: function () { renderTeamsModal(); }
    });
  }

  // --------------------------------------------------------------------------
  // Player search (shared by both entrant fields)
  // --------------------------------------------------------------------------
  var searchTimer = null;

  function wirePlayerSearch() {
    [['tbPlayer1', 'tbSuggest1'], ['tbPlayer2', 'tbSuggest2']].forEach(function (pair) {
      var input = document.getElementById(pair[0]);
      var box = document.getElementById(pair[1]);
      if (!input || !box) return;

      input.addEventListener('input', function () { queueSearch(input, box); });
      input.addEventListener('focus', function () { queueSearch(input, box); });
      input.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { box.classList.remove('is-open'); return; }
        if (e.key !== 'ArrowDown') return;
        var first = box.querySelector('.tb-suggestion');
        if (first) { e.preventDefault(); first.focus(); }
      });
    });
  }

  function queueSearch(input, box) {
    clearTimeout(searchTimer);
    var query = input.value.trim();
    searchTimer = setTimeout(function () { runSearch(query, input, box); }, 160);
  }

  function runSearch(query, input, box) {
    fetch(cfg.apiUrl + '?action=search_players&q=' + encodeURIComponent(query), {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        var users = (data && data.users) || [];
        var taken = new Set();
        if (state && state.tournament) {
          (state.tournament.teams || []).forEach(function (tm) {
            if (tm.player1) taken.add(tm.player1.toLowerCase().trim());
            if (tm.player2) taken.add(tm.player2.toLowerCase().trim());
          });
          (state.tournament.players_pool || []).forEach(function (pp) {
            if (pp.name) taken.add(pp.name.toLowerCase().trim());
          });
        }
        var availableUsers = users.filter(function (u) {
          var uname = (u.name || '').toLowerCase().trim();
          return uname !== '' && !taken.has(uname);
        });

        var qLower = (query || '').toLowerCase().trim();
        if (qLower) {
          availableUsers.sort(function (a, b) {
            var aName = (a.name || '').toLowerCase();
            var bName = (b.name || '').toLowerCase();
            var aScore = aName.indexOf(qLower) === 0 ? 1 : (aName.split(/\s+/).some(function (w) { return w.indexOf(qLower) === 0; }) ? 2 : 3);
            var bScore = bName.indexOf(qLower) === 0 ? 1 : (bName.split(/\s+/).some(function (w) { return w.indexOf(qLower) === 0; }) ? 2 : 3);
            if (aScore !== bScore) return aScore - bScore;
            return aName.localeCompare(bName);
          });
        }

        if (!availableUsers.length) {
          box.innerHTML = '<div class="tb-suggestion-empty">' +
            (users.length ? 'That player is already on the roster.' : 'No registered player matches “' + esc(query) + '”.<br>You can still type the name in full to add them.') +
            '</div>';
          box.classList.add('is-open');
          return;
        }

        box.innerHTML = availableUsers.map(function (u) {
          return '<button type="button" class="tb-suggestion" role="option" data-name="' + esc(u.name) + '">' +
            '<span class="tb-avatar">' + esc(initials(u.name)) + '</span>' +
            '<span><span class="tb-suggestion-name">' + highlight(u.name, query) + '</span>' +
            '<span class="tb-suggestion-email">' + esc(u.email || 'Registered player') + '</span></span>' +
            '</button>';
        }).join('');
        box.classList.add('is-open');

        box.querySelectorAll('.tb-suggestion').forEach(function (btn) {
          btn.addEventListener('click', function () {
            input.value = btn.getAttribute('data-name');
            box.classList.remove('is-open');
            input.focus();
          });
        });
      })
      .catch(function () { box.classList.remove('is-open'); });
  }

  function highlight(text, query) {
    var safe = esc(text);
    if (!query) return safe;
    var pattern = esc(query).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    try {
      return safe.replace(new RegExp('(' + pattern + ')', 'ig'), '<mark>$1</mark>');
    } catch (e) {
      return safe;
    }
  }

  // --------------------------------------------------------------------------
  // Champion celebration
  // --------------------------------------------------------------------------
  var crown = {
    root: document.getElementById('tbCrown'),
    name: document.getElementById('tbCrownName'),
    players: document.getElementById('tbCrownPlayers'),
    eyebrow: document.getElementById('tbCrownEyebrow'),
    confetti: document.getElementById('tbConfetti')
  };

  /** Only fire the reveal on the transition into "won", never on a reload. */
  function maybeCelebrate(animate) {
    var t = state.tournament;
    if (!t.champion_team_id || !Object.keys(animate).length) return;
    openCrown();
  }

  function openCrown() {
    var t = state.tournament;
    if (!crown.root || !t.champion_team_id) return;

    var team = teamOf(t.champion_team_id);
    crown.name.textContent = t.champion || (team && team.name) || 'Champion';
    crown.eyebrow.textContent = 'Champion of ' + t.title;
    var players = team ? [team.player1, team.player2].filter(Boolean).join('  •  ') : '';
    crown.players.textContent = players;
    crown.players.hidden = !players;

    crown.root.classList.add('is-open');
    dropConfetti();

    var first = crown.root.querySelector('button, a');
    if (first) first.focus();
  }

  function closeCrown() {
    if (!crown.root) return;
    crown.root.classList.remove('is-open');
    if (crown.confetti) crown.confetti.innerHTML = '';
  }

  function dropConfetti() {
    if (!crown.confetti || reduceMotion) return;
    crown.confetti.innerHTML = '';
    var colours = ['#00D98B', '#00E5FF', '#FFB800', '#F8FAFC', '#C084FC'];
    for (var i = 0; i < 70; i++) {
      var piece = document.createElement('i');
      piece.style.left = (Math.random() * 100) + '%';
      piece.style.background = colours[i % colours.length];
      piece.style.animationDuration = (2.4 + Math.random() * 2.2) + 's';
      piece.style.animationDelay = (Math.random() * 1.1) + 's';
      piece.style.transform = 'rotate(' + (Math.random() * 360) + 'deg)';
      crown.confetti.appendChild(piece);
    }
  }

  // --------------------------------------------------------------------------
  // Modal plumbing
  // --------------------------------------------------------------------------
  var lastFocused = null;

  function openModal(node) {
    if (!node) return;
    lastFocused = document.activeElement;
    node.classList.add('is-open');
    document.body.style.overflow = 'hidden';
    var focusable = node.querySelector('button, [href], input, select, textarea');
    if (focusable) focusable.focus();
  }

  function closeModal(node) {
    if (!node) return;
    node.classList.remove('is-open');
    if (!document.querySelector('.tb-modal.is-open') && !document.querySelector('.tb-crown.is-open')) {
      document.body.style.overflow = '';
    }
    if (lastFocused && lastFocused.focus) lastFocused.focus();
  }

  function showAlert(node, message) {
    if (!node) { toast(message, 'error'); return; }
    node.textContent = message;
    node.classList.remove('is-shown');
    void node.offsetWidth; // restart the shake
    node.classList.add('is-shown');
  }

  function hideAlert(node) {
    if (node) node.classList.remove('is-shown');
  }

  // Focus trap + Escape for whatever layer is on top.
  document.addEventListener('keydown', function (e) {
    var open = document.querySelector('.tb-crown.is-open') || document.querySelector('.tb-modal.is-open');
    if (!open) return;

    if (e.key === 'Escape') {
      e.preventDefault();
      if (open.classList.contains('tb-crown')) closeCrown();
      else closeModal(open);
      return;
    }

    if (e.key !== 'Tab') return;
    var focusables = open.querySelectorAll(
      'button:not([disabled]):not([hidden]), [href], input:not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])'
    );
    if (!focusables.length) return;
    var first = focusables[0];
    var last = focusables[focusables.length - 1];
    if (e.shiftKey && document.activeElement === first) {
      e.preventDefault();
      last.focus();
    } else if (!e.shiftKey && document.activeElement === last) {
      e.preventDefault();
      first.focus();
    }
  });

  // --------------------------------------------------------------------------
  // Inline SVG
  // --------------------------------------------------------------------------
  function svg(size, body) {
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' + size + '" height="' + size +
      '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" ' +
      'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + body + '</svg>';
  }

  function trophySvg(size) {
    return svg(size, '<path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/>' +
      '<path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/>' +
      '<path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/>');
  }

  function checkSvg(size) { return svg(size, '<polyline points="20 6 9 17 4 12"/>'); }
  function pencilSvg(size) { return svg(size, '<path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/>'); }
  function trashSvg(size) { return svg(size, '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>'); }

  // --------------------------------------------------------------------------
  // Event wiring — one delegated listener for the whole console
  // --------------------------------------------------------------------------
  document.addEventListener('click', function (e) {
    var card = e.target.closest('.tb-match[data-playable="1"]');
    if (card) {
      openMatchModal(card.getAttribute('data-match'));
      return;
    }

    var pick = e.target.closest('.tb-pick');
    if (pick) {
      state.pickedWinner = pick.getAttribute('data-team');
      syncPicks();
      hideAlert(matchModal.alert);
      return;
    }

    var trigger = e.target.closest('[data-action]');
    if (!trigger) {
      // Clicking away closes any open suggestion list.
      if (!e.target.closest('.tb-search-wrap')) {
        document.querySelectorAll('.tb-suggestions.is-open').forEach(function (b) {
          b.classList.remove('is-open');
        });
      }
      return;
    }

    var action = trigger.getAttribute('data-action');
    handleAction(action, trigger, e);
  });

  function handleAction(action, trigger, event) {
    var t = state.tournament;

    switch (action) {
      case 'toggle-bracket': {
        var bracket = trigger.getAttribute('data-bracket');
        // Never let the owner hide both halves and stare at an empty canvas.
        var other = bracket === 'W' ? 'L' : 'W';
        if (state.show[bracket] && !state.show[other]) {
          toast('Keep at least one bracket visible.', 'error');
          return;
        }
        state.show[bracket] = !state.show[bracket];
        render();
        return;
      }

      case 'zoom-in': setZoom(state.zoom + 0.12); return;
      case 'zoom-out': setZoom(state.zoom - 0.12); return;
      case 'zoom-fit': fitToScreen(); return;

      case 'open-teams': openTeamsModal(); return;
      case 'close-modal': closeModal(trigger.closest('.tb-modal')); return;
      case 'close-crown': closeCrown(); return;
      case 'show-crown': openCrown(); return;

      case 'confirm-match': submitMatchResult(trigger); return;

      case 'reset-match':
        if (!window.confirm('Clear this result? Every match it fed will be cleared too.')) return;
        resetMatchResult(trigger);
        return;

      case 'add-entrant': addEntrant(trigger); return;

      case 'remove-team': {
        var teamId = trigger.getAttribute('data-team');
        var team = byId(t.teams || [], teamId);
        if (!window.confirm('Remove ' + (team ? team.name : 'this team') + ' from the tournament?')) return;
        mutate('remove_tournament_team', { tournament_id: t.id, team_id: teamId }, {
          button: trigger,
          alertEl: teamsModal.alert,
          successMessage: 'Team removed.',
          onSuccess: renderTeamsModal
        });
        return;
      }

      case 'rename-team': {
        var id = trigger.getAttribute('data-team');
        var current = byId(t.teams || [], id);
        var next = window.prompt('Team display name', current ? current.name : '');
        if (next === null) return;
        next = next.trim();
        if (!next) { showAlert(teamsModal.alert, 'Team name cannot be empty.'); return; }
        mutate('update_tournament_team', { tournament_id: t.id, team_id: id, name: next }, {
          button: trigger,
          alertEl: teamsModal.alert,
          successMessage: 'Team renamed.',
          onSuccess: renderTeamsModal
        });
        return;
      }

      case 'remove-player': {
        mutate('remove_tournament_player', {
          tournament_id: t.id,
          player_id: trigger.getAttribute('data-player')
        }, {
          button: trigger,
          alertEl: teamsModal.alert,
          successMessage: 'Player removed from the pool.',
          onSuccess: renderTeamsModal
        });
        return;
      }

      case 'mix-draw': {
        if (t.teams_registered && !window.confirm('Redraw partners? The current pairings will be replaced.')) return;
        mutate('mix_tournament_teams', { tournament_id: t.id }, {
          button: trigger,
          busyLabel: 'Drawing…',
          alertEl: teamsModal.alert,
          onSuccess: renderTeamsModal
        });
        return;
      }

      case 'generate': {
        var randomise = !!(document.getElementById('tbRandomSeeds') || {}).checked;
        mutate('generate_tournament_bracket', {
          tournament_id: t.id,
          randomize_seeds: randomise
        }, {
          button: trigger,
          busyLabel: 'Drawing…',
          alertEl: teamsModal.alert,
          onSuccess: function () {
            closeModal(teamsModal.root);
            setTimeout(fitToScreen, 120);
          }
        });
        return;
      }

      case 'reset-bracket': {
        if (!window.confirm('Reset the bracket? Every reported result will be permanently cleared.')) return;
        mutate('reset_tournament_bracket', { tournament_id: t.id }, {
          button: trigger,
          busyLabel: 'Resetting…',
          alertEl: teamsModal.alert,
          onSuccess: renderTeamsModal
        });
        return;
      }

      default:
        return;
    }
  }

  // Backdrop click closes a modal.
  document.querySelectorAll('.tb-modal').forEach(function (modal) {
    modal.addEventListener('mousedown', function (e) {
      if (e.target === modal) closeModal(modal);
    });
  });

  if (matchModal.picks) {
    [matchModal.score1, matchModal.score2].forEach(function (input) {
      if (input) input.addEventListener('input', function () { hideAlert(matchModal.alert); });
    });
  }

  // --------------------------------------------------------------------------
  // Boot
  // --------------------------------------------------------------------------
  initPan();
  render();

  // Start at a zoom that shows the whole draw, once fonts have settled.
  if (state.tournament && state.tournament.matches && state.tournament.matches.length) {
    setTimeout(fitToScreen, 60);
  }

  var resizeTimer = null;
  window.addEventListener('resize', function () {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(applyZoom, 150);
  });

  // Exposed for the page shell (the "View champion" button in the topbar).
  window.TB = {
    reload: function () {
      return api('get_tournament', { tournament_id: state.tournament.id }).then(function (data) {
        state.tournament = data.tournament;
        render();
      });
    },
    showChampion: openCrown
  };
})();
