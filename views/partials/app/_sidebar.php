  <aside class="app-sidebar">
    <div class="sidebar-header">
      <a href="<?= \Picklers\Helpers\Url::to('/app') ?>" class="sidebar-brand">
        <img src="<?= \Picklers\Helpers\Url::asset('images/PICKLERS_OFFICIAL_LOGO.svg') ?>" alt="Picklers Logo" onerror="this.src='<?= \Picklers\Helpers\Url::asset('images/PICKLERS_LOGO.png') ?>'">
        <span class="sidebar-brand-name">PICKLERS</span>
      </a>

      <!-- Notification Bell -->
      <button type="button" class="notif-bell-btn" id="sidebarNotifBell" onclick="openModal('notifModal')" title="Notifications">
        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
        <?php if (!empty($unreadNotifsCount) && $unreadNotifsCount > 0): ?>
          <div class="notif-unread-dot"></div>
        <?php endif; ?>
      </button>
    </div>

    <!-- 5 Player Tabs: Play (Discover Venues), Explore (Open Play), Wallet, Bookings (Manage your Bookings), Settings -->
    <nav class="sidebar-nav">
      <a href="app.php?tab=play" class="nav-item-btn <?php echo $activeTab === 'play' ? 'active' : ''; ?>">
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z"/><path d="M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2"/><path d="M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2"/><path d="M10 6h4"/><path d="M10 10h4"/><path d="M10 14h4"/><path d="M10 18h4"/></svg>
        <span>Play</span>
      </a>

      <a href="app.php?tab=explore" class="nav-item-btn <?php echo $activeTab === 'explore' ? 'active' : ''; ?>">
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg>
        <span>Explore</span>
      </a>

      <a href="app.php?tab=wallet" class="nav-item-btn <?php echo $activeTab === 'wallet' ? 'active' : ''; ?>">
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4Z"/></svg>
        <span>Wallet</span>
      </a>

      <a href="app.php?tab=bookings" class="nav-item-btn <?php echo $activeTab === 'bookings' ? 'active' : ''; ?>">
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/><path d="m9 16 2 2 4-4"/></svg>
        <span>Bookings</span>
      </a>

      <a href="app.php?tab=settings" class="nav-item-btn <?php echo $activeTab === 'settings' ? 'active' : ''; ?>">
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>
        <span>Settings</span>
      </a>
    </nav>

    <!-- Sidebar Footer -->
    <div class="sidebar-footer">
      <?php if (($currentUser['role'] ?? '') === 'admin' || !empty($currentUser['is_admin'])): ?>
        <a href="admin.php" class="sidebar-signout-btn" title="Back to Admin Platform">
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>
          <span>Admin Console</span>
        </a>
      <?php endif; ?>

      <a href="owner.php" class="sidebar-signout-btn" title="Switch to Facility Owner Portal">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="16" height="20" x="4" y="2" rx="2" ry="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01"/><path d="M16 6h.01"/><path d="M12 6h.01"/><path d="M12 10h.01"/><path d="M12 14h.01"/><path d="M16 10h.01"/><path d="M16 14h.01"/><path d="M8 10h.01"/><path d="M8 14h.01"/></svg>
        <span>Switch to Owner View</span>
      </a>

      <a href="javascript:void(0)" onclick="openModal('logoutModal')" class="sidebar-signout-btn" style="margin-top:2px;">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/></svg>
        <span>Sign Out</span>
      </a>

      <!-- User Card -->
      <div class="user-profile-bar">
        <div class="user-avatar-circle-sm" id="sidebarUserAvatar">
          <?php
            $avatarUrl = trim($currentUser['avatar_url'] ?? '');
            $hasCustomAvatar = !empty($avatarUrl) && stripos($avatarUrl, 'unsplash.com') === false;
            $initial = strtoupper(substr(trim($currentUser['name'] ?? 'P'), 0, 1)) ?: 'P';
          ?>
          <?php if ($hasCustomAvatar): ?>
            <img src="<?php echo htmlspecialchars($avatarUrl); ?>" alt="Avatar" style="width:100%; height:100%; border-radius:50%; object-fit:cover;">
          <?php else: ?>
            <span style="font-weight:900; font-size:15px; color:#00D98B; font-family:'Montserrat', sans-serif; text-transform:uppercase;"><?php echo $initial; ?></span>
          <?php endif; ?>
        </div>
        <div class="user-info-text">
          <div class="user-name-line"><?php echo htmlspecialchars($currentUser['name'] ?? 'Demo Player'); ?></div>
          <div class="user-email-line"><?php echo htmlspecialchars($currentUser['email'] ?? 'demoaccount@gmail.com'); ?></div>
        </div>
      </div>
    </div>
  </aside>
