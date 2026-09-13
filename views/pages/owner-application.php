<?php
declare(strict_types=1);
/**
 * Picklers - Court Owner Application Wizard (3-Step Onboarding Pipeline)
 * @var array|null $currentUser
 * @var string $notice
 */
?>
<!--
  THESIS: Onboarding as a real verification session, not a generic web form —
  the wizard reads like the GCash/Maya-style identity check this audience
  already trusts, refusing the flat "step 1/2/3 circles" template every
  onboarding wizard defaults to.
  OWN-WORLD: PICKLERS' own documented system (docs/PICKLERS_BRAND_GUIDE.md) —
  Dark Navy #0A121F ground, Mint Green #55C39E-family accent, Sky Blue
  #69A2D0 secondary, Montserrat wordmark, Inter everywhere else. No new
  palette or type system introduced; expanded, not replaced.
  STORY: an applicant sees this is a real licensing check, not a formality —
  one focused screen per concern, a progress ring instead of a decorative
  stepper, and a document capture step framed like a bank's ID scanner.
  FIRST VIEWPORT: header, hero (stat line preserved verbatim), a single
  verification card: progress ring + step name at top, one focused field
  group below, primary action bottom-right.
  FORM: established-world surface expansion, user-selected "verification
  session" structure (AskUserQuestion round, this session).
  FINISH: unreviewed and undocumented is unfinished; this build ends with
  the finish review, the verdict, DESIGN.md, and every shipping raster
  carrying its provenance.
