<?php
declare(strict_types=1);
/**
 * Picklers — Tournament Bracket Console
 *
 * Full-page bracket view for one tournament, reached from the owner portal's
 * Tournaments tab at /app/owner/tournaments/{id}.
 *
 * @var array|null  $tournament      Hydrated record, or null for not found
 * @var array|null  $currentFacility
 * @var array|null  $currentUser
 * @var array       $categories
 */

use Picklers\Helpers\Url;
use Picklers\Middleware\CsrfMiddleware;
use Picklers\Services\BracketEngine;

$csrfToken = CsrfMiddleware::getToken();
$backUrl = Url::to('/owner') . '?tab=tournaments';
$pageTitle = $tournament ? $tournament['title'] . ' — Bracket' : 'Tournament Not Found';
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="description" content="Run a live pickleball tournament bracket: seed teams, report results and advance rounds.">
  <meta name="color-scheme" content="dark light">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
  <title><?= htmlspecialchars($pageTitle) ?> | PICKLERS</title>
  <link rel="icon" type="image/svg+xml" href="<?= Url::to('favicon.svg') ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Montserrat:wght@700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= Url::asset('css/style.css', true) ?>">
  <link rel="stylesheet" href="<?= Url::asset('css/theme.css', true) ?>">
  <link rel="stylesheet" href="<?= Url::asset('css/tournament.css', true) ?>">
  <script>
    (function () {
      var saved = localStorage.getItem('picklers_theme') || 'dark';
      document.documentElement.classList.remove('dark', 'light');
      document.documentElement.classList.add(saved === 'light' ? 'light' : 'dark');
    })();
  </script>
</head>
<body>

<?php if (!$tournament): ?>

  <div class="tb-page" style="align-items:center; justify-content:center;">
    <div class="tb-empty">
      <div class="tb-empty-icon" style="color:var(--pk-danger-text); background:var(--pk-danger-bg); border-color:var(--pk-danger-border);">
        <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      </div>
      <h2>Tournament not found</h2>
      <p>This tournament does not exist, or it belongs to a facility your account does not manage.</p>
      <div class="tb-empty-actions">
        <a class="tb-btn tb-btn--primary" href="<?= htmlspecialchars($backUrl) ?>">Back to Tournaments</a>
      </div>
    </div>
  </div>

<?php else: ?>

<?php
  $isElimination = $tournament['format'] !== BracketEngine::FORMAT_ROUND_ROBIN;
  $hasLosers = !empty($tournament['rounds']['L']);
  $dateLabel = trim((string)$tournament['date']);
  if ($dateLabel !== '' && $tournament['end_date'] !== '' && $tournament['end_date'] !== $tournament['date']) {
      $dateLabel .= ' – ' . $tournament['end_date'];
  }
?>

