<?php
/**
 * Owner Portal — Tournaments tab
 *
 * A list of the facility's events. Everything that touches a bracket (seeding,
 * results, advancement) lives on the dedicated console at
 * /app/owner/tournaments/{id}, so this stays a hub: create, review, open.
 *
 * @var array $tournaments  ['ongoing' => [], 'upcoming' => [], 'completed' => []]
 */

use Picklers\Helpers\Url;

/** One tournament card, identical in every lifecycle bucket. */
$renderTournamentCard = static function (array $t): void {
    $detailUrl = Url::to('/app/owner/tournaments/' . rawurlencode((string)$t['id']));
    $progress = $t['progress'] ?? ['completed' => 0, 'total' => 0, 'percent' => 0];
    $capacity = max(1, (int)$t['max_teams']);
    $fillPct = min(100, (int)round(((int)$t['teams_registered'] / $capacity) * 100));

    $dateLabel = trim((string)$t['date']);
    if ($dateLabel !== '' && !empty($t['end_date']) && $t['end_date'] !== $t['date']) {
        $dateLabel .= ' – ' . $t['end_date'];
    }
    ?>
    <article class="tt-card" data-tournament="<?= htmlspecialchars((string)$t['id']) ?>">
      <div class="tt-card-head">
        <div class="tt-card-heading">
          <div class="tt-chips">
            <span class="tt-chip tt-chip--<?= htmlspecialchars($t['status']) ?>">
              <?php if ($t['status'] === 'ongoing'): ?><span class="tt-dot"></span><?php endif; ?>
              <?= htmlspecialchars(match ($t['status']) {
                  'ongoing' => 'Live Bracket',
                  'completed' => 'Completed',
                  'cancelled' => 'Cancelled',
                  default => 'Registration Open',
              }) ?>
            </span>
            <span class="tt-chip tt-chip--cyan"><?= htmlspecialchars($t['category']) ?></span>
            <span class="tt-chip tt-chip--amber"><?= htmlspecialchars($t['format_label']) ?></span>
            <?php if ($t['pairing_mode'] === 'mix'): ?>
              <span class="tt-chip tt-chip--violet">🎲 Mix / Partner Draw</span>
            <?php elseif (empty($t['is_singles'])): ?>
              <span class="tt-chip tt-chip--emerald">⚡ Fixed Teammates</span>
            <?php endif; ?>
          </div>

          <h3 class="tt-title"><?= htmlspecialchars($t['title']) ?></h3>

          <p class="tt-meta">
            <?php if ($dateLabel !== ''): ?><span><?= htmlspecialchars($dateLabel) ?></span><?php endif; ?>
            <?php if ($t['entry_fee'] !== ''): ?><span>Entry <?= htmlspecialchars($t['entry_fee']) ?></span><?php endif; ?>
            <?php if ($t['prize_pool'] !== ''): ?><span class="tt-prize">🏆 <?= htmlspecialchars($t['prize_pool']) ?></span><?php endif; ?>
          </p>
        </div>

        <div class="tt-card-actions">
          <a class="tt-btn tt-btn--primary" href="<?= htmlspecialchars($detailUrl) ?>">
            <?= $t['has_bracket'] ? 'Open Bracket' : 'Set Up Bracket' ?>
          </a>
          <button type="button" class="tt-btn tt-btn--icon" title="Delete tournament"
                  aria-label="Delete <?= htmlspecialchars($t['title']) ?>"
                  onclick="deleteTournament('<?= htmlspecialchars((string)$t['id'], ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($t['title']), ENT_QUOTES) ?>')">
            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
          </button>
        </div>
      </div>

      <div class="tt-stats">
        <div class="tt-stat">
          <span class="tt-stat-label"><?= $t['pairing_mode'] === 'mix' && (int)$t['teams_registered'] === 0 ? 'Draw Pool' : 'Teams' ?></span>
          <span class="tt-stat-value">
            <?php if ($t['pairing_mode'] === 'mix' && (int)$t['teams_registered'] === 0): ?>
              <?= (int)$t['pool_size'] ?> players
            <?php else: ?>
              <?= (int)$t['teams_registered'] ?> / <?= (int)$t['max_teams'] ?>
            <?php endif; ?>
          </span>
          <span class="tt-bar"><span class="tt-bar-fill" style="width: <?= $fillPct ?>%"></span></span>
        </div>

        <div class="tt-stat">
          <span class="tt-stat-label">Bracket Progress</span>
          <span class="tt-stat-value">
            <?= $progress['total'] ? ((int)$progress['completed'] . ' / ' . (int)$progress['total'] . ' matches') : 'Not drawn' ?>
          </span>
          <span class="tt-bar"><span class="tt-bar-fill tt-bar-fill--cyan" style="width: <?= (int)$progress['percent'] ?>%"></span></span>
        </div>

        <div class="tt-stat">
          <span class="tt-stat-label"><?= $t['status'] === 'completed' ? 'Champion' : 'Next Up' ?></span>
          <span class="tt-stat-value <?= $t['status'] === 'completed' ? 'tt-stat-value--gold' : '' ?>">
            <?php if (!empty($t['champion'])): ?>
              🥇 <?= htmlspecialchars($t['champion']) ?>
            <?php elseif ($t['has_bracket']): ?>
              <?= (int)$progress['total'] - (int)$progress['completed'] ?> matches remaining
            <?php elseif ((int)$t['teams_registered'] >= 2): ?>
              Ready to draw
            <?php else: ?>
              Awaiting teams
            <?php endif; ?>
          </span>
        </div>
      </div>
    </article>
    <?php
};

