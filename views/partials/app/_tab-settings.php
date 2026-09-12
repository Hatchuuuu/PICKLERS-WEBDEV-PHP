<?php if ($activeTab === 'settings'): ?>
  <div class="settings-wrapper">
    <!-- Page Header -->
    <div style="margin-bottom: 24px;">
      <h1 class="settings-header-title">Settings</h1>
      <p class="settings-header-sub">Manage your account and app preferences</p>
    </div>

    <!-- Responsive Settings Layout (1 column on mobile, 2 columns on laptop/desktop) -->
    <div class="settings-layout">
      
      <!-- ===================================================================
           LEFT COLUMN: Profile Hero, Credits, Verify, Portal Switcher
           =================================================================== -->
      <div class="settings-col-left">
        
        <!-- Hero Profile Section -->
        <div class="settings-hero">
          <div class="settings-avatar-ring-wrap" onclick="triggerAvatarUpload()" title="Tap to change profile picture">
            <div class="settings-avatar-ring-glow"></div>
            <div class="settings-avatar-rotating-ring"></div>
            <div class="settings-avatar-inner">
              <div class="settings-avatar-circle" id="settingsAvatarContainer">
                <?php
                  $avatarUrl = trim($currentUser['avatar_url'] ?? '');
                  $hasCustomAvatar = !empty($avatarUrl) && stripos($avatarUrl, 'unsplash.com') === false;
                  $initial = strtoupper(substr(trim($currentUser['name'] ?? 'P'), 0, 1)) ?: 'P';
                ?>
                <?php if ($hasCustomAvatar): ?>
                  <img id="settingsHeroAvatarImg" src="<?php echo htmlspecialchars($avatarUrl); ?>" alt="<?php echo htmlspecialchars($currentUser['name'] ?? 'User'); ?>">
                <?php else: ?>
                  <span id="settingsHeroAvatarInitial" style="font-size:36px; font-weight:900; color:#00D98B; font-family:'Montserrat', sans-serif; text-transform:uppercase;"><?php echo $initial; ?></span>
                <?php endif; ?>
              </div>
            </div>
            <div class="settings-avatar-camera-btn" title="Upload new photo">
              <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/>
                <circle cx="12" cy="13" r="4"/>
              </svg>
            </div>
          </div>
          <input type="file" id="avatarFileInput" accept="image/png,image/jpeg,image/webp,image/gif" style="display:none;" onchange="handleAvatarUpload(event)">
          <div class="settings-hero-name" id="settingsHeroNameDisplay"><?php echo htmlspecialchars($currentUser['name'] ?? 'Alex Mercer'); ?></div>
          <div class="settings-hero-badge"><?php echo strtoupper(htmlspecialchars($currentUser['role'] ?? 'PLAYER')); ?></div>
        </div>

        <!-- Top Action Stack (Credits, Verify) -->
        <div class="settings-action-stack">
          <!-- Picklers Credits -->
          <div class="settings-action-card">
            <div style="display:flex; flex-direction:column; gap:6px;">
              <div id="settingsWalletBalance" style="font-size:28px; font-weight:800; color:#FFFFFF; font-family:'Inter', -apple-system, BlinkMacSystemFont, sans-serif; letter-spacing:-0.02em; line-height:1.1;">
                ₱<?php echo number_format($currentUser['wallet_balance'] ?? 1250); ?>
              </div>
              <div style="display:inline-flex; align-items:center; gap:7px; font-size:13px; font-weight:700; color:#94A3B8; letter-spacing:0.6px; text-transform:uppercase;">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#00D98B" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4Z"/></svg>
                <span>Available Pickle Credits</span>
              </div>
            </div>
            <button type="button" class="settings-topup-btn" onclick="openModal('topUpModal')" aria-label="Top Up Credits" style="width:36px; height:36px; min-width:36px; min-height:36px; max-width:36px; max-height:36px; aspect-ratio:1/1; flex:0 0 36px; flex-shrink:0; border-radius:50%; background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.1); color:#FFFFFF; font-size:18px; line-height:1; display:inline-flex; align-items:center; justify-content:center; cursor:pointer; padding:0; box-sizing:border-box; transition:all 0.2s;" onmouseover="this.style.background='rgba(0, 217, 139,0.2)'; this.style.borderColor='#00D98B'" onmouseout="this.style.background='rgba(255,255,255,0.06)'; this.style.borderColor='rgba(255,255,255,0.1)'">
              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" x2="12" y1="5" y2="19"/><line x1="5" x2="19" y1="12" y2="12"/></svg>
            </button>
          </div>

          <!-- Verify Identity -->
          <?php $isVerified = ($currentUser['verification_status'] ?? 'unverified') === 'verified'; ?>
          <div class="settings-action-card" onclick="<?php echo $isVerified ? "showToast('Your account is fully verified! ✓', 'success')" : "verifyIdentityNow()"; ?>">
            <div style="display:flex; align-items:center; gap:14px;">
              <div class="settings-card-icon-box" style="background:rgba(0, 217, 139,0.1); border:1px solid rgba(0, 217, 139,0.25); color:#00D98B;">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>
              </div>
              <div>
                <div style="font-size:14px; font-weight:800; color:#FFFFFF; margin-bottom:2px;">Verify Identity</div>
                <div style="font-size:11px; color:#94A3B8;">
                  <?php echo $isVerified ? 'Your account is fully verified ✓' : 'Verify now to unlock all player features'; ?>
                </div>
              </div>
            </div>
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--pk-text-muted, #94A3B8)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
          </div>
        </div>

        <!-- Portal & Console Switcher Stack -->
        <div class="settings-section-label">PORTAL & CONSOLE SWITCHER</div>
        <div class="settings-group-card">
          <!-- Player App -->
          <a href="app.php" class="settings-row-item" style="text-decoration:none;">
            <div class="settings-row-left">
              <div class="settings-icon-pill" style="background:rgba(0, 217, 139,0.15); color:#00D98B;">
                <svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polygon points="10 8 16 12 10 16 10 8"/></svg>
              </div>
              <div class="settings-row-text">
                <div class="settings-row-title" style="color:#FFFFFF;">Player App</div>
                <div class="settings-row-sub">Court booking, open matches & community hub</div>
              </div>
            </div>
            <div class="settings-row-action">
              <span class="settings-portal-tag active">ACTIVE</span>
            </div>
          </a>

          <!-- Court Owner Portal -->
          <a href="owner.php" class="settings-row-item" style="text-decoration:none;">
            <div class="settings-row-left">
              <div class="settings-icon-pill" style="background:rgba(255,184,0,0.15); color:#FFB800;">
                <svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><line x1="3" x2="21" y1="6" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
              </div>
              <div class="settings-row-text">
                <div class="settings-row-title" style="color:#FFFFFF;">Court Owner Portal</div>
                <div class="settings-row-sub">Manage courts, blackout calendar, earnings & tournaments</div>
              </div>
            </div>
            <div class="settings-row-action">
              <span class="settings-portal-launch launch-owner">
                <span>Launch</span>
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
              </span>
            </div>
          </a>

          <!-- Admin Console -->
          <a href="admin.php" class="settings-row-item" style="text-decoration:none;">
            <div class="settings-row-left">
              <div class="settings-icon-pill" style="background:rgba(239,68,68,0.15); color:#EF4444;">
                <svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
              </div>
              <div class="settings-row-text">
                <div class="settings-row-title" style="color:#FFFFFF;">Admin Management Console</div>
                <div class="settings-row-sub">Platform audit, user roles, facility moderation & finance</div>
              </div>
            </div>
            <div class="settings-row-action">
              <span class="settings-portal-launch launch-admin">
                <span>Launch</span>
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
              </span>
            </div>
          </a>
        </div>

      </div>

      <!-- ===================================================================
           RIGHT COLUMN: Account & Identity, Preferences, Legal, Sign Out, Danger
           =================================================================== -->
      <div class="settings-col-right">

        <!-- Section 1: ACCOUNT & IDENTITY -->
        <div class="settings-section-label">Account & Identity</div>
        <div class="settings-group-card">
          <!-- Name -->
          <div class="settings-row-item" onclick="openModal('editAccountModal')">
            <div class="settings-row-left">
              <div class="settings-icon-pill" style="background:rgba(59,130,246,0.15); color:#3B82F6;">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
              </div>
              <div class="settings-row-text">
                <div class="settings-row-title">Name</div>
              </div>
            </div>
            <div class="settings-row-right">
              <span id="settingsRowNameDisplay"><?php echo htmlspecialchars($currentUser['name'] ?? 'Alex Mercer'); ?></span>
              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--pk-text-muted, #94A3B8)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
            </div>
          </div>

          <!-- Phone Number -->
          <div class="settings-row-item" onclick="openModal('editAccountModal')">
            <div class="settings-row-left">
              <div class="settings-icon-pill" style="background:rgba(16,185,129,0.15); color:#10B981;">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
              </div>
              <div class="settings-row-text">
                <div class="settings-row-title">Phone Number</div>
              </div>
            </div>
            <div class="settings-row-right">
              <span id="settingsRowPhoneDisplay"><?php echo !empty($currentUser['phone']) ? htmlspecialchars($currentUser['phone']) : '+63 917 111 2222'; ?></span>
              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--pk-text-muted, #94A3B8)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
            </div>
          </div>

          <!-- Google -->
          <div class="settings-row-item">
            <div class="settings-row-left">
              <div class="settings-icon-pill" style="background:rgba(66,133,244,0.12);">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"><path fill="#EA4335" d="M12 5c1.6 0 3 .6 4.1 1.6l3.1-3.1C17.3 1.7 14.8 1 12 1 7.4 1 3.5 3.6 1.6 7.3l3.7 2.9C6.2 7.1 8.8 5 12 5z"/><path fill="#4285F4" d="M23.5 12.3c0-.8-.1-1.6-.2-2.3H12v4.5h6.5c-.3 1.5-1.1 2.8-2.4 3.7l3.7 2.9c2.2-2 3.7-5 3.7-8.8z"/><path fill="#FBBC05" d="M5.3 14.8c-.2-.7-.4-1.5-.4-2.3s.1-1.6.4-2.3L1.6 7.3C.6 9.3 0 10.6 0 12s.6 2.7 1.6 4.7l3.7-1.9z"/><path fill="#34A853" d="M12 23c3.2 0 6-1.1 8-3l-3.7-2.9c-1.1.7-2.5 1.2-4.3 1.2-3.2 0-5.8-2.1-6.7-5.2L1.6 16C3.5 19.7 7.4 23 12 23z"/></svg>
              </div>
              <div class="settings-row-text">
                <div class="settings-row-title">Google</div>
                <div class="settings-row-sub">Link your Google account</div>
              </div>
            </div>
            <button type="button" class="btn-social-connect" onclick="toggleSocialAccount('Google', this)">Connect</button>
          </div>

          <!-- Facebook -->
          <div class="settings-row-item">
            <div class="settings-row-left">
              <div class="settings-icon-pill" style="background:rgba(24,119,242,0.18); color:#1877F2;">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
              </div>
              <div class="settings-row-text">
                <div class="settings-row-title">Facebook</div>
                <div class="settings-row-sub">Link your Facebook account</div>
              </div>
            </div>
            <button type="button" class="btn-social-connect" onclick="toggleSocialAccount('Facebook', this)">Connect</button>
          </div>

          <!-- Change Password -->
          <div class="settings-row-item" onclick="promptChangePassword()">
            <div class="settings-row-left">
              <div class="settings-icon-pill" style="background:rgba(99,102,241,0.15); color:#6366F1;">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6"/><path d="m15.5 7.5 3 3L22 7l-3-3"/></svg>
              </div>
              <div class="settings-row-text">
                <div class="settings-row-title">Change Password</div>
              </div>
            </div>
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--pk-text-muted, #94A3B8)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
          </div>
        </div>

        <!-- Section 2: PREFERENCES & NOTIFICATIONS -->
        <div class="settings-section-label">Preferences & Notifications</div>
        <div class="settings-group-card">
          <!-- Dark Mode -->
          <div class="settings-row-item">
            <div class="settings-row-left">
              <div class="settings-icon-pill" style="background:rgba(245,158,11,0.15); color:#F59E0B;">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></svg>
              </div>
              <div class="settings-row-text">
                <div class="settings-row-title">Dark Mode</div>
              </div>
            </div>
            <label class="settings-switch">
              <input type="checkbox" id="themeToggleCheckbox" checked onchange="toggleTheme(this.checked)">
              <span class="settings-slider"></span>
            </label>
          </div>

          <!-- Booking Confirmations -->
          <div class="settings-row-item">
            <div class="settings-row-left">
              <div class="settings-icon-pill" style="background:rgba(244,63,94,0.15); color:#F43F5E;">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
              </div>
              <div class="settings-row-text">
                <div class="settings-row-title">Booking Confirmations</div>
              </div>
            </div>
            <label class="settings-switch">
              <input type="checkbox" checked onchange="showToast('Booking notifications updated')">
              <span class="settings-slider"></span>
            </label>
          </div>

          <!-- Open Match Alerts -->
          <div class="settings-row-item">
            <div class="settings-row-left">
              <div class="settings-icon-pill" style="background:rgba(6,182,212,0.15); color:#06B6D4;">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="20" x="5" y="2" rx="2" ry="2"/><path d="M12 18h.01"/></svg>
              </div>
              <div class="settings-row-text">
                <div class="settings-row-title">Open Match Alerts</div>
              </div>
            </div>
            <label class="settings-switch">
              <input type="checkbox" onchange="showToast('Open match alerts preference updated')">
              <span class="settings-slider"></span>
            </label>
          </div>

          <!-- Community Updates -->
          <div class="settings-row-item">
            <div class="settings-row-left">
              <div class="settings-icon-pill" style="background:rgba(139,92,246,0.15); color:#8B5CF6;">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
              </div>
              <div class="settings-row-text">
                <div class="settings-row-title">Community Updates</div>
              </div>
            </div>
            <label class="settings-switch">
              <input type="checkbox" checked onchange="showToast('Community updates updated')">
              <span class="settings-slider"></span>
            </label>
          </div>

          <!-- Chat & Direct Messages -->
          <div class="settings-row-item">
            <div class="settings-row-left">
              <div class="settings-icon-pill" style="background:rgba(16,185,129,0.15); color:#10B981;">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
              </div>
              <div class="settings-row-text">
                <div class="settings-row-title">Chat & Direct Messages</div>
              </div>
            </div>
            <label class="settings-switch">
              <input type="checkbox" checked onchange="showToast('Chat alerts updated')">
              <span class="settings-slider"></span>
            </label>
          </div>
        </div>

        <!-- Section 3: LEGAL & SUPPORT -->
        <div class="settings-section-label">Legal & Support</div>
        <div class="settings-group-card">
          <!-- Help & Support -->
          <div class="settings-row-item" onclick="openModal('supportModal')">
            <div class="settings-row-left">
              <div class="settings-icon-pill" style="background:rgba(139,92,246,0.15); color:#8B5CF6;">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="4"/><line x1="4.93" x2="9.17" y1="4.93" y2="9.17"/><line x1="14.83" x2="19.07" y1="14.83" y2="19.07"/><line x1="14.83" x2="19.07" y1="9.17" y2="4.93"/><line x1="4.93" x2="9.17" y1="19.07" y2="14.83"/></svg>
              </div>
              <div class="settings-row-text">
                <div class="settings-row-title">Help & Support</div>
                <div class="settings-row-sub">Contact customer service & report bugs</div>
              </div>
            </div>
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--pk-text-muted, #94A3B8)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
          </div>

          <!-- Privacy Policy -->
          <div class="settings-row-item" onclick="openModal('privacyModal')">
            <div class="settings-row-left">
              <div class="settings-icon-pill" style="background:rgba(16,185,129,0.15); color:#10B981;">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
              </div>
              <div class="settings-row-text">
                <div class="settings-row-title">Privacy Policy</div>
                <div class="settings-row-sub">App Store & Play Store privacy rules</div>
              </div>
            </div>
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--pk-text-muted, #94A3B8)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
          </div>

          <!-- Terms of Service (EULA) -->
          <div class="settings-row-item" onclick="openModal('termsModal')">
            <div class="settings-row-left">
              <div class="settings-icon-pill" style="background:rgba(59,130,246,0.15); color:#3B82F6;">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/><line x1="16" x2="8" y1="13" y2="13"/><line x1="16" x2="8" y1="17" y2="17"/><line x1="10" x2="8" y1="9" y2="9"/></svg>
              </div>
              <div class="settings-row-text">
                <div class="settings-row-title">Terms of Service (EULA)</div>
                <div class="settings-row-sub">User agreement & court booking policies</div>
              </div>
            </div>
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--pk-text-muted, #94A3B8)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
          </div>
        </div>

        <!-- Sign Out Button -->
        <button type="button" class="btn-settings-signout" onclick="openModal('logoutModal')">
          <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/></svg>
          <span>SIGN OUT</span>
        </button>

        <!-- Danger Zone -->
        <div style="color:#EF4444; font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:0.8px; margin-bottom:10px; padding-left:4px;">Danger Zone</div>
        <div class="settings-danger-card">
          <div style="display:flex; align-items:flex-start; gap:14px;">
            <div style="width:34px; height:34px; border-radius:10px; background:rgba(239,68,68,0.15); color:#EF4444; display:flex; align-items:center; justify-content:center; flex-shrink:0; margin-top:2px;">
              <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/></svg>
            </div>
            <div style="flex:1;">
              <div style="font-size:14px; font-weight:800; color:#FFFFFF; margin-bottom:3px;">Delete Account</div>
              <div style="font-size:11px; color:#94A3B8; line-height:1.4;">Permanently remove your account and all data. This action cannot be undone.</div>
            </div>
          </div>
          <button type="button" class="btn-delete-account" onclick="confirmDeleteAccount()">
            Delete My Account
          </button>
        </div>

        <!-- App Version & Copyright Footer -->
        <div style="text-align:center; padding-top:10px; padding-bottom:30px;">
          <div style="display:inline-flex; align-items:center; gap:6px; margin-bottom:4px;">
            <span style="font-family:'Montserrat', var(--font-heading), sans-serif; font-size:13px; font-weight:900; color:#94A3B8; letter-spacing:1px; text-transform:uppercase;">PICKLERS</span>
            <span style="font-size:10px; font-weight:700; color:#00D98B; background:rgba(0,217,139,0.1); padding:2px 7px; border-radius:4px;">V1.0.0</span>
          </div>
          <div style="font-size:11px; color:#475569;">© 2026 PICKLERS Inc. All Rights Reserved.</div>
        </div>

      </div>

    </div>

  </div>
<?php endif; ?>