-->
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>List Your Court | Owner Onboarding Pipeline — PICKLERS</title>
  <link rel="icon" type="image/svg+xml" href="favicon.svg">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Montserrat:wght@700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
  <style>
    :root {
      /* PICKLERS brand system (docs/PICKLERS_BRAND_GUIDE.md) — expanded here,
         not replaced. Mint/navy/sky-blue stay the only accent hues. */
      --bg-dark: #0A121F;
      --card-dark: #0E1A2D;
      --card-border: rgba(255, 255, 255, 0.08);
      --mint: #00D98B;
      --mint-dim: rgba(0, 217, 139, 0.14);
      --sky: #69A2D0;
      --sky-dim: rgba(105, 162, 208, 0.14);
      --accent-amber: #FFB800;
      --accent-coral: #EF4444;
      --text-white: #FFFFFF;
      --text-muted: #94A3B8;
      --ease: cubic-bezier(0.16, 1, 0.3, 1);
    }
    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }
    html {
      color-scheme: dark;
    }
    body {
      background-color: var(--bg-dark);
      color: #F8FAFC;
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
      min-height: 100vh;
      -webkit-font-smoothing: antialiased;
      display: flex;
      flex-direction: column;
    }
    ::selection {
      background: rgba(0, 217, 139, 0.35);
      color: #FFFFFF;
    }
    :focus-visible {
      outline: 2px solid var(--mint);
      outline-offset: 2px;
      border-radius: 6px;
    }

    /* Top Navigation */
    .app-header {
      background: rgba(14, 26, 45, 0.85);
      backdrop-filter: blur(16px);
      border-bottom: 1px solid rgba(255, 255, 255, 0.08);
      padding: 16px 32px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      position: sticky;
      top: 0;
      z-index: 50;
    }
    .brand-wrap {
      display: flex;
      align-items: center;
      gap: 8px;
      text-decoration: none;
    }
    .brand-logo {
      width: 40px;
      height: 40px;
      object-fit: contain;
      flex-shrink: 0;
    }
    .brand-title {
      font-family: 'Montserrat', sans-serif;
      font-size: 19px;
      font-weight: 900;
      letter-spacing: -0.02em;
      color: var(--mint);
      line-height: 1.2;
    }
    .btn-ghost-back {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 8px 16px;
      border-radius: 9999px;
      background: rgba(255, 255, 255, 0.05);
      border: 1px solid rgba(255, 255, 255, 0.1);
      color: #94A3B8;
      font-size: 13px;
      font-weight: 600;
      text-decoration: none;
      transition: color 0.2s var(--ease), background 0.2s var(--ease), border-color 0.2s var(--ease);
    }
    .btn-ghost-back:hover {
      color: #FFFFFF;
      background: rgba(255, 255, 255, 0.1);
      border-color: rgba(255, 255, 255, 0.2);
    }

    /* Main Container */
    .main-wizard-container {
      flex: 1;
      max-width: 720px;
      width: 100%;
      margin: 48px auto 80px;
      padding: 0 20px;
    }

    /* Notice Banner */
    .notice-card {
      background: rgba(255, 184, 0, 0.1);
      border: 1px solid rgba(255, 184, 0, 0.3);
      border-radius: 16px;
      padding: 14px 20px;
      display: flex;
      align-items: flex-start;
      gap: 14px;
      color: #FFB800;
      margin-bottom: 28px;
      font-size: 14px;
      font-weight: 600;
      line-height: 1.4;
    }
    .notice-card svg { flex-shrink: 0; margin-top: 1px; }

    /* Hero Header — no eyebrow/kicker above the heading; the heading and the
       verification card's own step framing carry the context instead. */
    .wizard-hero {
      text-align: center;
      margin-bottom: 40px;
    }
    .wizard-hero h1 {
      font-size: 34px;
      font-weight: 900;
      letter-spacing: -0.02em;
      color: #FFFFFF;
      margin-bottom: 10px;
    }
    .wizard-hero p {
      font-size: 15px;
      color: #94A3B8;
      max-width: 560px;
      margin: 0 auto;
      line-height: 1.55;
    }

    /* ====================================================================
       Verification Session Card — the signature structure for this surface.
       A real progress ring (not a decorative stepper) plus one focused
       screen per concern, framed like the identity-verification flow this
       audience already trusts from GCash/Maya.
       ==================================================================== */
    .verify-card {
      background: var(--card-dark);
      border: 1px solid var(--card-border);
      border-radius: 24px;
      box-shadow: 0 24px 60px rgba(0, 0, 0, 0.45);
      overflow: hidden;
    }
    .verify-progress {
      display: flex;
      align-items: center;
      gap: 18px;
      padding: 28px 36px;
      background: linear-gradient(180deg, rgba(0, 217, 139, 0.06), transparent);
      border-bottom: 1px solid var(--card-border);
    }
    .verify-ring {
      position: relative;
      width: 56px;
      height: 56px;
      flex-shrink: 0;
    }
    .verify-ring svg { transform: rotate(-90deg); width: 100%; height: 100%; }
    .verify-ring-track { fill: none; stroke: rgba(255, 255, 255, 0.1); stroke-width: 6; }
    .verify-ring-fill {
      fill: none;
      stroke: var(--mint);
      stroke-width: 6;
      stroke-linecap: round;
      stroke-dasharray: 251.2;
      stroke-dashoffset: 167.5;
      transition: stroke-dashoffset 0.5s var(--ease);
      filter: drop-shadow(0 0 6px rgba(0, 217, 139, 0.55));
    }
    .verify-ring-label {
      position: absolute;
      inset: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 18px;
      font-weight: 900;
      color: #FFFFFF;
    }
    .verify-progress-meta { min-width: 0; }
    .verify-step-eyebrow {
      font-size: 11px;
      font-weight: 800;
      letter-spacing: 0.08em;
      color: var(--mint);
      text-transform: uppercase;
      margin-bottom: 3px;
    }
    .verify-step-name {
      font-size: 18px;
      font-weight: 800;
      color: #FFFFFF;
    }

    .verify-form { padding: 36px; }

    .verify-screen { display: none; }
    .verify-screen.active {
      display: block;
      animation: screenIn 0.35s var(--ease);
    }
    @keyframes screenIn {
      from { opacity: 0; transform: translateY(10px); }
      to { opacity: 1; transform: translateY(0); }
    }
    .verify-screen-intro {
      font-size: 13px;
      color: #94A3B8;
      line-height: 1.5;
      margin-bottom: 26px;
    }

    /* Form Fields */
    .form-group {
      margin-bottom: 22px;
    }
    .form-label {
      display: block;
      font-size: 13px;
      font-weight: 700;
      color: #E2E8F0;
      margin-bottom: 8px;
      letter-spacing: 0.2px;
    }
    .form-hint {
      font-size: 12px;
      color: #94A3B8;
      margin-top: 6px;
      line-height: 1.4;
    }
    .input-field {
      width: 100%;
      background: rgba(10, 22, 40, 0.8);
      border: 1px solid rgba(255, 255, 255, 0.1);
      border-radius: 12px;
      padding: 14px 18px;
      font-size: 14px;
      font-family: inherit;
      color: #FFFFFF;
      outline: none;
      transition: border-color 0.2s var(--ease), box-shadow 0.2s var(--ease);
    }
    .input-field::placeholder { color: #4B5B72; }
    .input-field:focus {
      border-color: var(--mint);
      box-shadow: 0 0 0 3px var(--mint-dim);
    }
    .input-field.input-hero {
      font-size: 17px;
      font-weight: 700;
      padding: 16px 20px;
    }
    .form-grid-2 {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 20px;
    }

    /* GPS Auto-Detect Button */
    .btn-gps-detect {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background: var(--sky-dim);
      border: 1px solid rgba(105, 162, 208, 0.4);
      color: var(--sky);
      font-weight: 700;
      font-size: 13px;
      padding: 10px 18px;
      border-radius: 12px;
      cursor: pointer;
      transition: background 0.2s var(--ease), transform 0.2s var(--ease);
      margin-top: 10px;
      font-family: inherit;
    }
    .btn-gps-detect:hover { background: rgba(105, 162, 208, 0.24); transform: translateY(-1px); }
    .btn-gps-detect:active { transform: translateY(0); }

    /* Court Surface Pills */
    .surface-selector-wrap {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      margin-top: 8px;
    }
    .surface-pill-option {
      flex: 1;
      min-width: 140px;
      text-align: center;
      padding: 14px 16px;
      border-radius: 14px;
      background: rgba(255, 255, 255, 0.03);
      border: 1.5px solid rgba(255, 255, 255, 0.1);
      color: #94A3B8;
      font-size: 13px;
      font-weight: 700;
      cursor: pointer;
      transition: border-color 0.2s var(--ease), background 0.2s var(--ease), color 0.2s var(--ease);
    }
    .surface-pill-option:hover {
      border-color: rgba(255, 255, 255, 0.25);
      color: #FFFFFF;
    }
    .surface-pill-option.active {
      border-color: var(--mint);
      background: var(--mint-dim);
      color: var(--mint);
    }

    /* ====================================================================
       Document Capture — the signature moment for Step 3. Framed like a
       bank ID-scanner (corner brackets, capture frame) rather than a plain
       file dropzone, since this is the step the whole verification exists
       to serve.
       ==================================================================== */
    .capture-frame {
      position: relative;
      border: 2px dashed rgba(255, 255, 255, 0.18);
      background: rgba(10, 22, 40, 0.6);
      border-radius: 18px;
      padding: 30px 20px;
      min-height: 150px;
      display: flex;
      align-items: center;
      justify-content: center;
      text-align: center;
      cursor: pointer;
      transition: border-color 0.25s var(--ease), background 0.25s var(--ease), box-shadow 0.25s var(--ease);
    }
    .capture-frame:hover,
    .capture-frame.dragover {
      border-color: var(--mint);
      background: rgba(0, 217, 139, 0.05);
    }
    .capture-frame.filled {
      border-style: solid;
      border-color: var(--mint);
      background: rgba(0, 217, 139, 0.07);
      box-shadow: 0 0 0 4px var(--mint-dim);
    }
    .capture-bracket {
      position: absolute;
      width: 22px;
      height: 22px;
      border-color: rgba(255, 255, 255, 0.3);
      transition: border-color 0.25s var(--ease);
    }
    .capture-frame.filled .capture-bracket { border-color: var(--mint); }
    .capture-bracket.tl { top: 10px; left: 10px; border-top: 3px solid; border-left: 3px solid; border-top-left-radius: 8px; }
    .capture-bracket.tr { top: 10px; right: 10px; border-top: 3px solid; border-right: 3px solid; border-top-right-radius: 8px; }
    .capture-bracket.bl { bottom: 10px; left: 10px; border-bottom: 3px solid; border-left: 3px solid; border-bottom-left-radius: 8px; }
    .capture-bracket.br { bottom: 10px; right: 10px; border-bottom: 3px solid; border-right: 3px solid; border-bottom-right-radius: 8px; }
    .capture-idle, .capture-done {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 4px;
    }
    .capture-done { display: none; }
    .capture-frame.filled .capture-idle { display: none; }
    .capture-frame.filled .capture-done { display: flex; animation: captureConfirm 0.4s var(--ease); }
    @keyframes captureConfirm {
      from { opacity: 0; transform: scale(0.9); }
      to { opacity: 1; transform: scale(1); }
    }
    .capture-icon-wrap {
      width: 44px;
      height: 44px;
      border-radius: 50%;
      background: rgba(255, 255, 255, 0.05);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      color: var(--mint);
      margin-bottom: 6px;
    }
    .capture-frame.filled .capture-icon-wrap { background: var(--mint-dim); }
    .capture-text {
      font-size: 14px;
      font-weight: 700;
      color: #FFFFFF;
    }
    .capture-sub {
      font-size: 12px;
      color: #64748B;
    }
    .capture-done-name {
      font-size: 13px;
      font-weight: 700;
      color: #FFFFFF;
      max-width: 260px;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
    .capture-done-label {
      font-size: 12px;
      font-weight: 700;
      color: var(--mint);
      letter-spacing: 0.02em;
    }

    /* Terms + Error */
    .terms-row {
      margin-top: 22px;
      padding: 14px 18px;
      background: rgba(0, 217, 139, 0.06);
      border: 1px solid rgba(0, 217, 139, 0.2);
      border-radius: 12px;
      display: flex;
      align-items: flex-start;
      gap: 12px;
    }
    .terms-row input[type="checkbox"] { margin-top: 3px; accent-color: var(--mint); width: 16px; height: 16px; }
    .terms-row label { font-size: 12px; color: #94A3B8; line-height: 1.45; }
    .form-error-banner {
      display: none;
      margin-top: 16px;
      padding: 12px 16px;
      background: rgba(239, 68, 68, 0.08);
      border: 1px solid rgba(239, 68, 68, 0.3);
      border-radius: 12px;
      font-size: 12.5px;
      color: #F87171;
      line-height: 1.4;
    }

    /* Bottom Button Bar */
    .wizard-footer-actions {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-top: 32px;
      padding-top: 24px;
      border-top: 1px solid rgba(255, 255, 255, 0.08);
    }
    .btn-prev {
      padding: 12px 24px;
      border-radius: 9999px;
      background: rgba(255, 255, 255, 0.05);
      border: 1px solid rgba(255, 255, 255, 0.1);
      color: #94A3B8;
      font-size: 14px;
      font-weight: 700;
      cursor: pointer;
      font-family: inherit;
      transition: background 0.2s var(--ease), color 0.2s var(--ease);
    }
    .btn-prev:hover { background: rgba(255, 255, 255, 0.1); color: #FFFFFF; }
    .btn-next {
      padding: 12px 28px;
      border-radius: 9999px;
      background: var(--mint);
      color: #FFFFFF !important;
      font-size: 14px;
      font-weight: 800;
      border: none;
      cursor: pointer;
      font-family: inherit;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      transition: background 0.2s var(--ease), transform 0.2s var(--ease), box-shadow 0.2s var(--ease);
      box-shadow: 0 4px 14px rgba(0, 217, 139, 0.3);
    }
    .btn-next:disabled { opacity: 0.6; cursor: not-allowed; transform: none !important; }
    .btn-next, .btn-next * { color: #FFFFFF !important; stroke: #FFFFFF !important; }
    .btn-next:hover:not(:disabled) {
      background: #00C27C;
      transform: translateY(-1px);
      box-shadow: 0 6px 20px rgba(0, 217, 139, 0.45);
    }
    .qb-spin-icon { animation: spin 0.9s linear infinite; }
    @keyframes spin { to { transform: rotate(360deg); } }

    /* Success Modal */
    .elevation-modal-overlay {
      position: fixed;
      inset: 0;
      background: rgba(10, 22, 40, 0.9);
      backdrop-filter: blur(12px);
      z-index: 999;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 20px;
    }
    .elevation-modal-card {
      background: var(--card-dark);
      border: 1.5px solid var(--mint);
      border-radius: 24px;
      width: 100%;
      max-width: 480px;
      padding: 36px 32px;
      text-align: center;
      box-shadow: 0 20px 50px rgba(0, 217, 139, 0.25);
    }
    .elevation-icon-wrap {
      width: 68px;
      height: 68px;
      border-radius: 50%;
      background: var(--mint-dim);
      border: 2px solid var(--mint);
      color: var(--mint);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 20px;
    }
    .elevation-pill-row {
      display: flex;
      justify-content: center;
      gap: 8px;
      margin: 18px 0;
      flex-wrap: wrap;
    }
    .elevation-pill {
      padding: 6px 12px;
      border-radius: 9999px;
      background: rgba(255, 255, 255, 0.05);
      border: 1px solid rgba(255, 255, 255, 0.1);
      font-size: 12px;
      font-weight: 700;
      color: #94A3B8;
    }
    .elevation-pill.highlight {
      background: rgba(255, 184, 0, 0.15);
      border-color: #FFB800;
      color: #FFB800;
    }
    .btn-launch-owner {
      width: 100%;
      padding: 14px 24px;
      border-radius: 9999px;
      background: var(--mint);
      color: #FFFFFF !important;
      font-size: 15px;
      font-weight: 800;
      border: none;
      cursor: pointer;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      margin-top: 16px;
      transition: background 0.2s var(--ease), transform 0.2s var(--ease);
      box-shadow: 0 4px 16px rgba(0, 217, 139, 0.3);
    }
    .btn-launch-owner, .btn-launch-owner * { color: #FFFFFF !important; stroke: #FFFFFF !important; }
    .btn-launch-owner:hover { background: #00C27C; transform: translateY(-1px); }

    @media (max-width: 640px) {
      .main-wizard-container { margin-top: 28px; }
      .verify-form { padding: 26px 22px; }
      .verify-progress { padding: 22px 22px; gap: 14px; }
      .verify-ring { width: 48px; height: 48px; }
      .verify-ring-label { font-size: 16px; }
      .verify-step-name { font-size: 16px; }
      .form-grid-2 { grid-template-columns: 1fr; }
      .wizard-hero h1 { font-size: 27px; }
    }
    @media (prefers-reduced-motion: reduce) {
      .verify-screen.active, .capture-frame.filled .capture-done, .qb-spin-icon {
        animation: none !important;
      }
      .verify-ring-fill { transition: none !important; }
    }
  </style>
</head>
<body>

  <!-- Top App Navigation -->
  <header class="app-header">
    <a href="app.php" class="brand-wrap">
      <img src="<?= \Picklers\Helpers\Url::asset('images/PICKLERS_OFFICIAL_LOGO.svg') ?>" alt="PICKLERS" class="brand-logo" onerror="this.src='<?= \Picklers\Helpers\Url::asset('images/PICKLERS_LOGO.png') ?>'">
      <span class="brand-title">PICKLERS</span>
    </a>
  </header>

  <div class="main-wizard-container">
    <div style="margin-bottom: 20px;">
      <a href="app.php" class="btn-ghost-back">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
        <span>Player App</span>
      </a>
    </div>

    <?php if ($notice === 'verification_required'): ?>
      <div class="notice-card">
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <span>To access the Court Owner Portal, please complete your facility details and verification documents below.</span>
      </div>
    <?php elseif ($notice === 'incomplete'): ?>
      <div class="notice-card">
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <span>Your last submission was missing required details or documents. Please review every step below and try again.</span>
      </div>
    <?php endif; ?>

    <!-- Hero Header -->
    <div class="wizard-hero">
      <h1>List Your Pickleball Facility</h1>
      <p>Connect your courts with over 15,000 active players in the Philippines. Automated reservations, cashless split payments, and tournament management.</p>
    </div>

    <!-- Verification Session Card -->
    <div class="verify-card">
      <div class="verify-progress">
        <div class="verify-ring" id="verifyRing" role="progressbar" aria-valuemin="1" aria-valuemax="3" aria-valuenow="1" aria-label="Application step">
          <svg viewBox="0 0 60 60">
            <circle class="verify-ring-track" cx="30" cy="30" r="27"></circle>
            <circle class="verify-ring-fill" id="ringForeground" cx="30" cy="30" r="27"></circle>
          </svg>
          <div class="verify-ring-label" id="ringStepLabel">1</div>
        </div>
        <div class="verify-progress-meta">
          <div class="verify-step-eyebrow" id="stepEyebrow">Step 1 of 3</div>
          <div class="verify-step-name" id="stepName">Facility Details</div>
        </div>
      </div>

      <!-- novalidate: the browser's own pre-submit constraint validation tries
           to focus the first invalid control before the submit event even
           fires — including the visually-hidden required file inputs below,
           which it cannot focus, so it fails silently (a real console error,
           zero visible feedback) and blocks handleFormSubmit()'s own explicit
           checks from ever running. Validation still happens: goToStep() calls
           checkValidity()/reportValidity() per-field when moving forward
           between screens, and handleFormSubmit() does the same for the final
           step plus a dedicated, visible check for the two documents. -->
      <form id="ownerApplicationForm" class="verify-form" method="POST" action="owner-application.php" enctype="multipart/form-data" onsubmit="handleFormSubmit(event)" novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? ''); ?>">

        <!-- ====================================================================
             STEP 1: Facility Details & GPS Location
             ==================================================================== -->
        <div class="verify-screen active" id="stepPanel1">
          <p class="verify-screen-intro">Tell us about your physical court premises and operating schedule. This becomes your public listing.</p>

          <div class="form-group">
            <label class="form-label" for="facility_name">Facility Brand Name *</label>
            <input type="text" id="facility_name" name="facility_name" class="input-field input-hero" placeholder="e.g. BGC Pickleball Arena" value="" required>
            <div class="form-hint">Public brand name displayed across player court searches.</div>
          </div>

          <div class="form-group">
            <label class="form-label" for="address">Street Address / City *</label>
            <input type="text" id="address" name="address" class="input-field" placeholder="e.g. 9th Ave & 28th St, Bonifacio High Street, BGC, Taguig" value="" required>
            <button type="button" class="btn-gps-detect" onclick="detectGPSLocation()">
              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"/></svg>
              <span id="gpsBtnText">Auto-Detect GPS Location</span>
            </button>
            <input type="hidden" id="latitude" name="latitude" value="14.5547">
            <input type="hidden" id="longitude" name="longitude" value="121.0244">
          </div>

          <div class="form-grid-2">
            <div class="form-group">
              <label class="form-label" for="courts_count">Number of Active Courts *</label>
              <select id="courts_count" name="courts_count" class="input-field">
                <option value="2">2 Courts</option>
                <option value="4" selected>4 Courts</option>
                <option value="6">6 Courts</option>
                <option value="8">8 Courts</option>
                <option value="12">12+ Courts</option>
              </select>
            </div>

            <div class="form-group">
              <label class="form-label" for="operating_hours">Standard Operating Hours *</label>
              <input type="text" id="operating_hours" name="operating_hours" class="input-field" value="06:00 AM – 11:00 PM" required>
            </div>
          </div>

          <div class="form-group">
            <label class="form-label">Primary Court Surface *</label>
            <input type="hidden" id="court_surface" name="court_surface" value="Indoor Hard">
            <div class="surface-selector-wrap">
              <div class="surface-pill-option active" onclick="selectSurface(this, 'Indoor Hard')">Indoor Hard</div>
              <div class="surface-pill-option" onclick="selectSurface(this, 'Outdoor Acrylic')">Outdoor Acrylic</div>
              <div class="surface-pill-option" onclick="selectSurface(this, 'Pro Synthetic')">Pro Synthetic</div>
              <div class="surface-pill-option" onclick="selectSurface(this, 'Covered Wood')">Covered Wood</div>
            </div>
          </div>

          <div class="wizard-footer-actions">
            <div></div>
            <button type="button" class="btn-next" onclick="goToStep(2)">
              <span>Next: Business Info</span>
              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
            </button>
          </div>
        </div>

        <!-- ====================================================================
             STEP 2: Contact & Entity Verification
             ==================================================================== -->
        <div class="verify-screen" id="stepPanel2">
          <p class="verify-screen-intro">Official registered details for payment disbursements and booking escalations.</p>

          <div class="form-grid-2">
            <div class="form-group">
              <label class="form-label" for="owner_name">Owner Full Name *</label>
              <input type="text" id="owner_name" name="owner_name" class="input-field" placeholder="e.g. Marcus Vance" value="<?php echo htmlspecialchars($currentUser['name'] ?? ''); ?>" required>
            </div>

            <div class="form-group">
              <label class="form-label" for="business_email">Official Business Email *</label>
              <input type="email" id="business_email" name="business_email" class="input-field" placeholder="e.g. operator@bgcpickle.ph" value="<?php echo htmlspecialchars($currentUser['email'] ?? ''); ?>" required>
            </div>
          </div>

          <div class="form-group">
            <label class="form-label" for="phone">Philippine Mobile Number (+63) *</label>
            <input type="tel" id="phone" name="phone" class="input-field" placeholder="+63 917 888 2026" value="<?php echo htmlspecialchars($currentUser['phone'] ?? ''); ?>" maxlength="16" onkeydown="return isPhoneKey(event)" oninput="formatPHPhone(this)" required>
            <div class="form-hint">Strict 10-digit format for GCash payouts and instant booking SMS alerts.</div>
          </div>

          <div class="form-grid-2">
            <div class="form-group">
              <label class="form-label" for="entity_name">Registered Legal Trade Name *</label>
              <input type="text" id="entity_name" name="entity_name" class="input-field" placeholder="e.g. BGC Pickleball Sports Inc." value="" required>
            </div>

            <div class="form-group">
              <label class="form-label" for="reg_number">DTI / SEC Registration Number *</label>
              <input type="text" id="reg_number" name="reg_number" class="input-field" placeholder="e.g. SEC-CS2026-98124" value="" required>
            </div>
          </div>

          <div class="wizard-footer-actions">
            <button type="button" class="btn-prev" onclick="goToStep(1)">
              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 19-7-7 7-7"/><path d="M19 12H5"/></svg>
              <span>Back</span>
            </button>
            <button type="button" class="btn-next" onclick="goToStep(3)">
              <span>Next: Document Uploads</span>
              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
            </button>
          </div>
        </div>

        <!-- ====================================================================
             STEP 3: Document Uploads & Proof of Ownership
             ==================================================================== -->
        <div class="verify-screen" id="stepPanel3">
          <p class="verify-screen-intro">Upload government-issued permits so our team can verify your facility — the same way you'd verify identity in GCash or Maya.</p>

          <!-- Mayor's / Business Permit Capture -->
          <div class="form-group">
            <label class="form-label">Mayor's Permit / Business License (PDF or Image, max 5MB) *</label>
            <div class="capture-frame" id="dropzonePermit" onclick="triggerFileInput('permitInput')">
              <div class="capture-bracket tl"></div>
              <div class="capture-bracket tr"></div>
              <div class="capture-bracket bl"></div>
              <div class="capture-bracket br"></div>
              <div class="capture-idle">
                <div class="capture-icon-wrap">
                  <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                </div>
                <div class="capture-text">Tap or drag in your Mayor's / Business Permit</div>
                <div class="capture-sub">PDF, PNG, or JPG · up to 5MB</div>
              </div>
              <div class="capture-done" id="badgePermit">
                <div class="capture-icon-wrap">
                  <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                </div>
                <div class="capture-done-name" id="badgePermitName"></div>
                <div class="capture-done-label">Ready for review</div>
              </div>
            </div>
            <input type="file" id="permitInput" name="permit_file" style="display:none;" accept=".pdf,.png,.jpg,.jpeg" onchange="handleFileSelected(this, 'dropzonePermit', 'badgePermitName')" required>
          </div>

          <!-- Government ID Capture -->
          <div class="form-group">
            <label class="form-label">Valid Government ID of Primary Registrant *</label>
            <div class="capture-frame" id="dropzoneGovId" onclick="triggerFileInput('govIdInput')">
              <div class="capture-bracket tl"></div>
              <div class="capture-bracket tr"></div>
              <div class="capture-bracket bl"></div>
              <div class="capture-bracket br"></div>
              <div class="capture-idle">
                <div class="capture-icon-wrap">
                  <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="14" x="3" y="5" rx="2"/><circle cx="9" cy="12" r="2"/><path d="M15 9h2"/><path d="M15 12h2"/><path d="M15 15h2"/></svg>
                </div>
                <div class="capture-text">Tap or drag in your Official Photo ID</div>
                <div class="capture-sub">Passport, Driver's License, UMID, or National ID</div>
              </div>
              <div class="capture-done" id="badgeGovId">
                <div class="capture-icon-wrap">
                  <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                </div>
                <div class="capture-done-name" id="badgeGovIdName"></div>
                <div class="capture-done-label">Ready for review</div>
              </div>
            </div>
            <input type="file" id="govIdInput" name="gov_id_file" style="display:none;" accept=".png,.jpg,.jpeg,.pdf" onchange="handleFileSelected(this, 'dropzoneGovId', 'badgeGovIdName')" required>
          </div>

          <div class="terms-row">
            <input type="checkbox" id="terms_check" checked>
            <label for="terms_check">
              I certify that I am the authorized operator of this sports facility and that the information and documents provided are accurate. I agree to PICKLERS Court Operator Terms &amp; Safety Standards.
            </label>
          </div>

          <div id="applicationFormError" class="form-error-banner" role="alert" aria-live="polite"></div>

          <div class="wizard-footer-actions">
            <button type="button" class="btn-prev" onclick="goToStep(2)">
              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 19-7-7 7-7"/><path d="M19 12H5"/></svg>
              <span>Back</span>
            </button>
            <button type="submit" class="btn-next">
              <span>Submit for Review</span>
              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
            </button>
          </div>
        </div>

      </form>
    </div>
  </div>

  <!-- Application Submitted Modal -->
  <div class="elevation-modal-overlay" id="elevationModal">
    <div class="elevation-modal-card">
      <div class="elevation-icon-wrap">
        <svg xmlns="http://www.w3.org/2000/svg" width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
      </div>
      <h2 style="font-size: 24px; font-weight: 900; color: #FFFFFF; margin-bottom: 8px;">Application Submitted!</h2>
      <p style="font-size: 14px; color: #94A3B8; line-height: 1.5;">
        Thanks! Your facility application and documents are now with the Picklers team for review. We'll notify you as soon as a decision is made.
      </p>

      <div class="elevation-pill-row">
        <div class="elevation-pill highlight">status: 'pending_review'</div>
      </div>

      <a href="app.php?tab=settings" class="btn-launch-owner">
        <span>Back to My Account</span>
        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
      </a>
    </div>
  </div>

  <script>
    let currentStep = 1;
    const STEP_NAMES = ['Facility Details', 'Business & Contact', 'Permits & ID'];
    const RING_CIRCUMFERENCE = 2 * Math.PI * 27; // r=27, matches the SVG circle above

    function updateVerifyProgress(step) {
      const pct = step / 3;
      const ringFg = document.getElementById('ringForeground');
      if (ringFg) ringFg.style.strokeDashoffset = String(RING_CIRCUMFERENCE * (1 - pct));

      const ringLabel = document.getElementById('ringStepLabel');
      if (ringLabel) ringLabel.textContent = String(step);

      const eyebrow = document.getElementById('stepEyebrow');
      if (eyebrow) eyebrow.textContent = 'Step ' + step + ' of 3';

      const name = document.getElementById('stepName');
      if (name) name.textContent = STEP_NAMES[step - 1];

      const ring = document.getElementById('verifyRing');
      if (ring) ring.setAttribute('aria-valuenow', String(step));
    }

    function goToStep(step) {
      if (step < 1 || step > 3) return;

      // Validate inputs if moving forward to next step
      if (step > currentStep) {
        const currentPanel = document.getElementById('stepPanel' + currentStep);
        if (currentPanel) {
          const requiredInputs = currentPanel.querySelectorAll('input[required], select[required]');
          let isValid = true;
          requiredInputs.forEach(input => {
            if (!input.checkValidity()) {
              isValid = false;
              input.reportValidity();
            }
          });
          if (!isValid) return;
        }
      }

      currentStep = step;

      document.querySelectorAll('.verify-screen').forEach((panel, idx) => {
        panel.classList.toggle('active', (idx + 1) === step);
      });

      updateVerifyProgress(step);
    }

    function selectSurface(element, surface) {
      document.querySelectorAll('.surface-pill-option').forEach(el => el.classList.remove('active'));
      element.classList.add('active');
      document.getElementById('court_surface').value = surface;
    }

    function isPhoneKey(e) {
      if (!e) return true;
      const allowedKeys = ['Backspace', 'Delete', 'Tab', 'Escape', 'Enter', 'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End'];
      if (allowedKeys.includes(e.key)) return true;
      if (e.ctrlKey || e.metaKey) return true;
      if (/^[0-9]$/.test(e.key)) return true;
      e.preventDefault();
      return false;
    }

    function formatPHPhone(input) {
      if (!input) return;
      let val = input.value.replace(/\D/g, '');
      if (val.startsWith('63')) {
        val = val.substring(2);
      } else if (val.startsWith('0')) {
        val = val.substring(1);
      }
      if (val.length > 10) val = val.substring(0, 10);

      if (val.length === 0) {
        input.value = '';
      } else if (val.length <= 3) {
        input.value = '+63 ' + val;
      } else if (val.length <= 6) {
        input.value = '+63 ' + val.substring(0, 3) + ' ' + val.substring(3);
      } else {
        input.value = '+63 ' + val.substring(0, 3) + ' ' + val.substring(3, 6) + ' ' + val.substring(6);
      }
    }

    function detectGPSLocation() {
      const btnText = document.getElementById('gpsBtnText');
      if (btnText) btnText.innerText = 'Detecting GPS Coordinates...';

      if ('geolocation' in navigator) {
        navigator.geolocation.getCurrentPosition(
          (pos) => {
            const lat = pos.coords.latitude;
            const lng = pos.coords.longitude;
            document.getElementById('latitude').value = lat.toFixed(4);
            document.getElementById('longitude').value = lng.toFixed(4);

            fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}`, { headers: { 'Accept-Language': 'en' } })
              .then(r => r.json())
              .then(data => {
                let formatted = '';
                if (data && data.address) {
                  const addr = data.address;
                  const neighborhood = addr.suburb || addr.neighbourhood || addr.quarter || addr.village || addr.district || '';
                  const city = addr.city || addr.town || addr.municipality || addr.county || '';
                  let parts = [];
                  if (neighborhood) parts.push(neighborhood);
                  if (city) parts.push(city);
                  if (parts.length > 0) formatted = parts.join(', ');
                }
                if (!formatted) {
                  formatted = (lat >= 9.0 && lat <= 10.5 && lng >= 122.5 && lng <= 124.0) ? 'Bunao, Dumaguete City' : `${lat.toFixed(4)}° N, ${lng.toFixed(4)}° E`;
                }
                document.getElementById('address').value = formatted;
                if (btnText) btnText.innerText = `✓ GPS Locked (${formatted})`;
              })
              .catch(() => {
                const fallback = (lat >= 9.0 && lat <= 10.5 && lng >= 122.5 && lng <= 124.0) ? 'Bunao, Dumaguete City' : `${lat.toFixed(4)}° N, ${lng.toFixed(4)}° E`;
                document.getElementById('address').value = fallback;
                if (btnText) btnText.innerText = `✓ GPS Locked (${fallback})`;
              });
          },
          (err) => {
            const fallback = 'Bunao, Dumaguete City';
            document.getElementById('latitude').value = '9.3289';
            document.getElementById('longitude').value = '123.2960';
            document.getElementById('address').value = fallback;
            if (btnText) btnText.innerText = `✓ GPS Location Set (${fallback})`;
          },
          { timeout: 8000, enableHighAccuracy: true }
        );
      } else {
        const fallback = 'Bunao, Dumaguete City';
        document.getElementById('address').value = fallback;
        if (btnText) btnText.innerText = `✓ GPS Location Set (${fallback})`;
      }
    }

    function triggerFileInput(id) {
      document.getElementById(id).click();
    }

    function handleFileSelected(input, frameId, textId) {
      if (input.files && input.files[0]) {
        const file = input.files[0];
        document.getElementById(textId).innerText = file.name;
        const frame = document.getElementById(frameId);
        if (frame) frame.classList.add('filled');
      }
    }

    // Handle drag & drop visuals
    ['dropzonePermit', 'dropzoneGovId'].forEach(id => {
      const zone = document.getElementById(id);
      zone.addEventListener('dragover', (e) => { e.preventDefault(); zone.classList.add('dragover'); });
      zone.addEventListener('dragleave', () => zone.classList.remove('dragover'));
      zone.addEventListener('drop', (e) => {
        e.preventDefault();
        zone.classList.remove('dragover');
        const inputId = id === 'dropzonePermit' ? 'permitInput' : 'govIdInput';
        const textId = id === 'dropzonePermit' ? 'badgePermitName' : 'badgeGovIdName';
        if (e.dataTransfer.files && e.dataTransfer.files[0]) {
          const dt = new DataTransfer();
          dt.items.add(e.dataTransfer.files[0]);
          document.getElementById(inputId).files = dt.files;
          document.getElementById(textId).innerText = e.dataTransfer.files[0].name;
          zone.classList.add('filled');
        }
      });
    });

    function showApplicationError(message) {
      const box = document.getElementById('applicationFormError');
      if (!box) return;
      box.textContent = message;
      box.style.display = 'block';
      box.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function handleFormSubmit(e) {
      e.preventDefault();
      const form = document.getElementById('ownerApplicationForm');
      const errorBox = document.getElementById('applicationFormError');
      if (errorBox) errorBox.style.display = 'none';

      // permitInput/govIdInput are visually hidden (the capture frame is
      // what's actually clickable), so the native reportValidity() tooltip
      // for `required` on them has nothing visible to anchor to and
      // silently shows nothing — checked explicitly here instead, with a
      // real message, before falling back to native validation for the rest.
      const permitInput = document.getElementById('permitInput');
      const govIdInput = document.getElementById('govIdInput');
      const missingDocs = [];
      if (!permitInput.files || !permitInput.files[0]) missingDocs.push("Mayor's Permit / Business License");
      if (!govIdInput.files || !govIdInput.files[0]) missingDocs.push('a valid Government ID');
      if (missingDocs.length) {
        showApplicationError('Please upload ' + missingDocs.join(' and ') + ' before submitting.');
        return;
      }

      if (!form.checkValidity()) {
        form.reportValidity();
        return;
      }

      const submitBtn = form.querySelector('button[type="submit"]');
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = `
          <span>Submitting Application...</span>
          <svg class="qb-spin-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
        `;
      }

      const restoreSubmitBtn = () => {
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.innerHTML = '<span>Submit for Review</span><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
        }
      };

      const formData = new FormData(form);
      formData.append('ajax', '1');

      // This used to show the "Verified & Elevated" success modal on ANY
      // response — success, a server-side error, even a network failure
      // that never reached the endpoint at all — so an application that was
      // actually rejected (e.g. "you already have one under review") still
      // told the applicant they'd been instantly approved. The server never
      // auto-elevates (see submitApplication() — status is always
      // 'pending_review' for a human admin to act on), so this now only
      // shows that modal, with accurate copy, on a real success response.
      fetch('owner-application.php', {
        method: 'POST',
        body: formData
      })
      .then(res => res.json().then(data => ({ ok: res.ok, data })))
      .then(({ ok, data }) => {
        if (ok && data && data.success) {
          document.getElementById('elevationModal').style.display = 'flex';
        } else {
          restoreSubmitBtn();
          showApplicationError((data && data.message) || 'Could not submit your application. Please try again.');
        }
      })
      .catch(() => {
        restoreSubmitBtn();
        showApplicationError('Network error — your application was not submitted. Please try again.');
      });
    }
  </script>
</body>
</html>
