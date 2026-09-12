<?php
declare(strict_types=1);
/**
 * Picklers - Auth View Template
 * @var string $error
 * @var string $success
 * @var string $initialTab
 */
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sign In & Register | PICKLERS Philippines</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
  <style>
    /* Success & Loading Animations for Auth Buttons */
    @keyframes authSuccessFade {
      0% { opacity: 0.7; transform: scale(0.98); }
      100% { opacity: 1; transform: scale(1); }
    }
    @keyframes authSpin {
      to { transform: rotate(360deg); }
    }
    .auth-btn-spinner {
      width: 18px;
      height: 18px;
      border: 2.5px solid rgba(255, 255, 255, 0.35);
      border-top-color: #FFFFFF;
      border-radius: 50%;
      animation: authSpin 0.6s linear infinite;
      display: inline-block;
      vertical-align: middle;
    }
    .btn-auth-primary.btn-success-state {
      background: #00D98B !important;
      color: #FFFFFF !important;
      font-weight: 800 !important;
      box-shadow: 0 4px 20px rgba(0, 217, 139, 0.45) !important;
      border: none !important;
      animation: authSuccessFade 0.25s cubic-bezier(0.16, 1, 0.3, 1) forwards !important;
    }

    /* Auth Portal Exact Live Styling */
    * {
      box-sizing: border-box;
    }
    body.auth-page-body {
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      background: var(--surface-base, #0A121F);
      margin: 0;
      padding: 32px 16px;
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      position: relative;
      overflow-x: hidden;
    }

    /* Top Left Back to Home */
    .auth-page-back {
      position: absolute;
      top: 24px;
      left: 28px;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      font-size: 13.5px;
      font-weight: 500;
      color: rgba(255, 255, 255, 0.7);
      text-decoration: none;
      transition: color 0.2s, transform 0.2s;
      z-index: 20;
    }
    .auth-page-back:hover {
      color: #00D98B;
      transform: translateX(-2px);
    }
    @media (max-width: 640px) {
      .auth-page-back {
        top: 16px;
        left: 16px;
      }
    }

    /* Auth Card Container */
    .auth-card {
      width: 100%;
      max-width: 440px;
      background: var(--surface-raised, #111F3A);
      border: 1px solid rgba(255, 255, 255, 0.12);
      border-radius: 20px;
      box-shadow: 0 20px 50px rgba(0, 0, 0, 0.4), 0 0 30px rgba(0, 217, 139, 0.05);
      padding: 38px 34px 34px;
      position: relative;
      z-index: 10;
      margin: auto;
      animation: authCardFade 0.35s cubic-bezier(0.16, 1, 0.3, 1) forwards;
    }

    @keyframes authCardFade {
      from { opacity: 0; transform: translateY(14px) scale(0.99); }
      to { opacity: 1; transform: translateY(0) scale(1); }
    }

    /* Brand Icon and Header */
    .auth-header-wrap {
      text-align: center;
      margin-bottom: 24px;
    }
    .auth-brand-logo-icon {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 8px;
      margin-top: -6px;
    }
    .auth-brand-logo-icon img {
      width: 62px;
      height: 68px;
      object-fit: contain;
      filter: none;
    }
    .auth-card-title {
      font-family: 'Montserrat', var(--font-heading), sans-serif;
      font-size: clamp(17px, 5vw, 24px);
      font-weight: 800;
      letter-spacing: -0.03em;
      color: #FFFFFF;
      margin: 0 0 6px 0;
      white-space: nowrap;
    }
    .auth-card-subtitle {
      font-size: 13px;
      color: rgba(255, 255, 255, 0.55);
      margin: 0;
      font-weight: 400;
    }

    @media (max-width: 480px) {
      .auth-card {
        padding: 28px 20px 24px;
      }
      .auth-card-title {
        font-size: 18.5px;
        white-space: nowrap;
      }
      .otp-inputs-row {
        gap: 6px;
      }
      .otp-box {
        height: 48px;
        font-size: 19px;
        border-radius: 10px;
      }
    }

    /* Underline Tab Switcher */
    .auth-tabs-row {
      display: flex;
      border-bottom: 1px solid rgba(255, 255, 255, 0.08);
      margin-bottom: 22px;
      position: relative;
    }
    .auth-tab-btn {
      flex: 1;
      background: transparent;
      border: none;
      padding: 10px 0 12px;
      font-size: 14px;
      font-weight: 700;
      color: rgba(255, 255, 255, 0.5);
      cursor: pointer;
      position: relative;
      transition: all 0.2s;
      text-align: center;
    }
    .auth-tab-btn:hover {
      color: #FFFFFF;
    }
    .auth-tab-btn.active {
      color: #00D98B;
    }
    .auth-tab-btn.active::after {
      content: '';
      position: absolute;
      bottom: -1px;
      left: 0;
      right: 0;
      height: 2px;
      background: #00D98B;
      border-radius: 2px;
      box-shadow: 0 0 10px rgba(0, 217, 139, 0.6);
    }

    /* Form Rows & Inputs */
    .form-label-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 8px;
    }
    .form-label-txt {
      font-size: 11px;
      font-weight: 800;
      color: rgba(255, 255, 255, 0.6);
      text-transform: uppercase;
      letter-spacing: 0.6px;
    }
    .form-link-action {
      font-size: 11.5px;
      font-weight: 600;
      color: #00D98B;
      text-decoration: none;
      transition: opacity 0.2s;
    }
    .form-link-action:hover {
      opacity: 0.85;
      text-decoration: underline;
    }

    .auth-input-container {
      position: relative;
      display: flex;
      align-items: center;
      background: #182844;
      border: 1px solid rgba(255, 255, 255, 0.2);
      border-radius: 12px;
      transition: all 0.2s;
      overflow: hidden;
    }
    .auth-input-container:focus-within {
      border-color: #00D98B;
      background: #1D3052;
      box-shadow: 0 0 0 3px rgba(0, 217, 139, 0.2);
    }
    .auth-input-icon {
      position: absolute;
      left: 14px;
      color: rgba(255, 255, 255, 0.65);
      pointer-events: none;
    }
    .auth-input-icon.icon-teal {
      color: #00D98B;
    }
    .auth-text-input {
      width: 100%;
      background: transparent;
      border: none;
      outline: none;
      color: #FFFFFF;
      font-size: 14px;
      padding: 13px 40px 13px 44px;
      font-family: inherit;
      border-radius: inherit;
    }
    .auth-text-input:-webkit-autofill,
    .auth-text-input:-webkit-autofill:hover,
    .auth-text-input:-webkit-autofill:focus,
    .auth-text-input:-webkit-autofill:active {
      -webkit-box-shadow: 0 0 0px 1000px #182844 inset !important;
      -webkit-text-fill-color: #FFFFFF !important;
      caret-color: #FFFFFF !important;
      border-radius: inherit !important;
      transition: background-color 5000s ease-in-out 0s;
    }
    .auth-text-input::-ms-reveal,
    .auth-text-input::-ms-clear,
    input[type="password"]::-ms-reveal,
    input[type="password"]::-ms-clear {
      display: none !important;
      width: 0 !important;
      height: 0 !important;
    }
    .auth-text-input::placeholder {
      color: rgba(255, 255, 255, 0.5);
    }
    .auth-eye-btn {
      position: absolute;
      right: 12px;
      background: transparent;
      border: none;
      color: rgba(255, 255, 255, 0.65);
      cursor: pointer;
      padding: 4px;
      display: flex;
      align-items: center;
      transition: color 0.2s;
    }
    .auth-eye-btn:hover {
      color: #FFFFFF;
    }

    /* Primary Submit Button */
    .btn-auth-primary {
      width: 100%;
      background: #00D98B;
      color: #FFFFFF;
      border: none;
      border-radius: 12px;
      padding: 13px 20px;
      font-size: 15px;
      font-weight: 800;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      cursor: pointer;
      margin-top: 22px;
      box-shadow: 0 4px 16px rgba(0, 217, 139, 0.3);
      transition: all 0.2s;
    }
    .btn-auth-primary:hover {
      background: #00c47e;
      box-shadow: 0 6px 20px rgba(0, 217, 139, 0.4);
      transform: translateY(-1px);
    }

    /* Social Divider */
    .auth-social-divider {
      text-align: center;
      font-size: 10.5px;
      font-weight: 800;
      color: rgba(255, 255, 255, 0.45);
      letter-spacing: 0.8px;
      text-transform: uppercase;
      margin: 24px 0 16px;
    }

    /* Social Logins Row */
    .auth-social-row {
      display: flex;
      gap: 12px;
    }
    .btn-social-auth {
      width: 100%;
      background: #182844;
      border: 1px solid rgba(255, 255, 255, 0.2);
      border-radius: 12px;
      padding: 11px 14px;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      color: #FFFFFF;
      font-size: 13.5px;
      font-weight: 700;
      cursor: pointer;
      transition: all 0.2s;
    }
    .btn-social-auth:hover {
      background: #1D3052;
      border-color: rgba(255, 255, 255, 0.35);
      transform: translateY(-1px);
    }

    /* Password Strength Meter */
    .strength-meter {
      display: flex;
      gap: 6px;
      margin-top: 8px;
    }
    .strength-bar {
      flex: 1;
      height: 4px;
      border-radius: 4px;
      background: rgba(255, 255, 255, 0.1);
      transition: background 0.3s;
    }
    .strength-label {
      font-size: 11px;
      font-weight: 700;
      color: rgba(255, 255, 255, 0.5);
      margin-top: 4px;
      display: flex;
      justify-content: space-between;
    }

    /* Alerts & Floating Toast Notifications (Bottom Right Corner - Exact Red Line) */
    .auth-alert {
      padding: 12px 14px;
      border-radius: 12px;
      font-size: 13px;
      margin-bottom: 18px;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .auth-alert-error {
      background: rgba(239, 68, 68, 0.12);
      border: 1px solid rgba(239, 68, 68, 0.3);
      color: #F87171;
    }
    .auth-alert-success {
      background: rgba(16, 185, 129, 0.12);
      border: 1px solid rgba(16, 185, 129, 0.3);
      color: #34D399;
    }

    .auth-toast-container {
      position: fixed;
      bottom: 28px;
      right: 28px;
      z-index: 9999;
      display: flex;
      flex-direction: column;
      gap: 10px;
      pointer-events: none;
    }
    .auth-toast-card {
      pointer-events: auto;
      padding: 12px 20px;
      border-radius: 9999px;
      background: #0E1A2D;
      border: 1px solid rgba(0, 217, 139, 0.4);
      color: #FFFFFF;
      font-size: 13.5px;
      font-weight: 600;
      display: flex;
      align-items: center;
      gap: 10px;
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5), 0 0 15px rgba(0, 217, 139, 0.2);
      animation: authToastSlideIn 0.35s cubic-bezier(0.16, 1, 0.3, 1) forwards;
    }
    .auth-toast-success {
      border-color: rgba(0, 217, 139, 0.4);
      background: #0E1A2D;
      color: #00D98B;
    }
    .auth-toast-error {
      border-color: rgba(239, 68, 68, 0.4);
      background: #1F1218;
      color: #F87171;
    }

    @keyframes authToastSlideIn {
      from {
        opacity: 0;
        transform: translateY(20px) scale(0.95);
      }
      to {
        opacity: 1;
        transform: translateY(0) scale(1);
      }
    }

    /* OTP Code Boxes */
    .otp-inputs-row {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      margin: 22px 0;
      width: 100%;
      box-sizing: border-box;
    }
    .otp-box {
      flex: 1 1 0%;
      min-width: 0;
      max-width: 52px;
      height: 56px;
      background: rgba(255, 255, 255, 0.05);
      border: 1.5px solid rgba(255, 255, 255, 0.15);
      border-radius: 12px;
      text-align: center;
      font-size: 22px;
      font-weight: 800;
      color: #00D98B;
      outline: none;
      transition: all 0.2s;
      padding: 0;
      box-sizing: border-box;
    }
    .otp-box:focus {
      border-color: #00D98B;
      background: rgba(16, 185, 129, 0.08);
      box-shadow: 0 0 0 3px rgba(0, 217, 139, 0.25);
    }

    /* Fullscreen Branded Picklers Logo Loading Overlay */
    .auth-success-overlay {
      position: fixed;
      inset: 0;
      background: rgba(10, 18, 31, 0.94);
      backdrop-filter: blur(20px);
      -webkit-backdrop-filter: blur(20px);
      z-index: 99999;
      display: flex;
      align-items: center;
      justify-content: center;
      opacity: 0;
      transition: opacity 0.4s cubic-bezier(0.16, 1, 0.3, 1);
      pointer-events: none;
    }
    .auth-success-overlay.active {
      opacity: 1;
      pointer-events: auto;
    }
    .auth-loading-glow {
      position: absolute;
      width: 280px;
      height: 280px;
      background: radial-gradient(circle, rgba(0, 217, 139, 0.35) 0%, rgba(0, 217, 139, 0) 70%);
      border-radius: 50%;
      animation: authGlowPulse 2s ease-in-out infinite alternate;
      pointer-events: none;
    }
    @keyframes authGlowPulse {
      0% { transform: scale(0.85); opacity: 0.5; }
      100% { transform: scale(1.3); opacity: 0.95; }
    }
    .auth-loading-content {
      position: relative;
      z-index: 2;
      text-align: center;
      display: flex;
      flex-direction: column;
      align-items: center;
    }
    .auth-logo-pulse-wrap {
      position: relative;
      width: 110px;
      height: 110px;
      display: flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 22px;
    }
    .auth-loading-logo {
      width: 76px;
      height: 84px;
      object-fit: contain;
      animation: authLogoFloat 1.6s ease-in-out infinite alternate;
      filter: drop-shadow(0 0 20px rgba(0, 217, 139, 0.65));
    }
    @keyframes authLogoFloat {
      0% { transform: translateY(0) scale(1); }
      100% { transform: translateY(-8px) scale(1.08); }
    }
    .auth-logo-ring {
      position: absolute;
      inset: -6px;
      border: 3px solid rgba(0, 217, 139, 0.18);
      border-top-color: #00D98B;
      border-right-color: #00D98B;
      border-radius: 50%;
      animation: authSpin 0.9s linear infinite;
    }
    .auth-loading-title {
      font-family: 'Montserrat', var(--font-heading), sans-serif;
      font-size: 22px;
      font-weight: 800;
      color: #FFFFFF;
      letter-spacing: -0.02em;
      margin: 0 0 6px 0;
      text-shadow: 0 0 20px rgba(0, 217, 139, 0.4);
    }
    .auth-loading-sub {
      font-size: 13.5px;
      color: rgba(255, 255, 255, 0.7);
      margin: 0 0 16px 0;
      font-weight: 500;
    }
    .auth-loading-dots {
      display: flex;
      gap: 7px;
      align-items: center;
    }
    .auth-loading-dots span {
      width: 8px;
      height: 8px;
      background-color: #00D98B;
      border-radius: 50%;
      display: inline-block;
      animation: authDotBounce 1.4s ease-in-out infinite both;
    }
    .auth-loading-dots span:nth-child(1) { animation-delay: 0s; }
    .auth-loading-dots span:nth-child(2) { animation-delay: 0.2s; }
    .auth-loading-dots span:nth-child(3) { animation-delay: 0.4s; }
    @keyframes authDotBounce {
      0%, 80%, 100% { transform: scale(0.3); opacity: 0.3; }
      40% { transform: scale(1.1); opacity: 1; box-shadow: 0 0 10px #00D98B; }
    }
  </style>
</head>
<body class="auth-page-body">

  <!-- Fullscreen Branded Picklers Logo Loading Overlay -->
  <div id="authSuccessOverlay" class="auth-success-overlay" style="display:none;" aria-hidden="true">
    <div class="auth-loading-glow"></div>
    <div class="auth-loading-content">
      <div class="auth-logo-pulse-wrap">
        <img src="assets/images/PICKLERS_OFFICIAL_LOGO.svg" alt="PICKLERS Logo" class="auth-loading-logo" onerror="this.src='assets/images/PICKLERS_LOGO.png'">
        <div class="auth-logo-ring"></div>
      </div>
      <h2 class="auth-loading-title">Welcome to PICKLERS</h2>
      <p class="auth-loading-sub" id="authLoadingSub">Signing you in, please wait...</p>
      <div class="auth-loading-dots">
        <span></span><span></span><span></span>
      </div>
    </div>
  </div>

  <!-- Back to Home Button at Top Left -->
  <a href="index.php" class="auth-page-back">
    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
    <span>Back to Home</span>
  </a>

  <div class="auth-card">
    
    <!-- Card Brand Header -->
    <div class="auth-header-wrap">
      <div class="auth-brand-logo-icon">
        <img src="assets/images/PICKLERS_OFFICIAL_LOGO.svg" alt="Picklers Logo" onerror="this.src='assets/images/PICKLERS_LOGO.png'">
      </div>
      <h1 class="auth-card-title" id="authHeading"><?php echo $initialTab === 'signup' ? 'Start Playing Pickleball' : 'Welcome Back!'; ?></h1>
      <p class="auth-card-subtitle" id="authSubheading"><?php echo $initialTab === 'signup' ? 'Register to join match sessions near you' : 'Sign in to reserve courts and matches'; ?></p>
    </div>

    <!-- View: Main Auth Form (Sign In / Sign Up) -->
    <div id="authViewMain">
      <!-- Underline Tabs -->
      <div class="auth-tabs-row">
        <button type="button" class="auth-tab-btn <?php echo $initialTab === 'signin' ? 'active' : ''; ?>" id="tabSignInBtn" onclick="switchAuthTab('signin')">Sign In</button>
        <button type="button" class="auth-tab-btn <?php echo $initialTab === 'signup' ? 'active' : ''; ?>" id="tabSignUpBtn" onclick="switchAuthTab('signup')">Create Account</button>
      </div>

      <!-- Sign In Form -->
      <form id="signInForm" method="POST" action="<?= \Picklers\Helpers\Url::to('auth') ?>" novalidate style="<?php echo $initialTab === 'signin' ? 'display:block;' : 'display:none;'; ?>">
        <input type="hidden" name="auth_action" value="signin">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? ''); ?>">

        <!-- Email / Phone Switcher -->
        <div class="form-label-row">
          <label class="form-label-txt" id="signinMethodLabel">EMAIL ADDRESS</label>
          <a href="javascript:void(0)" onclick="toggleSignInMethod()" id="toggleMethodLink" class="form-link-action" tabindex="-1">Use Phone Number instead</a>
        </div>

        <div class="auth-input-container" id="emailInputGroup">
          <svg class="auth-input-icon icon-teal" id="signInIconSvg" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L1 7"/></svg>
          <input type="text" name="identifier" id="signInIdentifier" class="auth-text-input" placeholder="email@example.com" value="" tabindex="1" required autocomplete="username">
        </div>

        <!-- Password Field -->
        <div class="form-label-row" style="margin-top: 18px;">
          <label class="form-label-txt">PASSWORD</label>
          <a href="mailto:support@picklers.ph?subject=Password%20reset%20request" class="form-link-action" tabindex="-1">Forgot Password?</a>
        </div>

        <div class="auth-input-container">
          <svg class="auth-input-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          <input type="password" name="password" id="signInPassword" class="auth-text-input" placeholder="••••••••••" value="" tabindex="2" required autocomplete="current-password">
          <button type="button" class="auth-eye-btn" onclick="togglePassVisibility('signInPassword', this)" aria-label="Toggle Password Visibility" tabindex="-1">
            <svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
          </button>
        </div>

        <!-- Submit Button -->
        <button type="submit" class="btn-auth-primary" id="btnSignInSubmit" tabindex="3" style="margin-top: 22px;">
          <span>Sign In</span>
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
        </button>
      </form>

      <!-- Sign Up Form -->
      <form id="signUpForm" method="POST" action="<?= \Picklers\Helpers\Url::to('auth') ?>" novalidate style="<?php echo $initialTab === 'signup' ? 'display:block;' : 'display:none;'; ?>">
        <input type="hidden" name="auth_action" value="signup">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? ''); ?>">
        <input type="hidden" name="role" id="signupRole" value="player">

        <div class="auth-input-container">
          <svg class="auth-input-icon icon-teal" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          <input type="text" name="name" class="auth-text-input" placeholder="Full Name" required autocomplete="name">
        </div>

        <div class="auth-input-container" style="margin-top: 14px;">
          <svg class="auth-input-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L1 7"/></svg>
          <input type="email" name="email" class="auth-text-input" placeholder="Email address" required autocomplete="email">
        </div>

        <div class="auth-input-container" style="margin-top: 14px;">
          <svg class="auth-input-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          <input type="password" name="password" id="signUpPassword" class="auth-text-input" placeholder="Password" required autocomplete="new-password">
          <button type="button" class="auth-eye-btn" onclick="togglePassVisibility('signUpPassword', this)" aria-label="Toggle Password Visibility">
            <svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
          </button>
        </div>

        <div class="auth-input-container" style="margin-top: 14px;">
          <svg class="auth-input-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          <input type="password" name="password_confirm" id="signUpPasswordConfirm" class="auth-text-input" placeholder="Confirm password" required autocomplete="new-password">
          <button type="button" class="auth-eye-btn" onclick="togglePassVisibility('signUpPasswordConfirm', this)" aria-label="Toggle Password Visibility">
            <svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
          </button>
        </div>

        <button type="submit" class="btn-auth-primary" id="btnSignUpSubmit" style="margin-top: 22px;">
          <span>Create Account</span>
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
        </button>
      </form>

      <!-- Social Logins Section -->
      <div class="auth-social-divider">OR CONTINUE WITH</div>
      <div class="auth-social-row">
        <button type="button" class="btn-social-auth" style="flex:1;" onclick="event.preventDefault(); alert('Google sign-in is coming soon!');" aria-label="Sign in with Google">
          <svg width="18" height="18" viewBox="0 0 24 24">
            <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
            <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
            <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z"/>
            <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"/>
          </svg>
          <span>Google</span>
        </button>
        <button type="button" class="btn-social-auth" style="flex:1;" onclick="event.preventDefault(); alert('Facebook sign-in is coming soon!');" aria-label="Sign in with Facebook">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="#1877F2">
            <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
          </svg>
          <span>Facebook</span>
        </button>
      </div>
    </div>

    <!-- View: Forgot Password — self-service OTP not yet implemented; route to support -->
    <div id="authViewForgot" style="display:none;">
      <div style="text-align:center; margin-bottom:24px;">
        <h3 style="font-size:18px; font-weight:800; color:#FFFFFF; margin:0 0 8px 0;">Forgot Password?</h3>
        <p style="font-size:13px; color:rgba(255,255,255,0.65); margin:0 0 20px 0;">Self-service password reset is coming soon. For now, email our support team and we'll get you back in within 24 hours.</p>
        <a href="mailto:support@picklers.ph?subject=Password%20reset%20request" class="btn-auth-primary" style="display:inline-flex; align-items:center; gap:8px; text-decoration:none;">
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L1 7"/></svg>
          <span>Email support@picklers.ph</span>
        </a>
      </div>
      <div style="text-align:center;">
        <a href="javascript:void(0)" onclick="showView('signin')" style="font-size:13px; color:rgba(255,255,255,0.55); text-decoration:none;">← Back to Sign In</a>
      </div>
    </div>

  </div>

  <script>
    // Tab Switcher
    function switchAuthTab(tab) {
      const signInBtn = document.getElementById('tabSignInBtn');
      const signUpBtn = document.getElementById('tabSignUpBtn');
      const signInForm = document.getElementById('signInForm');
      const signUpForm = document.getElementById('signUpForm');
      const authHeading = document.getElementById('authHeading');
      const authSubheading = document.getElementById('authSubheading');

      // Clear alerts & red input error borders on tab switch
      const alerts = document.querySelectorAll('.auth-alert');
      alerts.forEach(el => el.remove());
      const containers = document.querySelectorAll('.auth-input-container');
      containers.forEach(c => {
        c.style.borderColor = '';
        c.style.boxShadow = '';
      });

      if (tab === 'signin') {
        signInBtn.classList.add('active');
        signUpBtn.classList.remove('active');
        signInForm.style.display = 'block';
        signUpForm.style.display = 'none';
        if (authHeading) authHeading.textContent = "Welcome Back!";
        if (authSubheading) authSubheading.textContent = "Sign in to reserve courts and matches";
      } else {
        signUpBtn.classList.add('active');
        signInBtn.classList.remove('active');
        signUpForm.style.display = 'block';
        signInForm.style.display = 'none';
        if (authHeading) authHeading.textContent = "Start Playing Pickleball";
        if (authSubheading) authSubheading.textContent = "Register to join match sessions near you";
      }
    }

    // Toggle Email vs Phone Mode (🇵🇭 +63)
    let isPhoneMode = false;
    function toggleSignInMethod() {
      isPhoneMode = !isPhoneMode;
      const label = document.getElementById('signinMethodLabel');
      const link = document.getElementById('toggleMethodLink');
      const input = document.getElementById('signInIdentifier');
      const icon = document.getElementById('signInIconSvg');

      // Clear existing alerts and error borders
      const alerts = document.querySelectorAll('.auth-alert');
      alerts.forEach(el => el.remove());
      const containers = document.querySelectorAll('.auth-input-container');
      containers.forEach(c => {
        c.style.borderColor = '';
        c.style.boxShadow = '';
      });

      if (isPhoneMode) {
        if (label) label.textContent = "PHONE NUMBER";
        if (link) link.textContent = "Use Email Address instead";
        if (input) {
          input.placeholder = "+63 917 555 4321";
          if (input.value.includes('@') || input.value.includes('.com') || input.value.includes('.ph')) {
            input.value = '';
          }
          input.focus();
        }
        if (icon) {
          icon.innerHTML = '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>';
        }
      } else {
        if (label) label.textContent = "EMAIL ADDRESS";
        if (link) link.textContent = "Use Phone Number instead";
        if (input) {
          input.placeholder = "email@example.com";
          if (input.value.includes('+') || input.value.replace(/\D/g, '').length >= 7) {
            input.value = '';
          }
          input.focus();
        }
        if (icon) {
          icon.innerHTML = '<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L1 7"/>';
        }
      }
    }

    // Password visibility toggle with icon & color update
    function togglePassVisibility(inputId, btn) {
      const input = document.getElementById(inputId);
      if (!input) return;
      const isPass = (input.type === 'password');
      input.type = isPass ? 'text' : 'password';
      if (btn) {
        if (isPass) {
          btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><line x1="2" y1="2" x2="22" y2="22"/></svg>';
          btn.style.color = '#00D98B';
        } else {
          btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>';
          btn.style.color = 'rgba(255, 255, 255, 0.65)';
        }
      }
    }

    // Password Strength Meter
    function checkStrength(val) {
      const bar1 = document.getElementById('strBar1');
      const bar2 = document.getElementById('strBar2');
      const bar3 = document.getElementById('strBar3');
      const label = document.getElementById('strengthText');

      if (!bar1 || !bar2 || !bar3 || !label) return;

      bar1.style.background = 'rgba(255,255,255,0.1)';
      bar2.style.background = 'rgba(255,255,255,0.1)';
      bar3.style.background = 'rgba(255,255,255,0.1)';

      if (!val) {
        label.textContent = "Enter a password";
        label.style.color = "rgba(255,255,255,0.5)";
        return;
      }

      if (val.length < 6) {
        bar1.style.background = '#EF4444';
        label.textContent = "WEAK";
        label.style.color = "#EF4444";
      } else if (val.length >= 6 && val.match(/[0-9]/) && val.match(/[a-zA-Z]/)) {
        bar1.style.background = '#F59E0B';
        bar2.style.background = '#F59E0B';
        label.textContent = "FAIR";
        label.style.color = "#F59E0B";
        if (val.length >= 8 && val.match(/[^a-zA-Z0-9]/)) {
          bar1.style.background = '#00D98B';
          bar2.style.background = '#00D98B';
          bar3.style.background = '#00D98B';
          label.textContent = "STRONG";
          label.style.color = "#00D98B";
        }
      } else {
        bar1.style.background = '#EF4444';
        label.textContent = "WEAK";
        label.style.color = "#EF4444";
      }
    }

    // View Navigation
    function showView(view) {
      document.getElementById('authViewMain').style.display = (view === 'main') ? 'block' : 'none';
      document.getElementById('authViewForgot').style.display = (view === 'forgot-password') ? 'block' : 'none';
      document.getElementById('authViewOTP').style.display = (view === 'verify-otp') ? 'block' : 'none';
    }

    function otpNext(elem, idx) {
      if (elem.value.length === 1) {
        const next = elem.parentElement.children[idx];
        if (next) next.focus();
      }
    }

    function verifyOTPSuccess() {
      window.location.href = 'app.php?verified=1';
    }

    // AJAX Form submission with smooth Success Animation
    function setupAuthFormAJAX(formId, buttonId, defaultLabel) {
      const form = document.getElementById(formId);
      const btn = document.getElementById(buttonId);
      if (!form || !btn) return;

      // Clear alert banners & error highlights when user starts modifying inputs
      form.querySelectorAll('input').forEach(input => {
        input.addEventListener('input', function() {
          const alerts = form.querySelectorAll('.auth-alert');
          alerts.forEach(el => el.remove());
          const containers = form.querySelectorAll('.auth-input-container');
          containers.forEach(c => {
            c.style.borderColor = '';
            c.style.boxShadow = '';
          });
        });
      });

      form.addEventListener('submit', function(e) {
        e.preventDefault();

        // Remove ALL existing alert banners (both error and success alerts)
        const oldAlerts = form.querySelectorAll('.auth-alert');
        oldAlerts.forEach(el => el.remove());

        // Basic Client-side Validation before sending request
        let emptyFieldFound = false;
        form.querySelectorAll('input[required]').forEach(input => {
          if (!input.value.trim()) {
            emptyFieldFound = true;
            const container = input.closest('.auth-input-container');
            if (container) {
              container.style.borderColor = '#EF4444';
              container.style.boxShadow = '0 0 0 3px rgba(239, 68, 68, 0.25)';
            }
          }
        });

        if (emptyFieldFound) {
          let errorEl = form.querySelector('.auth-alert-error');
          if (!errorEl) {
            errorEl = document.createElement('div');
            errorEl.className = 'auth-alert auth-alert-error';
            errorEl.style.marginTop = '16px';
            errorEl.style.marginBottom = '0';
            btn.parentNode.insertBefore(errorEl, btn);
          }
          errorEl.innerHTML = `
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
            <span>Please fill in all required fields.</span>
          `;
          return;
        }

        // 1. Loading State
        btn.disabled = true;
        btn.classList.remove('btn-success-state');
        btn.innerHTML = `<span style="display:flex; align-items:center; justify-content:center; gap:10px; color:#FFFFFF;"><span class="auth-btn-spinner"></span> <span style="color:#FFFFFF; font-weight:800;">Authenticating...</span></span>`;

        const formData = new FormData(form);
        const actionUrl = form.getAttribute('action') || window.location.href;

        fetch(actionUrl, {
          method: 'POST',
          headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
          },
          body: formData
        })
        .then(r => r.json())
        .then(data => {
          if (data.success) {
            // Remove any remaining alert banners
            const alerts = form.querySelectorAll('.auth-alert');
            alerts.forEach(el => el.remove());

            // 1. Success Animation state (Solid Green Background + Crisp White Checkmark & Text)
            btn.classList.add('btn-success-state');
            const successTxt = (formId === 'signUpForm') ? 'Registered!' : 'Signed In';
            btn.innerHTML = `
              <span style="display:flex; align-items:center; justify-content:center; gap:8px; width:100%; color:#FFFFFF;">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round">
                  <polyline points="20 6 9 17 4 12"/>
                </svg>
                <span style="font-size:15px; font-weight:800; letter-spacing:0.2px; color:#FFFFFF;">${successTxt}</span>
              </span>
            `;

            // 2. Show Fullscreen Picklers Logo Loading Overlay
            const overlay = document.getElementById('authSuccessOverlay');
            const subTitle = document.getElementById('authLoadingSub');
            if (subTitle) {
              subTitle.textContent = (formId === 'signUpForm') ? 'Setting up your Picklers account...' : 'Signing you in, please wait...';
            }
            if (overlay) {
              overlay.style.display = 'flex';
              setTimeout(() => {
                overlay.classList.add('active');
              }, 20);
            }

            // 3. Redirect after showing logo loading screen
            setTimeout(() => {
              let targetUrl = data.redirect || 'app.php';
              if (typeof targetUrl === 'string' && targetUrl.includes('PICKLERS WEBDEV PROJECT/PICKLERS WEBDEV PROJECT')) {
                targetUrl = targetUrl.replace('PICKLERS WEBDEV PROJECT/PICKLERS WEBDEV PROJECT', 'PICKLERS WEBDEV PROJECT');
              }
              window.location.href = targetUrl;
            }, 1250);
          } else {
            // Error handling
            btn.disabled = false;
            btn.classList.remove('btn-success-state');
            btn.innerHTML = `<span>${defaultLabel}</span> <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>`;

            // Highlight input containers with red error border
            const containers = form.querySelectorAll('.auth-input-container');
            containers.forEach(c => {
              c.style.borderColor = '#EF4444';
              c.style.boxShadow = '0 0 0 3px rgba(239, 68, 68, 0.25)';
            });

            const errorMsg = data.message || data.error || 'Authentication failed. Please check your credentials.';

            let errorEl = form.querySelector('.auth-alert-error');
            if (!errorEl) {
              errorEl = document.createElement('div');
              errorEl.className = 'auth-alert auth-alert-error';
              errorEl.style.marginTop = '16px';
              errorEl.style.marginBottom = '0';
              btn.parentNode.insertBefore(errorEl, btn);
            }
            errorEl.innerHTML = `
              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
              <span>${errorMsg}</span>
            `;

            // Display Toast Notification at bottom-right
            const toastContainer = document.querySelector('.auth-toast-container');
            if (toastContainer) {
              const toast = document.createElement('div');
              toast.className = 'auth-toast-card auth-toast-error';
              toast.innerHTML = `
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#EF4444" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
                <span>${errorMsg}</span>
              `;
              toastContainer.appendChild(toast);
              setTimeout(() => {
                toast.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
                toast.style.opacity = '0';
                toast.style.transform = 'translateY(16px)';
                setTimeout(() => toast.remove(), 600);
              }, 4000);
            }
          }
        })
        .catch(() => {
          // Fallback to standard native submission if network fails
          form.submit();
        });
      });
    }

    setupAuthFormAJAX('signInForm', 'btnSignInSubmit', 'Sign In');
    setupAuthFormAJAX('signUpForm', 'btnSignUpSubmit', 'Create Account');

    // Ensure buttons reset on page restore / bfcache load
    window.addEventListener('pageshow', function() {
      const overlay = document.getElementById('authSuccessOverlay');
      if (overlay) {
        overlay.classList.remove('active');
        overlay.style.display = 'none';
      }
      ['signInForm', 'signUpForm'].forEach(fId => {
        const f = document.getElementById(fId);
        if (!f) return;
        const b = f.querySelector('button[type="submit"]');
        if (!b) return;
        b.disabled = false;
        b.classList.remove('btn-success-state');
        const defaultText = fId === 'signInForm' ? 'Sign In' : 'Create Account';
        b.innerHTML = `<span>${defaultText}</span> <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>`;
      });
    });
  </script>

  <!-- Floating Toast Notifications (Positioned at Bottom-Right Red Line Location) -->
  <div class="auth-toast-container" role="status" aria-live="polite">
    <?php if (!empty($success)): ?>
      <div class="auth-toast-card auth-toast-success">
        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#00D98B" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        <span><?php echo htmlspecialchars($success); ?></span>
      </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
      <div class="auth-toast-card auth-toast-error">
        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#EF4444" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
        <span><?php echo htmlspecialchars($error); ?></span>
      </div>
    <?php endif; ?>
  </div>

  <script>
    // Auto-dismiss floating toast notifications after 3.5 seconds
    document.addEventListener('DOMContentLoaded', function() {
      const toasts = document.querySelectorAll('.auth-toast-card');
      if (toasts.length > 0) {
        setTimeout(function() {
          toasts.forEach(function(toast) {
            toast.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(16px)';
            setTimeout(function() {
              toast.remove();
            }, 600);
          });
        }, 3500);
      }
    });
  </script>
</body>
</html>
