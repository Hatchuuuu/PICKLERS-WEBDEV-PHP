<!-- ====================================================================
     Player-facing tournament section — fetched via my_tournaments API.
     Renders as a collapsible card strip below Open Play.
     ==================================================================== -->
<section id="myTournamentsSection" style="margin-top:32px;">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
    <div>
      <h2 style="font-family:'Montserrat',var(--font-heading),sans-serif;font-size:20px;font-weight:800;color:#FFFFFF;margin:0 0 4px;">My Tournaments</h2>
      <p style="font-size:12px;color:#94A3B8;margin:0;">Events you are enrolled in</p>
    </div>
  </div>

  <!-- Loading state -->
  <div id="myTournamentsLoading" style="display:flex;gap:12px;overflow-x:auto;padding-bottom:4px;">
    <?php for ($ti = 0; $ti < 2; $ti++): ?>
      <div class="pk-skeleton" style="min-width:200px;height:120px;border-radius:16px;flex-shrink:0;"></div>
    <?php endfor; ?>
  </div>

  <!-- Empty state -->
  <div id="myTournamentsEmpty" style="display:none;background:rgba(17,35,61,0.5);border:1px solid rgba(255,255,255,0.07);border-radius:16px;padding:24px;text-align:center;">
    <p style="font-size:14px;color:#64748B;margin:0;">You have not been enrolled in any tournaments yet.</p>
  </div>

  <!-- Cards strip -->
  <div id="myTournamentsCards" style="display:none;display:flex;gap:14px;overflow-x:auto;padding-bottom:8px;"></div>
</section>

<template id="tmplTournamentCard">
  <div class="my-tournament-card" style="min-width:220px;max-width:260px;flex-shrink:0;background:rgba(17,35,61,0.65);border:1px solid rgba(255,255,255,0.09);border-radius:16px;padding:16px;display:flex;flex-direction:column;gap:10px;cursor:default;">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px;">
      <span class="mt-title" style="font-size:14px;font-weight:700;color:#FFFFFF;line-height:1.3;flex:1;"></span>
      <span class="mt-status-badge" style="font-size:10px;font-weight:700;border-radius:9999px;padding:3px 9px;white-space:nowrap;"></span>
    </div>
    <div style="font-size:12px;color:#94A3B8;display:flex;flex-direction:column;gap:4px;">
      <span class="mt-venue"></span>
      <span class="mt-format"></span>
    </div>
    <div class="mt-bracket-snippet" style="font-size:11px;color:#64748B;border-top:1px solid rgba(255,255,255,0.06);padding-top:8px;"></div>
  </div>
</template>

<style>
  #myTournamentsCards::-webkit-scrollbar { height: 4px; }
  #myTournamentsCards::-webkit-scrollbar-track { background: transparent; }
  #myTournamentsCards::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.12); border-radius: 9999px; }
  .my-tournament-card:hover { border-color: rgba(0,217,139,0.3); }
</style>

<script>
(function () {
  const STATUS_COLORS = {
    upcoming:  { bg: 'rgba(59,130,246,0.18)',  color: '#60A5FA' },
    ongoing:   { bg: 'rgba(16,185,129,0.18)',  color: '#34D399' },
    completed: { bg: 'rgba(100,116,139,0.18)', color: '#94A3B8' },
    cancelled: { bg: 'rgba(239,68,68,0.18)',   color: '#F87171' },
  };

  function statusStyle(status) {
    const s = STATUS_COLORS[status] || STATUS_COLORS.upcoming;
    return `background:${s.bg};color:${s.color};`;
  }

  function bracketSnippet(t) {
    const matches = t.matches || [];
    if (!matches.length) return 'Bracket not yet generated';
    const pending  = matches.filter(m => (m.status || 'pending') === 'pending').length;
    const played   = matches.filter(m => (m.status || '') === 'completed').length;
    const champion = t.champion_team_id ? 'Champion decided' : null;
    if (champion) return champion;
    return `${played}/${matches.length} match${matches.length !== 1 ? 'es' : ''} played · ${pending} remaining`;
  }

  function formatLabel(fmt) {
    const map = { single_elimination: 'Single Elimination', double_elimination: 'Double Elimination', round_robin: 'Round Robin', pool_play: 'Pool Play', mixed_doubles: 'Mixed Doubles' };
    return map[fmt] || fmt || 'Tournament';
  }

  function renderCards(tournaments) {
    const strip = document.getElementById('myTournamentsCards');
    const tmpl  = document.getElementById('tmplTournamentCard');
    strip.innerHTML = '';

    tournaments.forEach(function (t) {
      const clone = tmpl.content.cloneNode(true);
      const card  = clone.querySelector('.my-tournament-card');
      clone.querySelector('.mt-title').textContent       = t.title || 'Tournament';
      const badge = clone.querySelector('.mt-status-badge');
      badge.textContent  = (t.status || 'upcoming').charAt(0).toUpperCase() + (t.status || 'upcoming').slice(1);
      badge.style.cssText += statusStyle(t.status || 'upcoming');
      clone.querySelector('.mt-venue').textContent       = t.venue || t.facility_name || '';
      clone.querySelector('.mt-format').textContent      = formatLabel(t.format);
      clone.querySelector('.mt-bracket-snippet').textContent = bracketSnippet(t);
      strip.appendChild(clone);
    });
  }

  async function loadMyTournaments() {
    try {
      const res  = await fetch('api.php?action=my_tournaments', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      const data = await res.json();
      const ts   = (data && data.tournaments) ? data.tournaments : [];

      document.getElementById('myTournamentsLoading').style.display = 'none';

      if (!ts.length) {
        document.getElementById('myTournamentsEmpty').style.display = 'block';
        return;
      }

      document.getElementById('myTournamentsCards').style.display = 'flex';
      renderCards(ts);
    } catch (_) {
      document.getElementById('myTournamentsLoading').style.display = 'none';
      document.getElementById('myTournamentsEmpty').style.display   = 'block';
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', loadMyTournaments);
  } else {
    loadMyTournaments();
  }
})();
</script>
