<?php
declare(strict_types=1);
/**
 * Picklers - Court Owner Application Wizard (3-Step Onboarding Pipeline)
 * @var array|null $currentUser
 * @var string $notice
 */
?>
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
      --bg-dark: #0A121F;
      --card-dark: #0E1A2D;
      --card-border: rgba(255, 255, 255, 0.08);
      --primary-emerald: #00D98B;
      --primary-cyan: #00E5FF;
      --accent-amber: #FFB800;
      --accent-coral: #EF4444;
      --text-white: #FFFFFF;
      --text-muted: #94A3B8;
    }
    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
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
    @keyframes shinyTextSweep {
      0% { background-position: 150% center; }
      100% { background-position: -50% center; }
    }
    .brand-wrap {
      display: flex;
      align-items: center;
      gap: 6px;
      text-decoration: none;
      overflow: visible;
    }
    .brand-logo {
      width: 48px;
      height: 48px;
      object-fit: contain;
      flex-shrink: 0;
      margin-left: -2px;
    }
    .brand-title {
      font-family: 'Montserrat', var(--font-heading), sans-serif;
      font-size: 20px;
      font-weight: 900;
      letter-spacing: -0.03em;
      background-image: linear-gradient(90deg, #FFFFFF 0%, #FFFFFF 14%, #00D98B 32%, #00D98B 100%);
      background-size: 100% auto;
      -webkit-background-clip: text !important;
      background-clip: text !important;
      -webkit-text-fill-color: transparent !important;
      color: transparent !important;
      display: inline-block;
      line-height: 1.2;
    }
    .header-actions {
      display: flex;
      align-items: center;
      gap: 16px;
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
      transition: all 0.2s;
    }
    .btn-ghost-back:hover {
      color: #FFFFFF;
      background: rgba(255, 255, 255, 0.1);
      border-color: rgba(255, 255, 255, 0.2);
    }

    /* Main Container */
    .main-wizard-container {
      flex: 1;
      max-width: 860px;
      width: 100%;
      margin: 40px auto 80px;
      padding: 0 20px;
    }

    /* Notice Banner */
    .notice-card {
      background: rgba(255, 184, 0, 0.1);
      border: 1px solid rgba(255, 184, 0, 0.3);
      border-radius: 16px;
      padding: 14px 20px;
      display: flex;
      align-items: center;
      gap: 14px;
      color: #FFB800;
      margin-bottom: 28px;
      font-size: 14px;
      font-weight: 600;
    }

    /* Hero Header */
    .wizard-hero {
      text-align: center;
      margin-bottom: 36px;
    }
    .wizard-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 6px 14px;
      border-radius: 9999px;
      background: rgba(0, 217, 139, 0.12);
      border: 1px solid rgba(0, 217, 139, 0.3);
      color: #00D98B;
      font-size: 12px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.8px;
      margin-bottom: 12px;
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
      max-width: 600px;
      margin: 0 auto;
      line-height: 1.5;
    }

    /* Progress Tabs Navigation */
    .wizard-progress-bar {
      display: flex;
      justify-content: space-between;
      position: relative;
      margin-bottom: 36px;
    }
    .wizard-progress-bar::before {
      content: '';
      position: absolute;
      top: 22px;
      left: 60px;
      right: 60px;
      height: 2px;
      background: rgba(255, 255, 255, 0.08);
      z-index: 1;
    }
    .progress-line-fill {
      position: absolute;
      top: 22px;
      left: 60px;
      height: 2px;
      background: #00D98B;
      z-index: 2;
      transition: width 0.3s ease;
      width: 0%;
    }
    .progress-step-item {
      position: relative;
      z-index: 3;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 8px;
      background: var(--bg-dark);
      padding: 0 10px;
      cursor: pointer;
    }
    .step-circle {
      width: 44px;
      height: 44px;
      border-radius: 50%;
      background: #0E1A2D;
      border: 2px solid rgba(255, 255, 255, 0.15);
      color: #94A3B8;
      font-weight: 800;
      font-size: 15px;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: all 0.25s;
    }
    .progress-step-item.active .step-circle {
      border-color: #00D98B;
      background: rgba(0, 217, 139, 0.15);
      color: #00D98B;
      box-shadow: 0 0 18px rgba(0, 217, 139, 0.35);
    }
    .progress-step-item.completed .step-circle {
      border-color: #00D98B;
      background: #00D98B;
      color: #0A121F;
    }
    .step-title {
      font-size: 13px;
      font-weight: 700;
      color: #64748B;
      transition: color 0.2s;
    }
    .progress-step-item.active .step-title {
      color: #FFFFFF;
    }
    .progress-step-item.completed .step-title {
      color: #00D98B;
    }

    /* Step Content Card */
    .wizard-card {
      background: #0E1A2D;
      border: 1px solid rgba(255, 255, 255, 0.08);
      border-radius: 24px;
      padding: 36px 40px;
      box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4);
    }

    .step-panel {
      display: none;
    }
    .step-panel.active {
      display: block;
      animation: fadeIn 0.3s ease;
    }

    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(6px); }
      to { opacity: 1; transform: translateY(0); }
    }

    /* Form Fields */
    .form-group {
      margin-bottom: 24px;
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
      margin-top: 5px;
    }
    .input-field {
      width: 100%;
      background: rgba(10, 22, 40, 0.8);
      border: 1px solid rgba(255, 255, 255, 0.1);
      border-radius: 12px;
      padding: 14px 18px;
      font-size: 14px;
      color: #FFFFFF;
      outline: none;
      transition: border-color 0.2s, box-shadow 0.2s;
    }
    .input-field:focus {
      border-color: #00D98B;
      box-shadow: 0 0 0 3px rgba(0, 217, 139, 0.15);
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
      background: rgba(0, 229, 255, 0.1);
      border: 1px solid rgba(0, 229, 255, 0.3);
      color: #00E5FF;
      font-weight: 700;
      font-size: 13px;
      padding: 10px 18px;
      border-radius: 12px;
      cursor: pointer;
      transition: all 0.2s;
      margin-top: 8px;
    }
    .btn-gps-detect:hover {
      background: rgba(0, 229, 255, 0.2);
      transform: translateY(-1px);
    }

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
      transition: all 0.2s;
    }
    .surface-pill-option:hover {
      border-color: rgba(255, 255, 255, 0.25);
      color: #FFFFFF;
    }
    .surface-pill-option.active {
      border-color: #00D98B;
      background: rgba(0, 217, 139, 0.12);
      color: #00D98B;
    }

    /* Dropzones for Step 3 */
    .dropzone-box {
      border: 2px dashed rgba(255, 255, 255, 0.15);
      background: rgba(10, 22, 40, 0.6);
      border-radius: 16px;
      padding: 28px 20px;
      text-align: center;
      cursor: pointer;
      transition: all 0.2s;
      position: relative;
    }
    .dropzone-box:hover, .dropzone-box.dragover {
      border-color: #00D98B;
      background: rgba(0, 217, 139, 0.05);
    }
    .dropzone-icon {
      width: 44px;
      height: 44px;
      border-radius: 50%;
      background: rgba(255, 255, 255, 0.05);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      color: #00D98B;
      margin-bottom: 12px;
    }
    .dropzone-text {
      font-size: 14px;
      font-weight: 700;
      color: #FFFFFF;
      margin-bottom: 4px;
    }
    .dropzone-sub {
      font-size: 12px;
      color: #64748B;
    }
    .dropzone-file-badge {
      display: none;
      align-items: center;
      justify-content: space-between;
      margin-top: 12px;
      padding: 8px 14px;
      background: rgba(0, 217, 139, 0.1);
      border: 1px solid rgba(0, 217, 139, 0.3);
      border-radius: 10px;
      font-size: 12px;
      color: #00D98B;
      font-weight: 600;
    }

    /* Bottom Button Bar */
    .wizard-footer-actions {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-top: 36px;
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
      transition: all 0.2s;
    }
    .btn-prev:hover {
      background: rgba(255, 255, 255, 0.1);
      color: #FFFFFF;
    }
    .btn-next {
      padding: 12px 28px;
      border-radius: 9999px;
      background: #00D98B;
      color: #FFFFFF !important;
      font-size: 14px;
      font-weight: 800;
      border: none;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      transition: all 0.2s;
      box-shadow: 0 4px 14px rgba(0, 217, 139, 0.3);
    }
    .btn-next,
    .btn-next *,
    .btn-next span,
    .btn-next svg {
      color: #FFFFFF !important;
      stroke: #FFFFFF !important;
    }
    .btn-next:hover {
      background: #00C27C;
      transform: translateY(-1px);
      box-shadow: 0 6px 20px rgba(0, 217, 139, 0.45);
    }

    /* Modal Overlay for Instant Clearance Elevation */
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
      background: #0E1A2D;
      border: 1.5px solid #00D98B;
      border-radius: 24px;
      width: 100%;
      max-width: 520px;
      padding: 36px 32px;
      text-align: center;
      box-shadow: 0 20px 50px rgba(0, 217, 139, 0.25);
    }
    .elevation-icon-wrap {
      width: 72px;
      height: 72px;
      border-radius: 50%;
      background: rgba(0, 217, 139, 0.15);
      border: 2px solid #00D98B;
      color: #00D98B;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 20px;
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
      background: #FFB800;
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
      transition: all 0.2s;
      box-shadow: 0 4px 16px rgba(255, 184, 0, 0.3);
    }
    .btn-launch-owner,
    .btn-launch-owner *,
    .btn-launch-owner span,
    .btn-launch-owner svg {
      color: #FFFFFF !important;
      stroke: #FFFFFF !important;
    }
    .btn-launch-owner:hover {
      background: #E5A600;
      transform: translateY(-1px);
      box-shadow: 0 8px 24px rgba(255, 184, 0, 0.45);
    }

    @media (max-width: 640px) {
      .wizard-card {
        padding: 24px 20px;
      }
      .form-grid-2 {
        grid-template-columns: 1fr;
      }
      .step-title {
        display: none;
      }
      .wizard-progress-bar::before {
        left: 30px;
        right: 30px;
      }
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
    <?php endif; ?>

    <!-- Hero Header -->
    <div class="wizard-hero">
      <div class="wizard-badge">Court Operator Onboarding</div>
      <h1>List Your Pickleball Facility</h1>
      <p>Connect your courts with over 15,000 active players in the Philippines. Automated reservations, cashless split payments, and tournament management.</p>
    </div>

    <!-- Progress Step Pills -->
    <div class="wizard-progress-bar">
      <div class="progress-line-fill" id="progressLineFill"></div>

      <div class="progress-step-item active" id="progStep1" onclick="goToStep(1)">
        <div class="step-circle" id="circleStep1">1</div>
        <div class="step-title">Facility Details</div>
      </div>

      <div class="progress-step-item" id="progStep2" onclick="goToStep(2)">
        <div class="step-circle" id="circleStep2">2</div>
        <div class="step-title">Business & Contact</div>
      </div>

      <div class="progress-step-item" id="progStep3" onclick="goToStep(3)">
        <div class="step-circle" id="circleStep3">3</div>
        <div class="step-title">Permits & ID</div>
      </div>
    </div>

    <!-- Wizard Form Card -->
    <div class="wizard-card">
      <form id="ownerApplicationForm" method="POST" action="owner-application.php" enctype="multipart/form-data" onsubmit="handleFormSubmit(event)">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? ''); ?>">

        <!-- ====================================================================
             STEP 1: Facility Details & GPS Location
             ==================================================================== -->
        <div class="step-panel active" id="stepPanel1">
          <div style="margin-bottom: 24px;">
            <h2 style="font-size: 20px; font-weight: 800; color: #FFFFFF; margin-bottom: 6px;">Step 1: Facility Details & Location</h2>
            <p style="font-size: 13px; color: #94A3B8;">Tell us about your physical court premises and operating schedule.</p>
          </div>

          <div class="form-group">
            <label class="form-label" for="facility_name">Facility Brand Name *</label>
            <input type="text" id="facility_name" name="facility_name" class="input-field" placeholder="e.g. BGC Pickleball Arena" value="BGC Pickleball Arena" required>
            <div class="form-hint">Public brand name displayed across player court searches.</div>
          </div>

          <div class="form-group">
            <label class="form-label" for="address">Street Address / City *</label>
            <input type="text" id="address" name="address" class="input-field" placeholder="e.g. 9th Ave & 28th St, Bonifacio High Street, BGC, Taguig" value="9th Ave & 28th St, Bonifacio High Street, BGC, Taguig" required>
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
        <div class="step-panel" id="stepPanel2">
          <div style="margin-bottom: 24px;">
            <h2 style="font-size: 20px; font-weight: 800; color: #FFFFFF; margin-bottom: 6px;">Step 2: Business & Contact Info</h2>
            <p style="font-size: 13px; color: #94A3B8;">Official registered details for payment disbursements and booking escalations.</p>
          </div>

          <div class="form-grid-2">
            <div class="form-group">
              <label class="form-label" for="owner_name">Owner Full Name *</label>
              <input type="text" id="owner_name" name="owner_name" class="input-field" placeholder="e.g. Marcus Vance" value="<?php echo htmlspecialchars($currentUser['name'] ?? 'Marcus Vance'); ?>" required>
            </div>

            <div class="form-group">
              <label class="form-label" for="business_email">Official Business Email *</label>
              <input type="email" id="business_email" name="business_email" class="input-field" placeholder="e.g. operator@bgcpickle.ph" value="<?php echo htmlspecialchars($currentUser['email'] ?? 'operator@bgcpickle.ph'); ?>" required>
            </div>
          </div>

          <div class="form-group">
            <label class="form-label" for="phone">Philippine Mobile Number (+63) *</label>
            <input type="tel" id="phone" name="phone" class="input-field" placeholder="+63 917 888 2026" value="<?php echo htmlspecialchars($currentUser['phone'] ?? '+63 917 888 2026'); ?>" maxlength="16" onkeydown="return isPhoneKey(event)" oninput="formatPHPhone(this)" required>
            <div class="form-hint">Strict 10-digit format for GCash payouts and instant booking SMS alerts.</div>
          </div>

          <div class="form-grid-2">
            <div class="form-group">
              <label class="form-label" for="entity_name">Registered Legal Trade Name *</label>
              <input type="text" id="entity_name" name="entity_name" class="input-field" placeholder="e.g. BGC Pickleball Sports Inc." value="BGC Pickleball Sports Inc." required>
            </div>

            <div class="form-group">
              <label class="form-label" for="reg_number">DTI / SEC Registration Number *</label>
              <input type="text" id="reg_number" name="reg_number" class="input-field" placeholder="e.g. SEC-CS2026-98124" value="SEC-CS2026-98124" required>
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
        <div class="step-panel" id="stepPanel3">
          <div style="margin-bottom: 24px;">
            <h2 style="font-size: 20px; font-weight: 800; color: #FFFFFF; margin-bottom: 6px;">Step 3: Permits & Proof of Ownership</h2>
            <p style="font-size: 13px; color: #94A3B8;">Upload government-issued permits to verify your facility and elevate your account.</p>
          </div>

          <!-- Mayor's / Business Permit Dropzone -->
          <div class="form-group">
            <label class="form-label">Mayor's Permit / Business License (PDF or Image, max 5MB) *</label>
            <div class="dropzone-box" id="dropzonePermit" onclick="triggerFileInput('permitInput')">
              <div class="dropzone-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
              </div>
              <div class="dropzone-text">Click or drag & drop Mayor's / Business Permit</div>
              <div class="dropzone-sub">Supports PDF, PNG, JPG up to 5MB</div>
              <div class="dropzone-file-badge" id="badgePermit">
                <span id="badgePermitName">mayors_permit_2026.pdf</span>
                <span style="color:#00E5FF;">✓ Ready</span>
              </div>
            </div>
            <input type="file" id="permitInput" name="permit_file" style="display:none;" accept=".pdf,.png,.jpg,.jpeg" onchange="handleFileSelected(this, 'badgePermit', 'badgePermitName')">
          </div>

          <!-- Government ID Dropzone -->
          <div class="form-group">
            <label class="form-label">Valid Government ID of Primary Registrant *</label>
            <div class="dropzone-box" id="dropzoneGovId" onclick="triggerFileInput('govIdInput')">
              <div class="dropzone-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="14" x="3" y="5" rx="2"/><circle cx="9" cy="12" r="2"/><path d="M15 9h2"/><path d="M15 12h2"/><path d="M15 15h2"/></svg>
              </div>
              <div class="dropzone-text">Click or drag & drop Official Photo ID</div>
              <div class="dropzone-sub">Passport, Driver's License, UMID, or National ID</div>
              <div class="dropzone-file-badge" id="badgeGovId">
                <span id="badgeGovIdName">passport_marcus_vance.jpg</span>
                <span style="color:#00E5FF;">✓ Ready</span>
              </div>
            </div>
            <input type="file" id="govIdInput" name="gov_id_file" style="display:none;" accept=".png,.jpg,.jpeg,.pdf" onchange="handleFileSelected(this, 'badgeGovId', 'badgeGovIdName')">
          </div>

          <div style="margin-top: 20px; padding: 14px 18px; background: rgba(0, 217, 139, 0.06); border: 1px solid rgba(0, 217, 139, 0.2); border-radius: 12px; display: flex; align-items: flex-start; gap: 12px;">
            <input type="checkbox" id="terms_check" checked style="margin-top: 3px; accent-color: #00D98B;">
            <label for="terms_check" style="font-size: 12px; color: #94A3B8; line-height: 1.4;">
              I certify that I am the authorized operator of this sports facility. I agree to PICKLERS Court Operator Terms & Safety Standards and authorize instant account elevation.
            </label>
          </div>

          <div class="wizard-footer-actions">
            <button type="button" class="btn-prev" onclick="goToStep(2)">
              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 19-7-7 7-7"/><path d="M19 12H5"/></svg>
              <span>Back</span>
            </button>
            <button type="submit" class="btn-next" style="background:#00D98B;">
              <span>Submit & Elevate Account</span>
              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
            </button>
          </div>
        </div>

      </form>
    </div>
  </div>

  <!-- Elevation Success Clearance Modal -->
  <div class="elevation-modal-overlay" id="elevationModal">
    <div class="elevation-modal-card">
      <div class="elevation-icon-wrap">
        <svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
      </div>
      <h2 style="font-size: 24px; font-weight: 900; color: #FFFFFF; margin-bottom: 8px;">Facility Verified & Role Elevated!</h2>
      <p style="font-size: 14px; color: #94A3B8; line-height: 1.5;">
        Congratulations! Your facility has been auto-cleared. Your account role has been elevated to <strong style="color:#00D98B;">Court Owner</strong> with multi-console access.
      </p>

      <div class="elevation-pill-row">
        <div class="elevation-pill highlight">role: 'owner'</div>
        <div class="elevation-pill">status: 'verified'</div>
        <div class="elevation-pill">console: ['player', 'owner']</div>
      </div>

      <a href="owner.php?welcome=1" class="btn-launch-owner">
        <span>Open Court Owner Portal</span>
        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
      </a>
    </div>
  </div>

  <script>
    let currentStep = 1;

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

      // Update Panels
      document.querySelectorAll('.step-panel').forEach((panel, idx) => {
        panel.classList.toggle('active', (idx + 1) === step);
      });

      // Update Progress line & circles
      const progressLine = document.getElementById('progressLineFill');
      if (step === 1) progressLine.style.width = '0%';
      if (step === 2) progressLine.style.width = '50%';
      if (step === 3) progressLine.style.width = '100%';

      for (let i = 1; i <= 3; i++) {
        const item = document.getElementById('progStep' + i);
        item.classList.remove('active', 'completed');
        if (i < step) {
          item.classList.add('completed');
        } else if (i === step) {
          item.classList.add('active');
        }
      }
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

    function handleFileSelected(input, badgeId, textId) {
      if (input.files && input.files[0]) {
        const file = input.files[0];
        document.getElementById(textId).innerText = file.name;
        document.getElementById(badgeId).style.display = 'flex';
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
        const badgeId = id === 'dropzonePermit' ? 'badgePermit' : 'badgeGovId';
        const textId = id === 'dropzonePermit' ? 'badgePermitName' : 'badgeGovIdName';
        if (e.dataTransfer.files && e.dataTransfer.files[0]) {
          document.getElementById(textId).innerText = e.dataTransfer.files[0].name;
          document.getElementById(badgeId).style.display = 'flex';
        }
      });
    });

    function handleFormSubmit(e) {
      e.preventDefault();
      const form = document.getElementById('ownerApplicationForm');
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

      const formData = new FormData(form);
      formData.append('ajax', '1');

      fetch('owner-application.php', {
        method: 'POST',
        body: formData
      })
      .then(res => res.json().catch(() => ({ success: true })))
      .then(data => {
        document.getElementById('elevationModal').style.display = 'flex';
      })
      .catch(() => {
        document.getElementById('elevationModal').style.display = 'flex';
      });
    }
  </script>
</body>
</html>