/** Empty-state panel shared by all three buckets. */
$renderEmpty = static function (string $heading, string $body, bool $withCta = false): void {
    ?>
    <div class="tt-empty">
      <div class="tt-empty-icon">
        <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg>
      </div>
      <h3><?= htmlspecialchars($heading) ?></h3>
      <p><?= htmlspecialchars($body) ?></p>
      <?php if ($withCta): ?>
        <button type="button" class="tt-btn tt-btn--primary" onclick="openModal('createTournamentModal')">+ Create Tournament</button>
      <?php endif; ?>
    </div>
    <?php
};
?>

<div class="owner-topbar">
  <div class="owner-topbar-title-wrap">
    <h1>Tournaments</h1>
    <p>Host leagues, cups, and run brackets live.</p>
  </div>
  <button type="button" class="btn-walkin-open" onclick="openModal('createTournamentModal')">
    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
    <span>Create Tournament</span>
  </button>
</div>

<div class="segmented-control">
  <button type="button" class="seg-tab-btn active" id="btnTournOngoing" onclick="switchTournTab('ongoing')">
    Ongoing<?= !empty($tournaments['ongoing']) ? ' (' . count($tournaments['ongoing']) . ')' : '' ?>
  </button>
  <button type="button" class="seg-tab-btn" id="btnTournUpcoming" onclick="switchTournTab('upcoming')">
    Upcoming<?= !empty($tournaments['upcoming']) ? ' (' . count($tournaments['upcoming']) . ')' : '' ?>
  </button>
  <button type="button" class="seg-tab-btn" id="btnTournCompleted" onclick="switchTournTab('completed')">
    Completed<?= !empty($tournaments['completed']) ? ' (' . count($tournaments['completed']) . ')' : '' ?>
  </button>
</div>

<div id="tournOngoingView" class="tt-list">
  <?php if (empty($tournaments['ongoing'])): ?>
    <?php $renderEmpty(
        'No live brackets',
        'Once you draw a bracket for a tournament it moves here, where you can report results round by round.',
        true
    ); ?>
  <?php else: ?>
    <?php foreach ($tournaments['ongoing'] as $t) { $renderTournamentCard($t); } ?>
  <?php endif; ?>
</div>

<div id="tournUpcomingView" class="tt-list" style="display:none;">
  <?php if (empty($tournaments['upcoming'])): ?>
    <?php $renderEmpty(
        'No upcoming tournaments',
        'Create a tournament to open registration, build the roster, and draw the bracket when you are ready.',
        true
    ); ?>
  <?php else: ?>
    <?php foreach ($tournaments['upcoming'] as $t) { $renderTournamentCard($t); } ?>
  <?php endif; ?>
</div>

<div id="tournCompletedView" class="tt-list" style="display:none;">
  <?php if (empty($tournaments['completed'])): ?>
    <?php $renderEmpty(
        'No completed tournaments',
        'Finished events and their champions are archived here.'
    ); ?>
  <?php else: ?>
    <?php foreach ($tournaments['completed'] as $t) { $renderTournamentCard($t); } ?>
  <?php endif; ?>
</div>