<div class="tb-page" id="tbRoot">

  <!-- Topbar -->
  <header class="tb-topbar">
    <a class="tb-back" href="<?= htmlspecialchars($backUrl) ?>">
      <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
      <span>Back</span>
    </a>

    <h1 class="tb-title"><?= htmlspecialchars($tournament['title']) ?></h1>

    <span class="tb-status-pill" id="tbStatusPill" data-status="<?= htmlspecialchars($tournament['status']) ?>"></span>

    <button type="button" class="tb-btn" data-action="open-teams">
      <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
      <span>Manage Teams</span>
    </button>

    <div class="tb-topbar-spacer"></div>

    <div class="tb-topbar-actions">
      <?php if ($isElimination): ?>
        <button type="button" class="tb-toggle" data-action="toggle-bracket" data-bracket="W" aria-pressed="true">
          <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg>
          <span>Winners Bracket</span>
        </button>
        <button type="button" class="tb-toggle" data-action="toggle-bracket" data-bracket="L" aria-pressed="true" <?= $hasLosers ? '' : 'hidden' ?>>
          <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8.21 13.89 7 23l9-9-9-9 1.21 9.11Z"/><circle cx="12" cy="12" r="10"/></svg>
          <span>Losers Bracket</span>
        </button>
      <?php endif; ?>

      <button type="button" class="tb-btn tb-btn--gold" id="tbCrownBtn" data-action="show-crown"
              <?= empty($tournament['champion_team_id']) ? 'hidden' : '' ?>>
          <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/><path d="M4 22h16"/></svg>
        <span>Champion</span>
      </button>

      <span class="tb-count-chip" id="tbTeamCount"><?= (int)$tournament['teams_registered'] ?> Teams</span>
    </div>
  </header>

  <!-- Meta strip -->
  <div class="tb-metabar">
    <span class="tb-chip tb-chip--accent"><?= htmlspecialchars($tournament['format_label']) ?></span>
    <span class="tb-chip"><?= htmlspecialchars($tournament['category']) ?></span>
    <?php if ($tournament['pairing_mode'] === 'mix'): ?>
      <span class="tb-chip tb-chip--mix">🎲 Mix / Partner Draw</span>
    <?php elseif (!$tournament['is_singles']): ?>
      <span class="tb-chip">⚡ Fixed Teammates</span>
    <?php endif; ?>
    <?php if ($dateLabel !== ''): ?>
      <span class="tb-chip"><?= htmlspecialchars($dateLabel) ?></span>
    <?php endif; ?>
    <?php if ($tournament['prize_pool'] !== ''): ?>
      <span class="tb-chip tb-chip--gold">🏆 <?= htmlspecialchars($tournament['prize_pool']) ?></span>
    <?php endif; ?>
    <?php if ($tournament['entry_fee'] !== ''): ?>
      <span class="tb-chip">Entry: <?= htmlspecialchars($tournament['entry_fee']) ?></span>
    <?php endif; ?>

    <div class="tb-progress-wrap">
      <span id="tbProgressText">Bracket not drawn yet</span>
      <span class="tb-progress-track"><span class="tb-progress-fill" id="tbProgressFill"></span></span>
    </div>
  </div>

  <!-- Standings (round robin only) -->
  <div id="tbStandings" style="padding:20px 24px 0;" hidden></div>

  <!-- Bracket stage -->
  <div class="tb-stage" id="tbStage">
    <div class="tb-canvas-sizer" id="tbSizer">
      <div class="tb-canvas" id="tbCanvas"></div>
    </div>
  </div>

  <!-- Zoom rail -->
  <div class="tb-zoom">
    <button type="button" class="tb-zoom-btn" data-action="zoom-in" aria-label="Zoom in">
      <svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/><line x1="11" y1="8" x2="11" y2="14"/><line x1="8" y1="11" x2="14" y2="11"/></svg>
    </button>
    <button type="button" class="tb-zoom-btn" data-action="zoom-out" aria-label="Zoom out">
      <svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/><line x1="8" y1="11" x2="14" y2="11"/></svg>
    </button>
    <button type="button" class="tb-zoom-btn" data-action="zoom-fit" aria-label="Fit bracket to screen">
      <svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 3H5a2 2 0 0 0-2 2v3"/><path d="M21 8V5a2 2 0 0 0-2-2h-3"/><path d="M3 16v3a2 2 0 0 0 2 2h3"/><path d="M16 21h3a2 2 0 0 0 2-2v-3"/></svg>
    </button>
  </div>

  <!-- Match result modal -->
  <div class="tb-modal" id="tbMatchModal" role="dialog" aria-modal="true" aria-labelledby="tbMatchTitle">
    <div class="tb-modal-card">
      <div class="tb-modal-head">
        <span class="tb-modal-icon">
          <svg xmlns="http://www.w3.org/2000/svg" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg>
        </span>
        <div>
          <h2 class="tb-modal-title" id="tbMatchTitle">Select Winner</h2>
          <p class="tb-modal-sub" id="tbMatchSub"></p>
        </div>
        <button type="button" class="tb-modal-close" data-action="close-modal" aria-label="Close">&times;</button>
      </div>

      <div class="tb-modal-body">
        <div id="tbMatchPicks"></div>

        <div class="tb-score-row" id="tbMatchScores">
          <div>
            <label class="tb-label" for="tbScore1">Score</label>
            <input type="number" id="tbScore1" class="tb-input tb-input--score" min="0" max="999" inputmode="numeric" placeholder="–">
          </div>
          <span>vs</span>
          <div>
            <label class="tb-label" for="tbScore2">Score</label>
            <input type="number" id="tbScore2" class="tb-input tb-input--score" min="0" max="999" inputmode="numeric" placeholder="–">
          </div>
        </div>
        <p class="tb-hint">Scores are optional — pick a winner and the bracket advances either way.</p>

        <div class="tb-alert" id="tbMatchAlert" role="alert"></div>

        <div class="tb-modal-actions">
          <button type="button" class="tb-btn" data-action="close-modal">Cancel</button>
          <button type="button" class="tb-btn tb-btn--primary" id="tbMatchConfirm" data-action="confirm-match" disabled>Confirm Winner</button>
        </div>

        <button type="button" class="tb-btn tb-btn--danger" id="tbMatchReset" data-action="reset-match" style="width:100%; margin-top:10px;" hidden>
          ↺ Reset Match Result
        </button>
      </div>
    </div>
  </div>

  <!-- Manage teams modal -->
  <div class="tb-modal" id="tbTeamsModal" role="dialog" aria-modal="true" aria-labelledby="tbTeamsTitle">
    <div class="tb-modal-card tb-modal-card--wide">
      <div class="tb-modal-head">
        <span class="tb-modal-icon" style="color:var(--tb-cyan); background:rgba(0,229,255,.12); border-color:rgba(0,229,255,.3);">
          <svg xmlns="http://www.w3.org/2000/svg" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        </span>
        <div>
          <h2 class="tb-modal-title" id="tbTeamsTitle">Manage Teams</h2>
          <p class="tb-modal-sub">Roster, seeding &amp; bracket</p>
        </div>
        <button type="button" class="tb-modal-close" data-action="close-modal" aria-label="Close">&times;</button>
      </div>

      <div class="tb-modal-body">
        <div class="tb-alert" id="tbTeamsAlert" role="alert"></div>
        <div id="tbTeamsBody"></div>
      </div>
    </div>
  </div>

  <!-- Champion reveal -->
  <div class="tb-crown" id="tbCrown" role="dialog" aria-modal="true" aria-labelledby="tbCrownName">
    <div class="tb-confetti" id="tbConfetti" aria-hidden="true"></div>
    <div class="tb-crown-trophy">
      <svg xmlns="http://www.w3.org/2000/svg" width="62" height="62" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg>
    </div>
    <p class="tb-crown-eyebrow" id="tbCrownEyebrow"></p>
    <h2 class="tb-crown-name" id="tbCrownName"></h2>
    <p class="tb-crown-players" id="tbCrownPlayers"></p>
    <div class="tb-crown-actions">
      <button type="button" class="tb-btn tb-btn--primary" data-action="close-crown">View Final Bracket</button>
      <a class="tb-btn" href="<?= htmlspecialchars($backUrl) ?>">Back to Tournaments</a>
    </div>
  </div>

  <div class="tb-toast-rail" id="tbToasts" aria-live="polite"></div>
</div>

<script>
  window.__TOURNAMENT__ = <?= json_encode(
      $tournament,
      JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
  ) ?>;
  window.__TB_CONFIG__ = {
    apiUrl: <?= json_encode(Url::to('/api'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    backUrl: <?= json_encode($backUrl, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    csrfToken: <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
  };
</script>
<script src="<?= Url::asset('js/tournament.js', true) ?>"></script>

<?php endif; ?>

</body>
</html>
